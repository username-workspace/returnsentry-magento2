<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\PaymentException;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Api\Data\Assessment;
use ReturnSentry\Connector\Api\Data\ReturnTerms;
use ReturnSentry\Connector\Model\Checkout\Assessor;
use ReturnSentry\Connector\Model\Checkout\PaymentRestriction;
use ReturnSentry\Connector\Model\Checkout\ReturnTermsDisplay;
use ReturnSentry\Connector\Model\Config;
use ReturnSentry\Connector\Model\Source\RiskLevel;
use ReturnSentry\Connector\Observer\ApplyCheckoutDecision;

class ApplyCheckoutDecisionTest extends TestCase
{
    private Order&MockObject $order;
    private PaymentRestriction&MockObject $restriction;

    protected function setUp(): void
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn('klarna');
        $this->order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPayment', 'addCommentToStatusHistory'])
            ->getMock();
        $this->order->method('getPayment')->willReturn($payment);
        $this->restriction = $this->createMock(PaymentRestriction::class);
    }

    public function testBlockRefusesTheOrderWithANeutralMessage(): void
    {
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('We cannot complete this order. Please contact our customer service.');

        $this->apply(new Assessment('block', 'critical', 95));
    }

    public function testReviewFlagsTheOrderForAManualReview(): void
    {
        $this->order->expects($this->once())
            ->method('addCommentToStatusHistory')
            ->with($this->stringContains('manual review of this order (level High, score 72). Reasons: Many returns'));

        $this->apply(new Assessment('review', 'high', 72, ['Many returns']));

        $this->assertSame('review', $this->order->getData('returnsentry_action'));
        $this->assertSame('high', $this->order->getData('returnsentry_level'));
        $this->assertSame(72, $this->order->getData('returnsentry_score'));
    }

    public function testRejectsARiskyMethodHiddenByTheRestriction(): void
    {
        $this->restriction->method('rejects')->willReturn(true);
        $this->expectException(PaymentException::class);

        $this->apply(new Assessment('restrict_payment', 'critical', 85));
    }

    public function testObserveKeepsTheDecisionWithoutApplyingIt(): void
    {
        $this->order->expects($this->never())->method('addCommentToStatusHistory');
        $this->restriction->expects($this->never())->method('rejects');

        $this->apply(new Assessment('block', 'critical', 95), Config::OBSERVE);
        $this->apply(new Assessment('restrict_payment', 'critical', 90), Config::OBSERVE);
        $this->apply(new Assessment('review', 'high', 72), Config::OBSERVE);

        $this->assertSame('review', $this->order->getData('returnsentry_observed_action'));
        $this->assertNull($this->order->getData('returnsentry_action'));
        $this->assertSame(72, $this->order->getData('returnsentry_score'));
    }

    public function testOffMakesNoCall(): void
    {
        $this->apply(new Assessment('block', 'critical', 95), Config::OFF);

        $this->assertNull($this->order->getData('returnsentry_level'));
    }

    public function testAFailOpenAnswerStillCarriesAnOperatorBlock(): void
    {
        $this->expectException(PaymentException::class);

        $this->apply(new Assessment('block', 'low', 0, [], true));
    }

    public function testFailOpenLetsTheOrderThroughUntouched(): void
    {
        $this->order->expects($this->never())->method('addCommentToStatusHistory');

        $this->apply(Assessment::failedOpen());
        $this->apply(Assessment::notAssessed());

        $this->assertNull($this->order->getData('returnsentry_action'));
    }

    public function testKeepsTheAnnouncedPaidTermsOnTheOrderForItsConfirmation(): void
    {
        $this->apply(new Assessment('allow', returnTerms: new ReturnTerms(true, 6.5)), displayed: true);

        $this->assertSame(1, $this->order->getData('returnsentry_return_paid'));
        $this->assertSame(6.5, $this->order->getData('returnsentry_return_label_cost'));
    }

    public function testKeepsNoTermsThatWereNotDisplayedOrAreFree(): void
    {
        $this->apply(new Assessment('allow', returnTerms: new ReturnTerms(true, 6.5)));
        $this->apply(new Assessment('allow', returnTerms: new ReturnTerms()), displayed: true);

        $this->assertNull($this->order->getData('returnsentry_return_paid'));
        $this->assertNull($this->order->getData('returnsentry_return_label_cost'));
    }

    public function testAnOrderPaidWithAnExcludedMethodIsNeitherAssessedNorFiltered(): void
    {
        $this->restriction->expects($this->never())->method('rejects');
        $this->order->expects($this->never())->method('addCommentToStatusHistory');

        $this->apply(new Assessment('block', 'critical', 95), excluded: 'klarna');

        $this->assertNull($this->order->getData('returnsentry_level'));
        $this->assertNull($this->order->getData('returnsentry_action'));
    }

    private function apply(
        Assessment $assessment,
        string $mode = Config::ENFORCE,
        bool $displayed = false,
        ?string $excluded = null
    ): void {
        $quote = $this->createMock(Quote::class);
        $quote->method('getStoreId')->willReturn(1);
        $config = $this->createMock(Config::class);
        $config->method('mode')->willReturn($mode);
        $config->method('isExcludedPaymentMethod')
            ->willReturnCallback(static fn (string $method, int $storeId) => $method === $excluded && $storeId === 1);
        $assessor = $this->createMock(Assessor::class);
        $assessor->expects($mode === Config::OFF || $excluded !== null ? $this->never() : $this->once())
            ->method('assess')
            ->with($quote, $displayed)
            ->willReturn($assessment);
        $observer = new Observer(['event' => new Event(['quote' => $quote, 'order' => $this->order])]);

        $termsDisplay = $this->createMock(ReturnTermsDisplay::class);
        $termsDisplay->method('attested')->with($quote)->willReturn($displayed);

        (new ApplyCheckoutDecision($config, $assessor, $this->restriction, $termsDisplay, new RiskLevel()))
            ->execute($observer);
    }
}
