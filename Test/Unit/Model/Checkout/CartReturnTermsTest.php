<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model\Checkout;

use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Api\Data\Assessment;
use ReturnSentry\Connector\Api\Data\ReturnTerms;
use ReturnSentry\Connector\Model\Checkout\Assessor;
use ReturnSentry\Connector\Model\Checkout\CartReturnTerms;
use ReturnSentry\Connector\Model\Checkout\ReturnTermsDisplay;
use ReturnSentry\Connector\Model\Config;

class CartReturnTermsTest extends TestCase
{
    private Assessor&MockObject $assessor;
    private ReturnTermsDisplay&MockObject $display;
    private Quote&MockObject $quote;

    protected function setUp(): void
    {
        $this->assessor = $this->createMock(Assessor::class);
        $this->display = $this->createMock(ReturnTermsDisplay::class);
        $this->quote = $this->createMock(Quote::class);
        $this->quote->method('getStoreId')->willReturn(1);
    }

    public function testInEnforceTheTermsAreThoseOfTheCheckoutAssessment(): void
    {
        $this->assessor->method('assess')->with($this->quote)
            ->willReturn(new Assessment('allow', returnTerms: new ReturnTerms(true, 6.5)));
        $this->display->expects($this->once())->method('served')
            ->with($this->quote, $this->callback(static fn (ReturnTerms $terms) => $terms->isPaid()));

        $terms = $this->service(Config::ENFORCE)->get(5);

        $this->assertTrue($terms->isPaid());
        $this->assertSame(6.5, $terms->getLabelCost());
    }

    public function testOutsideEnforceReturnsStayFreeWithoutAnAssessment(): void
    {
        $this->assessor->expects($this->never())->method('assess');
        $this->display->expects($this->once())->method('served')
            ->with($this->quote, $this->callback(static fn (ReturnTerms $terms) => !$terms->isPaid()));

        $this->assertFalse($this->service(Config::OBSERVE)->get(5)->isPaid());
    }

    public function testAnAssessmentWithoutTermsMeansFreeReturns(): void
    {
        $this->assessor->method('assess')->willReturn(Assessment::failedOpen());

        $terms = $this->service(Config::ENFORCE)->get(5);

        $this->assertFalse($terms->isPaid());
        $this->assertNull($terms->getLabelCost());
    }

    public function testAGuestCartIsFoundByItsMaskedId(): void
    {
        $this->assessor->method('assess')->willReturn(new Assessment('allow', returnTerms: new ReturnTerms(true)));

        $this->assertTrue($this->service(Config::ENFORCE)->getForGuestCart('m4sk3d')->isPaid());
    }

    private function service(string $mode): CartReturnTerms
    {
        $carts = $this->createMock(CartRepositoryInterface::class);
        $carts->method('getActive')->with(5)->willReturn($this->quote);
        $maskedIds = $this->createMock(MaskedQuoteIdToQuoteIdInterface::class);
        $maskedIds->method('execute')->with('m4sk3d')->willReturn(5);
        $config = $this->createMock(Config::class);
        $config->method('mode')->with(Config::CHECKOUT_DECISIONS, 1)->willReturn($mode);
        return new CartReturnTerms($carts, $maskedIds, $config, $this->assessor, $this->display);
    }
}
