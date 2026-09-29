<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Exception;

use Magento\Framework\Phrase;
use Magento\Sales\Exception\CouldNotRefundException;

/**
 * @api
 */
class RefundStoppedException extends CouldNotRefundException
{
    /**
     * @param string[] $reasons
     */
    public function __construct(
        Phrase $phrase,
        private readonly string $decision,
        private readonly array $reasons = [],
        private readonly ?string $holdUntil = null,
        private readonly ?string $evidenceUrl = null
    ) {
        parent::__construct($phrase);
    }

    public function getDecision(): string
    {
        return $this->decision;
    }

    /**
     * @return string[]
     */
    public function getReasons(): array
    {
        return $this->reasons;
    }

    public function getHoldUntil(): ?string
    {
        return $this->holdUntil;
    }

    public function getEvidenceUrl(): ?string
    {
        return $this->evidenceUrl;
    }
}
