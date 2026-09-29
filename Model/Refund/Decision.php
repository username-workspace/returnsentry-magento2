<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Refund;

class Decision
{
    /**
     * @param string[] $reasons
     * @param array<string, mixed>|null $carrier
     */
    public function __construct(
        public readonly string $decision,
        public readonly float $requestedAmount,
        public readonly float $approvedAmount,
        public readonly array $reasons = [],
        public readonly ?string $evidenceUrl = null,
        public readonly ?string $holdUntil = null,
        public readonly bool $degraded = false,
        public readonly bool $fraudEvidence = false,
        public readonly ?array $carrier = null,
        public readonly ?string $id = null
    ) {
    }

    /**
     * @param array<string, mixed> $response
     */
    public static function fromResponse(array $response): self
    {
        $reasons = [];
        foreach (is_array($response['reasons'] ?? null) ? $response['reasons'] : [] as $reason) {
            if (is_array($reason) && is_string($reason['label'] ?? null)) {
                $reasons[] = $reason['label'];
            }
        }
        return new self(
            is_string($response['decision'] ?? null) ? $response['decision'] : 'hold',
            (float) ($response['requestedAmount'] ?? 0),
            (float) ($response['approvedAmount'] ?? 0),
            $reasons,
            is_string($response['evidenceUrl'] ?? null) ? $response['evidenceUrl'] : null,
            is_string($response['holdUntil'] ?? null) ? $response['holdUntil'] : null,
            (bool) ($response['degraded'] ?? false),
            ($response['fraudEvidence'] ?? false) === true,
            is_array($response['carrier'] ?? null) ? $response['carrier'] : null,
            is_string($response['id'] ?? null) ? $response['id'] : null
        );
    }

    public function allows(float $amount): bool
    {
        return $this->decision === 'approve'
            || ($this->decision === 'approve_partial' && round($amount, 2) <= round($this->approvedAmount, 2));
    }
}
