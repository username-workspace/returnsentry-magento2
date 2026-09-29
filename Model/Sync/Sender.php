<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Sync;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\CustomerRegistry;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Shipment;
use ReturnSentry\Connector\Model\Http\ApiException;
use ReturnSentry\Connector\Model\Http\Client;
use ReturnSentry\Connector\Model\Mapper\Fields;
use ReturnSentry\Connector\Model\Mapper\Identity;
use ReturnSentry\Connector\Model\Mapper\OrderMapper;
use ReturnSentry\Connector\Model\Mapper\RefundMapper;
use ReturnSentry\Connector\Model\Mapper\ShipmentMapper;
use ReturnSentry\Connector\Model\OrderLoader;
use ReturnSentry\Connector\Model\Refund\AuthorizationStore;
use ReturnSentry\Connector\Model\Returns\InspectionStore;
use ReturnSentry\Connector\Model\Returns\ReturnService;
use ReturnSentry\Connector\Model\Returns\ReturnStore;

class Sender
{
    public const CUSTOMER = 'customer';
    public const ORDER = 'order';
    public const SHIPMENT = 'shipment';
    public const CREDITMEMO = 'creditmemo';
    public const RMA = 'rma';
    public const RETURN = 'return';
    public const ERASURE = 'erasure';
    public const OVERRIDE = 'override';
    public const INSPECTION = 'inspection';

    private const PATHS = [
        self::CUSTOMER => '/v1/customers',
        self::ORDER => '/v1/orders',
        self::CREDITMEMO => '/v1/refunds',
        self::RMA => '/v1/returns',
        self::RETURN => '/v1/returns',
    ];

    public function __construct(
        private readonly Client $client,
        private readonly CustomerRepositoryInterface $customers,
        private readonly CustomerRegistry $customerRegistry,
        private readonly OrderLoader $orders,
        private readonly CreditmemoRepositoryInterface $creditmemos,
        private readonly Identity $identity,
        private readonly OrderMapper $orderMapper,
        private readonly RefundMapper $refundMapper,
        private readonly ReturnService $returns,
        private readonly ReturnStore $returnStore,
        private readonly ShipmentRepositoryInterface $shipments,
        private readonly ShipmentMapper $shipmentMapper,
        private readonly AuthorizationStore $authorizations,
        private readonly InspectionStore $inspections
    ) {
    }

    /**
     * @throws ApiException
     * @throws LocalizedException
     */
    public function send(string $type, string $id, int $storeId): void
    {
        if ($type === self::ERASURE) {
            $this->client->delete('/v1/customers', $this->erasureQuery($id), $storeId);
            return;
        }
        if ($type === self::OVERRIDE) {
            $this->sendOverride($id, $storeId);
            return;
        }
        if ($type === self::INSPECTION) {
            $this->sendInspection($id, $storeId);
            return;
        }
        if ($type === self::SHIPMENT) {
            foreach ($this->shipmentRecords((int) $id) as $record) {
                $this->client->post('/v1/shipments', $record, $storeId);
            }
            return;
        }
        $record = match ($type) {
            self::CUSTOMER => $this->customerRecord((int) $id, $storeId),
            self::ORDER => $this->orderMapper->toRecord($this->orders->get((int) $id)),
            self::CREDITMEMO => $this->refundRecord((int) $id),
            self::RMA, self::RETURN => $this->returnRecord($type, $id),
            default => throw new NoSuchEntityException(__('Unknown ReturnSentry sync type %1', $type)),
        };
        if ($record === null) {
            return;
        }
        $this->client->post(self::PATHS[$type], $record, $storeId);
        if ($type === self::RMA || $type === self::RETURN) {
            $this->returns->assessOnce((string) $record['externalId'], $storeId);
        }
    }

    /**
     * @return array<string, string>
     */
    private function erasureQuery(string $id): array
    {
        if (ctype_digit($id)) {
            return ['externalId' => $this->identity->customerExternalId((int) $id)];
        }
        return ['email' => substr($id, (int) strpos($id, ':') + 1)];
    }

    /**
     * @return array<string, mixed>
     */
    private function customerRecord(int $customerId, int $storeId): array
    {
        $this->customerRegistry->remove($customerId);
        $customer = $this->customers->getById($customerId);
        return $this->identity->customer($customerId, $customer->getEmail(), null, $storeId)
            + Fields::compact(['firstSeenAt' => Fields::iso($customer->getCreatedAt())]);
    }

    /**
     * @throws ApiException
     * @throws NoSuchEntityException
     */
    private function sendOverride(string $externalId, int $storeId): void
    {
        $override = $this->authorizations->override($externalId);
        if ($override === null) {
            throw NoSuchEntityException::singleField('external_id', $externalId);
        }
        $path = sprintf('/v1/refund-authorizations/%s/decision', rawurlencode($externalId));
        try {
            $this->client->post($path, ['decision' => 'approve'] + $override, $storeId);
        } catch (ApiException $exception) {
            if (!$exception->transient) {
                $this->authorizations->markOverrideRejected($externalId, $exception->status);
            }
            throw $exception;
        }
        $this->authorizations->markOverrideRejected($externalId, 0);
    }

    /**
     * @throws ApiException
     * @throws NoSuchEntityException
     */
    private function sendInspection(string $externalId, int $storeId): void
    {
        $inspection = $this->inspections->find($externalId);
        if ($inspection === null) {
            return;
        }
        if ($this->orderMapper->hasErasedCustomer($this->orders->get($inspection['orderId']))) {
            $this->inspections->delete($externalId);
            return;
        }
        try {
            $this->client->post('/v1/inspections', $inspection['record'], $storeId);
        } catch (ApiException $exception) {
            if ($exception->status === 404) {
                throw new ApiException($exception->getMessage(), true, 404, $exception);
            }
            if (!$exception->retryable()) {
                $this->inspections->delete($externalId);
            }
            throw $exception;
        }
        $this->inspections->delete($externalId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function shipmentRecords(int $shipmentId): array
    {
        $shipment = $this->shipments->get($shipmentId);
        if (!$shipment instanceof Shipment) {
            throw NoSuchEntityException::singleField('entity_id', $shipmentId);
        }
        return $this->shipmentMapper->toRecords($shipment, $this->orders->get((int) $shipment->getOrderId()));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function refundRecord(int $creditmemoId): ?array
    {
        $creditmemo = $this->creditmemos->get($creditmemoId);
        if (!$creditmemo instanceof Creditmemo) {
            throw NoSuchEntityException::singleField('entity_id', $creditmemoId);
        }
        $order = $this->orders->get((int) $creditmemo->getOrderId());
        if ($this->orderMapper->hasErasedCustomer($order)) {
            return null;
        }
        $skus = $this->refundMapper->refundedSkus($creditmemo);
        $return = $this->returnStore->refundedBy((int) $order->getEntityId(), $skus);
        return $this->refundMapper->toRecord($creditmemo, $order, $return);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function returnRecord(string $type, string $id): ?array
    {
        $externalId = $type === self::RMA ? $this->returns->recordRma((int) $id) : $id;
        return $externalId === null ? null : ($this->returnStore->find($externalId)['record'] ?? null);
    }
}
