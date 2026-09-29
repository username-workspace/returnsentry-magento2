<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model;

use Magento\Customer\Model\Customer;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use ReturnSentry\Connector\Model\Http\ApiException;
use ReturnSentry\Connector\Model\Http\Client;
use ReturnSentry\Connector\Model\Mapper\Fields;
use ReturnSentry\Connector\Model\Mapper\Identity;
use ReturnSentry\Connector\Model\Mapper\OrderMapper;
use ReturnSentry\Connector\Model\Mapper\RefundMapper;
use ReturnSentry\Connector\Model\Mapper\ReturnMapper;
use ReturnSentry\Connector\Model\Mapper\ShipmentMapper;
use ReturnSentry\Connector\Model\Returns\ReturnStore;
use ReturnSentry\Connector\Model\Returns\RmaReader;

/**
 * @phpstan-type Stats array{orders: int, records: int, batches: int, created: int, updated: int, errors: list<string>}
 */
class Backfill
{
    private const PAGE_SIZE = 200;
    private const BATCH_SIZE = 500;
    private const BATCH_TIMEOUT_MS = 120000;

    /**
     * @var list<array{type: string, data: array<string, mixed>}>
     */
    private array $buffer = [];

    /**
     * @var Stats
     */
    private array $stats;

    public function __construct(
        private readonly OrderCollectionFactory $orderCollections,
        private readonly CustomerCollectionFactory $customerCollections,
        private readonly Client $client,
        private readonly Identity $identity,
        private readonly OrderMapper $orderMapper,
        private readonly RefundMapper $refundMapper,
        private readonly ReturnMapper $returnMapper,
        private readonly RmaReader $rmaReader,
        private readonly ReturnStore $returnStore,
        private readonly ShipmentMapper $shipmentMapper
    ) {
        $this->stats = $this->emptyStats();
    }

    /**
     * @param callable(Stats): void $progress
     * @return Stats
     * @throws ApiException
     */
    public function run(int $storeId, string $from, string $to, bool $dryRun, callable $progress): array
    {
        $this->buffer = [];
        $this->stats = $this->emptyStats();
        $sentCustomers = [];
        $afterId = 0;
        do {
            $orders = $this->orderCollections->create()
                ->addFieldToFilter('store_id', ['eq' => $storeId])
                ->addFieldToFilter('created_at', ['gteq' => $from])
                ->addFieldToFilter('created_at', ['lt' => $to])
                ->addFieldToFilter('entity_id', ['gt' => $afterId])
                ->setOrder('entity_id', 'ASC')
                ->setPageSize(self::PAGE_SIZE)
                ->setCurPage(1);
            $page = array_values(array_filter($orders->getItems(), static fn ($order) => $order instanceof Order));
            $orderIds = array_map(static fn (Order $order) => (int) $order->getEntityId(), $page);
            $customerIds = array_diff(
                array_filter(array_map(static fn (Order $order) => (int) $order->getCustomerId(), $page)),
                $sentCustomers
            );
            $this->addCustomers(array_values(array_unique($customerIds)), $storeId);
            $sentCustomers = array_merge($sentCustomers, $customerIds);
            $rmas = $this->rmaReader->idsByOrder($orderIds);
            foreach ($page as $order) {
                $this->addOrder($order, $rmas[(int) $order->getEntityId()] ?? [], $dryRun);
                $afterId = (int) $order->getEntityId();
            }
            $this->stats['orders'] += count($page);
            if (count($this->buffer) >= self::BATCH_SIZE) {
                $this->flush($storeId, $dryRun);
                $progress($this->stats);
            }
        } while (count($page) === self::PAGE_SIZE);
        $this->flush($storeId, $dryRun);
        $progress($this->stats);
        return $this->stats;
    }

    /**
     * @param int[] $customerIds
     */
    private function addCustomers(array $customerIds, int $storeId): void
    {
        if ($customerIds === []) {
            return;
        }
        $customers = $this->customerCollections->create()->addFieldToFilter('entity_id', ['in' => $customerIds]);
        foreach ($customers->getItems() as $customer) {
            if (!$customer instanceof Customer) {
                continue;
            }
            $record = $this->identity->customer(
                (int) $customer->getId(),
                (string) $customer->getEmail(),
                null,
                $storeId
            ) + Fields::compact(['firstSeenAt' => Fields::iso((string) $customer->getCreatedAt())]);
            $this->buffer[] = ['type' => 'customer', 'data' => $record];
        }
    }

    /**
     * @param int[] $rmaIds
     */
    private function addOrder(Order $order, array $rmaIds, bool $dryRun): void
    {
        $record = $this->orderMapper->toRecord($order);
        if ($record === null) {
            return;
        }
        $this->buffer[] = ['type' => 'order', 'data' => $record];
        $shipments = $order->getShipmentsCollection();
        foreach ($shipments ? $shipments->getItems() : [] as $shipment) {
            $parcels = $shipment instanceof Shipment ? $this->shipmentMapper->toRecords($shipment, $order) : [];
            foreach ($parcels as $parcel) {
                $this->buffer[] = ['type' => 'shipment', 'data' => $parcel];
            }
        }
        $returns = [];
        foreach ($this->returnStore->forOrder((int) $order->getEntityId()) as $stored) {
            $returns[(string) $stored['record']['externalId']] = $stored['record'];
        }
        foreach ($rmaIds as $rmaId) {
            $rma = $this->rmaReader->read($rmaId);
            if ($rma === null) {
                continue;
            }
            $return = $this->returnMapper->fromRma($rma['rma'], $rma['items'], $rma['tracks'], $order);
            if (!$dryRun) {
                $this->returnStore->save((int) $order->getEntityId(), $return);
            }
            $returns[(string) $return['externalId']] = $return;
        }
        foreach ($returns as $return) {
            $this->buffer[] = ['type' => 'return', 'data' => $return];
        }
        $creditmemos = $order->getCreditmemosCollection();
        foreach ($creditmemos ? $creditmemos->getItems() : [] as $creditmemo) {
            if ($creditmemo instanceof Creditmemo) {
                $skus = $this->refundMapper->refundedSkus($creditmemo);
                $return = $this->returnStore->matching(array_values($returns), $skus);
                $refund = $this->refundMapper->toRecord($creditmemo, $order, $return);
                $this->buffer[] = ['type' => 'refund', 'data' => $refund];
            }
        }
    }

    /**
     * @throws ApiException
     */
    private function flush(int $storeId, bool $dryRun): void
    {
        $this->stats['records'] += count($this->buffer);
        foreach ($dryRun ? [] : array_chunk($this->buffer, self::BATCH_SIZE) as $batch) {
            $this->stats['batches']++;
            $response = $this->client->post('/v1/batch', ['records' => $batch], $storeId, self::BATCH_TIMEOUT_MS);
            $this->stats['created'] += (int) ($response['created'] ?? 0);
            $this->stats['updated'] += (int) ($response['updated'] ?? 0);
            foreach (is_array($response['errors'] ?? null) ? $response['errors'] : [] as $error) {
                $index = (int) ($error['index'] ?? -1);
                $record = $batch[$index] ?? null;
                $this->stats['errors'][] = sprintf(
                    'batch %d, record %d, %s %s: %s',
                    $this->stats['batches'],
                    $index,
                    $record['type'] ?? '?',
                    $record['data']['externalId'] ?? $record['data']['trackingNumber'] ?? '?',
                    (string) ($error['message'] ?? '')
                );
            }
        }
        $this->buffer = [];
    }

    /**
     * @return Stats
     */
    private function emptyStats(): array
    {
        return ['orders' => 0, 'records' => 0, 'batches' => 0, 'created' => 0, 'updated' => 0, 'errors' => []];
    }
}
