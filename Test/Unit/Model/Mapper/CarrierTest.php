<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model\Mapper;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Model\Mapper\Carrier;

class CarrierTest extends TestCase
{
    /**
     * @return array<string, array{list<?string>, string}>
     */
    public static function labels(): array
    {
        return [
            'magento ups' => [['ups', 'United Parcel Service'], 'ups'],
            'magento fedex' => [['fedex', 'Federal Express'], 'fedex'],
            'magento dhl' => [['dhl', 'DHL'], 'dhl_express'],
            'usps is not ups' => [['usps', 'United States Postal Service'], 'other'],
            'custom carrier by its title' => [['custom', 'Colissimo'], 'colissimo'],
            'la poste' => [['custom', 'La Poste'], 'colissimo'],
            'accented title' => [['custom', 'Colis Privé'], 'colis_prive'],
            'relais colis' => [['custom', 'Relais Colis'], 'relais_colis'],
            'chronopost module code' => [['chronorelais', 'Livraison en point relais'], 'chronopost'],
            'dpd module code' => [['dpdfrpredict', 'Livraison Predict'], 'dpd_fr'],
            'mondial relay module code' => [['mondialrelaypickup', 'Point Relais'], 'mondial_relay'],
            'code wins over title' => [['dhl', 'DHL Parcel'], 'dhl_express'],
            'title when the code is unknown' => [['custom', 'DHL Parcel'], 'dhl_parcel'],
            'unknown' => [['custom', 'Transporteur local'], 'other'],
            'nothing' => [[null, null], 'other'],
        ];
    }

    /**
     * @param list<?string> $labels
     */
    #[DataProvider('labels')]
    public function testMapsMagentoCarriersToTheStandardCodes(array $labels, string $expected): void
    {
        $this->assertSame($expected, (new Carrier())->standard(...$labels));
    }
}
