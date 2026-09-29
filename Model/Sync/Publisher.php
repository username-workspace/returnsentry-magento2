<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Sync;

use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use ReturnSentry\Connector\Model\Config;

class Publisher
{
    public const TOPIC = 'returnsentry.sync';
    private const DURABLE = [Sender::ERASURE, Sender::OVERRIDE, Sender::INSPECTION];

    public function __construct(
        private readonly PublisherInterface $publisher,
        private readonly Config $config,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
        private readonly RetryStore $retries
    ) {
    }

    public function publish(string $type, int|string $id, int $storeId): void
    {
        if (in_array($type, self::DURABLE, true)) {
            if ($this->config->apiUrl($storeId) !== '' && $this->config->secretKey($storeId) !== '') {
                $this->retries->enqueue($type, (string) $id, $storeId);
            }
            return;
        }
        if (!$this->config->isConnected($storeId)) {
            return;
        }
        try {
            $this->publisher->publish(
                self::TOPIC,
                $this->json->serialize(['type' => $type, 'id' => (string) $id, 'store' => $storeId])
            );
        } catch (\Exception $exception) {
            $this->logger->error('ReturnSentry could not queue a sync message', [
                'type' => $type,
                'id' => $id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
