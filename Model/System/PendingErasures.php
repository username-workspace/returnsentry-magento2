<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\System;

use Magento\Framework\Notification\MessageInterface;
use ReturnSentry\Connector\Model\Sync\RetryStore;
use ReturnSentry\Connector\Model\Sync\Sender;

class PendingErasures implements MessageInterface
{
    /**
     * @var array{count: int, lastError: ?string}|null
     */
    private ?array $pending = null;

    public function __construct(private readonly RetryStore $retries)
    {
    }

    public function getIdentity(): string
    {
        return 'returnsentry_pending_erasures';
    }

    public function isDisplayed(): bool
    {
        return $this->pending()['count'] > 0;
    }

    public function getText(): string
    {
        $pending = $this->pending();
        return (string) __(
            'ReturnSentry: %1 customer erasures are still waiting to be sent, retried automatically. Last error: %2',
            $pending['count'],
            $pending['lastError'] ?? '-'
        );
    }

    public function getSeverity(): int
    {
        return self::SEVERITY_MAJOR;
    }

    /**
     * @return array{count: int, lastError: ?string}
     */
    private function pending(): array
    {
        return $this->pending ??= $this->retries->pending(Sender::ERASURE);
    }
}
