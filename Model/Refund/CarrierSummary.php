<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Refund;

use Magento\Framework\Phrase;

class CarrierSummary
{
    /**
     * @param array<string, mixed> $carrier
     */
    public function describe(array $carrier): Phrase
    {
        $state = (string) ($carrier['state'] ?? 'unknown');
        if ($state !== 'delivered') {
            return __('Carrier: %1.', $this->stateLabel($state));
        }
        return __(
            'Carrier: delivered on %1, %2, %3.',
            substr((string) ($carrier['deliveredAt'] ?? ''), 0, 10),
            $this->handoverLabel((string) ($carrier['handover'] ?? 'unknown')),
            $this->proofLabel((string) ($carrier['proofStrength'] ?? 'none'))
        );
    }

    private function stateLabel(string $state): Phrase
    {
        return match ($state) {
            'not_delivered' => __('not delivered'),
            'in_transit' => __('in transit'),
            'awaiting_pickup' => __('waiting at the pickup point'),
            default => __('no carrier data'),
        };
    }

    private function handoverLabel(string $handover): Phrase
    {
        return match ($handover) {
            'in_person' => __('handed to the recipient'),
            'third_party' => __('handed to a third party'),
            'safe_place' => __('left in a safe place'),
            'mailbox' => __('left in the mailbox'),
            'relay_point' => __('collected at the pickup point'),
            'locker' => __('collected from a locker'),
            'post_office' => __('collected at the post office'),
            default => __('handover mode unknown'),
        };
    }

    private function proofLabel(string $proofStrength): Phrase
    {
        return match ($proofStrength) {
            'strong' => __('strong proof'),
            'corroborating' => __('corroborating proof'),
            'weak' => __('weak proof'),
            default => __('no proof of delivery'),
        };
    }
}
