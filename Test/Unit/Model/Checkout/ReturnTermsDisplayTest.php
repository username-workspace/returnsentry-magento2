<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model\Checkout;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Api\Data\ReturnTerms;
use ReturnSentry\Connector\Model\Checkout\ReturnTermsDisplay;

class ReturnTermsDisplayTest extends TestCase
{
    /** @var array<string, string> */
    private array $cached = [];

    public function testTheLumaNoticeAttestsPaidTermsItWasServed(): void
    {
        $display = $this->display([ReturnTermsDisplay::COOKIE => '1'], []);
        $display->served($this->quote(5), new ReturnTerms(true, 6.5));

        $this->assertTrue($display->attested($this->quote(5)));
        $this->assertFalse($display->attested($this->quote(6)));
    }

    public function testAHeadlessStorefrontAttestsThroughTheHeader(): void
    {
        $display = $this->display([], [ReturnTermsDisplay::HEADER => '1']);
        $display->served($this->quote(5), new ReturnTerms(true));

        $this->assertTrue($display->attested($this->quote(5)));
    }

    public function testNoAttestationWithoutTheFrontSayingSo(): void
    {
        $display = $this->display([ReturnTermsDisplay::COOKIE => '0'], [ReturnTermsDisplay::HEADER => 'yes']);
        $display->served($this->quote(5), new ReturnTerms(true));

        $this->assertFalse($display->attested($this->quote(5)));
    }

    public function testAnAttestationDoesNotCoverTermsThatWereFreeWhenServed(): void
    {
        $display = $this->display([], [ReturnTermsDisplay::HEADER => '1']);
        $display->served($this->quote(5), new ReturnTerms(true));
        $display->served($this->quote(5), new ReturnTerms());

        $this->assertFalse($display->attested($this->quote(5)));
    }

    /**
     * @param array<string, string> $cookies
     * @param array<string, string> $headers
     */
    private function display(array $cookies, array $headers): ReturnTermsDisplay
    {
        $cookieManager = $this->createMock(CookieManagerInterface::class);
        $cookieManager->method('getCookie')->willReturnCallback(static fn (string $name) => $cookies[$name] ?? null);
        $request = $this->createMock(Http::class);
        $request->method('getHeader')->willReturnCallback(static fn (string $name) => $headers[$name] ?? false);
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('save')->willReturnCallback(function (string $data, string $key) {
            $this->cached[$key] = $data;
            return true;
        });
        $cache->method('load')->willReturnCallback(fn (string $key) => $this->cached[$key] ?? false);
        return new ReturnTermsDisplay($cookieManager, $request, $cache);
    }

    private function quote(int $id): Quote
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn($id);
        return $quote;
    }
}
