<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Api\Data;

/**
 * Webapi output: Magento reads each public method's type from its @return annotation.
 */
class ReturnTerms
{
    public function __construct(
        private readonly bool $paid = false,
        private readonly ?float $labelCost = null
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $labelCost = $data['labelCost'] ?? null;
        return new self(($data['paid'] ?? false) === true, is_numeric($labelCost) ? (float) $labelCost : null);
    }

    /**
     * @return bool
     */
    public function isPaid(): bool
    {
        return $this->paid;
    }

    /**
     * @return float|null
     */
    public function getLabelCost(): ?float
    {
        return $this->labelCost;
    }
}
