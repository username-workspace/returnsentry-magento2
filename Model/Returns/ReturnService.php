<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Returns;

use Magento\Sales\Model\Order;
use ReturnSentry\Connector\Model\OrderLoader;
use Psr\Log\LoggerInterface;
use ReturnSentry\Connector\Api\Data\Assessment;
use ReturnSentry\Connector\Api\Data\ReturnRequest;
use ReturnSentry\Connector\Model\Config;
use ReturnSentry\Connector\Model\Http\ApiException;
use ReturnSentry\Connector\Model\Http\Client;
use ReturnSentry\Connector\Model\Mapper\Fields;
use ReturnSentry\Connector\Model\Mapper\OrderMapper;
use ReturnSentry\Connector\Model\Mapper\ReturnMapper;

class ReturnService
{
    public function __construct(
        private readonly Config $config,
        private readonly Client $client,
        private readonly ReturnStore $store,
        private readonly ReturnMapper $mapper,
        private readonly OrderMapper $orderMapper,
        private readonly RmaReader $rmaReader,
        private readonly OrderLoader $orders,
        private readonly LoggerInterface $logger
    ) {
    }

    public function recordRma(int $rmaId): ?string
    {
        $rma = $this->rmaReader->read($rmaId);
        if ($rma === null) {
            return null;
        }
        $order = $this->orders->get((int) $rma['rma']['order_id']);
        if ($this->orderMapper->hasErasedCustomer($order)) {
            return null;
        }
        return $this->save($order, $this->mapper->fromRma($rma['rma'], $rma['items'], $rma['tracks'], $order));
    }

    public function record(ReturnRequest $request, Order $order): string
    {
        return $this->save($order, $this->mapper->fromRequest($request, $order));
    }

    public function storedAssessment(string $externalId): Assessment
    {
        return $this->store->find($externalId)['assessment'] ?? Assessment::notAssessed();
    }

    public function assessOnce(string $externalId, int $storeId): Assessment
    {
        $stored = $this->store->find($externalId);
        if ($stored === null || !$this->config->isEnabled(Config::RETURN_ASSESSMENT, $storeId)) {
            return Assessment::notAssessed();
        }
        if ($stored['assessment'] !== null) {
            return $stored['assessment'];
        }
        $record = $stored['record'];
        $body = Fields::compact([
            'context' => 'return',
            'customer' => $record['customer'] ?? null,
            'return' => [
                'externalId' => $externalId,
                'orderExternalId' => $record['orderExternalId'] ?? null,
                'reason' => $record['reason'] ?? null,
                'amount' => $record['amount'] ?? null,
                'items' => $record['items'] ?? null,
                'goodsBackAt' => $record['receivedAt'] ?? null,
            ],
        ]);
        try {
            $assessment = Assessment::fromArray($this->client->post('/v1/assessments', $body, $storeId));
        } catch (ApiException $exception) {
            $this->logger->warning('ReturnSentry return assessment failed open', [
                'return' => $externalId,
                'error' => $exception->getMessage(),
            ]);
            return Assessment::failedOpen();
        }
        if (!$assessment->failOpen) {
            $this->store->saveAssessment($externalId, $assessment);
        }
        return $assessment;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function save(Order $order, array $record): string
    {
        $this->store->save((int) $order->getEntityId(), $record);
        return (string) $record['externalId'];
    }
}
