<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model\Checkout;

use Magento\Framework\App\Request\Http;
use Magento\Framework\Stdlib\CookieManagerInterface;
use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Model\Checkout\ConsentCookie;
use ReturnSentry\Connector\Model\Checkout\VisitorId;
use ReturnSentry\Connector\Model\Config;

class VisitorIdTest extends TestCase
{
    private const VISITOR = 'Qm9vbGVhbi1zdHJpbmc';

    public function testWithoutConsentTheVisitorIdIsNeitherReadNorSent(): void
    {
        $read = [];
        $cookies = $this->createMock(CookieManagerInterface::class);
        $cookies->method('getCookie')->willReturnCallback(static function (string $name) use (&$read) {
            $read[] = $name;
            return $name === VisitorId::COOKIE ? self::VISITOR : null;
        });

        $visitorId = new VisitorId($cookies, $this->request([]), $this->config(false, '', ''), new ConsentCookie());

        $this->assertNull($visitorId->current(1));
        $this->assertNotContains(VisitorId::COOKIE, $read);
    }

    public function testTheConsentEventMarkerLetsItThrough(): void
    {
        $visitorId = $this->visitorId([VisitorId::CONSENT_COOKIE => '1'], $this->config(false, '', ''));

        $this->assertSame(self::VISITOR, $visitorId->current(1));
    }

    public function testTheConfiguredConsentCookieMustContainItsText(): void
    {
        $config = $this->config(false, 'axeptio_authorized_vendors', 'returnsentry');

        $granted = $this->visitorId(['axeptio_authorized_vendors' => ',google,returnsentry,'], $config);
        $refused = $this->visitorId(['axeptio_authorized_vendors' => ',google,'], $config);

        $this->assertSame(self::VISITOR, $granted->current(1));
        $this->assertNull($refused->current(1));
    }

    public function testARefusalWrittenInTheConsentCookieIsNoConsent(): void
    {
        $config = $this->config(false, 'OptanonConsent', 'C0004');
        $refused = $this->visitorId(['OptanonConsent' => 'groups=C0001:1,C0004:0'], $config);

        $this->assertNull($refused->current(1));
    }

    public function testTheUnitedKingdomModeKeepsReadingIt(): void
    {
        $this->assertSame(self::VISITOR, $this->visitorId([], $this->config(true, '', ''))->current(1));
    }

    public function testAHeadlessStorefrontPassesTheVisitorIdAndConsentInHeaders(): void
    {
        $headers = [VisitorId::HEADER => 'k3Jd9QxW2bL0pZ7aR4tY1g', VisitorId::CONSENT_HEADER => '1'];

        $visitorId = $this->visitorId([], $this->config(false, '', ''), $headers);

        $this->assertSame('k3Jd9QxW2bL0pZ7aR4tY1g', $visitorId->current(1));
    }

    public function testAVisitorIdHeaderWithoutTheConsentHeaderIsNotRead(): void
    {
        $config = $this->config(false, '', '');

        $absent = $this->visitorId([], $config, [VisitorId::HEADER => 'k3Jd9QxW2bL0pZ7aR4tY1g']);
        $refused = $this->visitorId([], $config, [
            VisitorId::HEADER => 'k3Jd9QxW2bL0pZ7aR4tY1g',
            VisitorId::CONSENT_HEADER => '0',
        ]);

        $this->assertNull($absent->current(1));
        $this->assertNull($refused->current(1));
    }

    public function testAVisitorIdHeaderIsValidatedLikeTheCookie(): void
    {
        $visitorId = $this->visitorId([], $this->config(false, '', ''), [
            VisitorId::HEADER => 'k3Jd9QxW;admin=1',
            VisitorId::CONSENT_HEADER => '1',
        ]);

        $this->assertNull($visitorId->current(1));
    }

    /**
     * @param array<string, string> $cookies
     * @param array<string, string> $headers
     */
    private function visitorId(array $cookies, Config $config, array $headers = []): VisitorId
    {
        return new VisitorId($this->cookies($cookies), $this->request($headers), $config, new ConsentCookie());
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(array $headers): Http
    {
        $request = $this->createMock(Http::class);
        $request->method('getHeader')->willReturnCallback(static fn (string $name) => $headers[$name] ?? false);
        return $request;
    }

    /**
     * @param array<string, string> $cookies
     */
    private function cookies(array $cookies): CookieManagerInterface
    {
        $manager = $this->createMock(CookieManagerInterface::class);
        $manager->method('getCookie')->willReturnCallback(
            static fn (string $name) => ($cookies + [VisitorId::COOKIE => self::VISITOR])[$name] ?? null
        );
        return $manager;
    }

    private function config(bool $withoutConsent, string $cookie, string $text): Config
    {
        $config = $this->createMock(Config::class);
        $config->method('beaconLoadsWithoutConsent')->willReturn($withoutConsent);
        $config->method('consentCookie')->willReturn($cookie);
        $config->method('consentCookieValue')->willReturn($text);
        return $config;
    }
}
