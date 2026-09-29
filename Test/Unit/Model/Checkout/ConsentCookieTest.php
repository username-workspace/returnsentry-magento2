<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model\Checkout;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Model\Checkout\ConsentCookie;

class ConsentCookieTest extends TestCase
{
    private const ONETRUST = 'isGpcEnabled=0&datestamp=Sat Sep 27 2026 10:00:00 GMT+0200 (Central European Summer Time)'
        . '&version=202409.1.0&browserGpcFlag=0&isIABGlobal=false&hosts=&consentId=0a1b2c3d&interactionCount=1'
        . '&isAnonUser=1&landingPath=NotLandingPage&groups=C0001:1,C0002:0,C0003:0,C0004:1&AwaitingReconsent=false';
    private const AXEPTIO_VENDORS = ',google_analytics,returnsentry,';
    private const AXEPTIO_COOKIES = '{"$$token":"f1a2b3","$$date":"2026-09-27T08:00:00.000Z",'
        . '"$$cookiesVersion":{"name":"shop-fr","identifier":"a1"},"returnsentry":true,"google_analytics":false,'
        . '"$$completed":true}';
    private const DIDOMI_JSON = '{"user_id":"1880b3a4","created":"2026-09-27T08:00:00.000Z",'
        . '"vendors":{"enabled":["google","c:returnsentry"],"disabled":[]},'
        . '"purposes":{"enabled":["device_characteristics"]},"version":2}';

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function cookies(): array
    {
        $didomi = base64_encode(self::DIDOMI_JSON);
        return [
            'onetrust accepted group' => [self::ONETRUST, 'C0004:1', true],
            'onetrust accepted group by name' => [self::ONETRUST, 'C0004', true],
            'onetrust first group after groups=' => [self::ONETRUST, 'C0001', true],
            'onetrust refused group by name' => [self::ONETRUST, 'C0002', false],
            'onetrust refused group as accepted' => [self::ONETRUST, 'C0002:1', false],
            'onetrust other refused group' => [self::ONETRUST, 'C0003', false],
            'onetrust false flag' => [self::ONETRUST, 'isIABGlobal', false],
            'axeptio vendor listed' => [self::AXEPTIO_VENDORS, 'returnsentry', true],
            'axeptio vendor missing' => [',google_analytics,', 'returnsentry', false],
            'axeptio cookie json accepted' => [self::AXEPTIO_COOKIES, 'returnsentry', true],
            'axeptio cookie json refused' => [self::AXEPTIO_COOKIES, 'google_analytics', false],
            'didomi encoded token needs the event' => [$didomi, 'returnsentry', false],
            'didomi encoded token by vendor id' => [$didomi, 'c:returnsentry', false],
            'key=1 form accepted' => ['analytics=1|marketing=0', 'analytics', true],
            'key=0 form refused' => ['analytics=1|marketing=0', 'marketing', false],
            'no substring match' => [',returnsentry_disabled,', 'returnsentry', false],
            'empty expected value never grants' => [self::AXEPTIO_VENDORS, '', false],
        ];
    }

    #[DataProvider('cookies')]
    public function testConsentIsAWholeTokenOrAnAcceptedKey(string $cookie, string $expected, bool $granted): void
    {
        $this->assertSame($granted, (new ConsentCookie())->grants($cookie, $expected));
    }
}
