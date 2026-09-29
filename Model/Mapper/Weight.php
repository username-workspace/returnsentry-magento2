<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Mapper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Weight
{
    private const UNIT_PATH = 'general/locale/weight_unit';
    private const GRAMS_PER_UNIT = ['kgs' => 1000.0, 'lbs' => 453.59237];
    private const MAX_GRAMS = 1000000;

    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    public function grams(float|string|null $weight, int|string|null $storeId): ?int
    {
        $unit = (string) $this->scopeConfig->getValue(self::UNIT_PATH, ScopeInterface::SCOPE_STORE, $storeId);
        $perUnit = self::GRAMS_PER_UNIT[$unit] ?? null;
        if ($perUnit === null || $weight === null) {
            return null;
        }
        $grams = (int) round((float) $weight * $perUnit);
        return $grams > 0 && $grams <= self::MAX_GRAMS ? $grams : null;
    }
}
