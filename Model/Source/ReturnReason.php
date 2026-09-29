<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ReturnReason implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'size_fit', 'label' => __('Size or fit')],
            ['value' => 'changed_mind', 'label' => __('Changed mind, does not suit')],
            ['value' => 'quality', 'label' => __('Disappointing quality')],
            ['value' => 'damaged_defective', 'label' => __('Damaged or defective')],
            ['value' => 'not_as_described', 'label' => __('Not as described')],
            ['value' => 'wrong_item', 'label' => __('Wrong item received')],
            ['value' => 'late_delivery', 'label' => __('Late delivery')],
            ['value' => 'multiple_sizes', 'label' => __('Several sizes ordered')],
            ['value' => 'other', 'label' => __('Other')],
            ['value' => 'unknown', 'label' => __('No reason')],
        ];
    }
}
