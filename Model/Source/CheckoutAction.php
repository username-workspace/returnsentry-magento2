<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class CheckoutAction implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'allow', 'label' => __('Allow')],
            ['value' => 'review', 'label' => __('Review')],
            ['value' => 'restrict_payment', 'label' => __('Restricted payment')],
            ['value' => 'block', 'label' => __('Blocked')],
        ];
    }
}
