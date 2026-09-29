<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Cron;

use Psr\Log\LoggerInterface;
use ReturnSentry\Connector\Model\Sync\Consumer;
use ReturnSentry\Connector\Model\Sync\RetryStore;

class RetrySync
{
    private const BATCH = 200;

    public function __construct(
        private readonly RetryStore $retries,
        private readonly Consumer $consumer,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        foreach ($this->retries->due(self::BATCH) as $retry) {
            try {
                $this->consumer->run(
                    $retry['entity_type'],
                    $retry['entity_id'],
                    $retry['store_id'],
                    $retry['attempts']
                );
            } catch (\Exception $exception) {
                $this->logger->error('ReturnSentry sync retry failed', [
                    'type' => $retry['entity_type'],
                    'id' => $retry['entity_id'],
                    'error' => $exception->getMessage(),
                ]);
                $this->retries->clear($retry['entity_type'], $retry['entity_id']);
            }
        }
    }
}
