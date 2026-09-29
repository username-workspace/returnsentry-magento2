<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class BeaconLoading implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'consent', 'label' => __('After consent, and on sign-in and account creation pages (EU)')],
            ['value' => 'always', 'label' => __('On every page (United Kingdom, fraud prevention exemption)')],
        ];
    }
}
