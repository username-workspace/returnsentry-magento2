<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Mapper;

class Carrier
{
    public const OTHER = 'other';
    private const ALIASES = [
        'relaiscolis' => 'relais_colis',
        'colisprive' => 'colis_prive',
        'colissimo' => 'colissimo',
        'laposte' => 'colissimo',
        'chrono' => 'chronopost',
        'mondialrelay' => 'mondial_relay',
        'dhlparcel' => 'dhl_parcel',
        'dhlpaket' => 'dhl_parcel',
        'dhlecommerce' => 'dhl_parcel',
        'dhl' => 'dhl_express',
        'dpd' => 'dpd_fr',
        'gls' => 'gls',
        'ups' => 'ups',
        'fedex' => 'fedex',
        'geodis' => 'geodis',
        'amazon' => 'amazon_shipping',
        'vinted' => 'vinted_go',
    ];

    public function standard(?string ...$labels): string
    {
        foreach ($labels as $label) {
            $ascii = strtolower((string) transliterator_transliterate('Any-Latin; Latin-ASCII', (string) $label));
            $words = preg_split('/[^a-z0-9]+/', $ascii, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $joined = implode('', $words);
            foreach (self::ALIASES as $alias => $carrier) {
                if ($this->matches($alias, $words, $joined)) {
                    return $carrier;
                }
            }
        }
        return self::OTHER;
    }

    /**
     * @param string[] $words
     */
    private function matches(string $alias, array $words, string $joined): bool
    {
        if (strlen($alias) > 3) {
            return str_contains($joined, $alias);
        }
        foreach ($words as $word) {
            if (str_starts_with($word, $alias)) {
                return true;
            }
        }
        return false;
    }
}
