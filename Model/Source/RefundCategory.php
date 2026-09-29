<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class RefundCategory implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'return', 'label' => __('Refund after a return')],
            ['value' => 'return_to_sender', 'label' => __('Parcel returned to sender')],
            ['value' => 'item_not_received', 'label' => __('Claim: parcel not received')],
            ['value' => 'empty_package_claim', 'label' => __('Claim: empty parcel received')],
            ['value' => 'missing_items_claim', 'label' => __('Claim: items missing')],
            ['value' => 'return_not_received_dispute', 'label' => __('Claim: return sent but not received')],
            ['value' => 'return_empty', 'label' => __('Evidence: return received empty')],
            ['value' => 'return_non_conforming', 'label' => __('Evidence: return received non-conforming')],
            ['value' => 'defective_product', 'label' => __('Defective product')],
            ['value' => 'merchant_error', 'label' => __('Merchant error')],
            ['value' => 'commercial_gesture', 'label' => __('Commercial gesture')],
            ['value' => 'other', 'label' => __('Other')],
        ];
    }
}
