<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Sync;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use ReturnSentry\Connector\Model\Http\ApiException;

class Consumer
{
    public const MAX_ATTEMPTS = 20;

    public function __construct(
        private readonly Sender $sender,
        private readonly RetryStore $retries,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    public function process(string $message): void
    {
        $data = $this->json->unserialize($message);
        if (!is_array($data) || !isset($data['type'], $data['id'], $data['store'])) {
            $this->logger->error('ReturnSentry received a malformed sync message', ['message' => $message]);
            return;
        }
        $this->run((string) $data['type'], (string) $data['id'], (int) $data['store'], 0);
    }

    public function run(string $type, string $id, int $storeId, int $attempts): void
    {
        try {
            $this->sender->send($type, $id, $storeId);
            $this->retries->clear($type, $id);
        } catch (NoSuchEntityException) {
            $this->retries->clear($type, $id);
        } catch (ApiException $exception) {
            $bounded = $type !== Sender::OVERRIDE && $attempts >= self::MAX_ATTEMPTS;
            if ($type === Sender::ERASURE || ($exception->retryable() && !$bounded)) {
                $this->retries->schedule($type, $id, $storeId, $attempts + 1, $exception->getMessage());
                return;
            }
            $this->logger->error('ReturnSentry sync failed', [
                'type' => $type,
                'id' => $id,
                'attempts' => $attempts + 1,
                'error' => $exception->getMessage(),
            ]);
            $this->retries->clear($type, $id);
        }
    }
}
