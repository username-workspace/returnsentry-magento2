<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model\Checkout;

use Magento\Payment\Model\MethodInterface;
use Magento\Payment\Model\MethodList;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Api\Data\Assessment;
use ReturnSentry\Connector\Model\Checkout\Assessor;
use ReturnSentry\Connector\Model\Checkout\PaymentRestriction;
use ReturnSentry\Connector\Model\Config;
use ReturnSentry\Connector\Plugin\RestrictPaymentMethods;

class PaymentRestrictionTest extends TestCase
{
    public function testHidesRiskyMethodsOnRestrictPayment(): void
    {
        $methods = [$this->method('checkmo'), $this->method('klarna'), $this->method('card')];

        $filtered = $this->restriction()->filter($methods, new Assessment('restrict_payment'), 1);

        $codes = array_map(static fn (MethodInterface $method) => $method->getCode(), $filtered);

        $this->assertSame(['checkmo', 'card'], $codes);
    }

    public function testKeepsEveryMethodWhenAllAreRisky(): void
    {
        $methods = [$this->method('klarna'), $this->method('cashondelivery')];

        $this->assertSame($methods, $this->restriction()->filter($methods, new Assessment('restrict_payment'), 1));
    }

    public function testLeavesMethodsAloneForOtherActions(): void
    {
        $methods = [$this->method('klarna'), $this->method('card')];

        $this->assertSame($methods, $this->restriction()->filter($methods, new Assessment('review'), 1));
        $this->assertSame($methods, $this->restriction()->filter($methods, Assessment::failedOpen(), 1));
    }

    public function testRejectsOnlyARiskyMethodThatWasHidden(): void
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getStoreId')->willReturn(1);
        $restriction = $this->restriction([$this->method('card')]);

        $this->assertTrue($restriction->rejects($quote, 'klarna', new Assessment('restrict_payment')));
        $this->assertFalse($restriction->rejects($quote, 'card', new Assessment('restrict_payment')));
        $this->assertFalse($restriction->rejects($quote, 'klarna', new Assessment('allow')));
        $allRisky = $this->restriction([$this->method('klarna')]);
        $this->assertFalse($allRisky->rejects($quote, 'klarna', new Assessment('restrict_payment')));
    }

    public function testPlacementChecksTheMethodWithItsOwnAssessmentWithoutAssessingAgain(): void
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getStoreId')->willReturn(1);
        $assessor = $this->createMock(Assessor::class);
        $assessor->expects($this->never())->method('assess');
        $methodList = $this->createMock(MethodList::class);
        $restriction = new PaymentRestriction($this->config(Config::ENFORCE), $methodList);
        $plugin = new RestrictPaymentMethods($assessor, $restriction);
        $methodList->method('getAvailableMethods')->willReturnCallback(
            fn (Quote $quote) => $plugin->afterGetAvailableMethods(
                $methodList,
                [$this->method('card'), $this->method('klarna')],
                $quote
            )
        );

        $this->assertTrue($restriction->rejects($quote, 'klarna', new Assessment('restrict_payment')));
        $this->assertFalse($restriction->rejects($quote, 'card', new Assessment('restrict_payment')));
        $this->assertNull($restriction->placingAssessment());
    }

    public function testRestrictsOnlyWhenCheckoutDecisionsAreEnforced(): void
    {
        $this->assertTrue($this->restriction()->isActive(1));
        $this->assertFalse($this->restriction([], Config::OBSERVE)->isActive(1));
        $this->assertFalse($this->restriction([], Config::OFF)->isActive(1));
    }

    /**
     * @param MethodInterface[] $available
     */
    private function restriction(array $available = [], string $mode = Config::ENFORCE): PaymentRestriction
    {
        $methodList = $this->createMock(MethodList::class);
        $methodList->method('getAvailableMethods')->willReturn($available);
        return new PaymentRestriction($this->config($mode), $methodList);
    }

    private function config(string $mode): Config
    {
        $config = $this->createMock(Config::class);
        $config->method('mode')->willReturn($mode);
        $config->method('riskyPaymentMethods')->willReturn(['klarna', 'cashondelivery']);
        return $config;
    }

    private function method(string $code): MethodInterface
    {
        $method = $this->createMock(MethodInterface::class);
        $method->method('getCode')->willReturn($code);
        return $method;
    }
}
