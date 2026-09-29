<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Phrase;

class RiskLevel implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'low', 'label' => __('Low')],
            ['value' => 'medium', 'label' => __('Medium')],
            ['value' => 'high', 'label' => __('High')],
            ['value' => 'critical', 'label' => __('Critical')],
        ];
    }

    public function label(string $level): Phrase|string
    {
        foreach ($this->toOptionArray() as $option) {
            if ($option['value'] === $level) {
                return $option['label'];
            }
        }
        return $level;
    }
}
