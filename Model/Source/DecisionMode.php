<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use ReturnSentry\Connector\Model\Config;

class DecisionMode implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => Config::OFF, 'label' => __('Off')],
            ['value' => Config::OBSERVE, 'label' => __('Observe')],
            ['value' => Config::ENFORCE, 'label' => __('Enforce')],
        ];
    }
}
