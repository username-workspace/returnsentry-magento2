<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\PaymentException;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use ReturnSentry\Connector\Model\Checkout\Assessor;
use ReturnSentry\Connector\Model\Checkout\PaymentRestriction;
use ReturnSentry\Connector\Model\Checkout\ReturnTermsDisplay;
use ReturnSentry\Connector\Model\Config;
use ReturnSentry\Connector\Model\Source\RiskLevel;

class ApplyCheckoutDecision implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Assessor $assessor,
        private readonly PaymentRestriction $restriction,
        private readonly ReturnTermsDisplay $termsDisplay,
        private readonly RiskLevel $riskLevels
    ) {
    }

    /**
     * @throws PaymentException
     */
    public function execute(Observer $observer): void
    {
        $quote = $observer->getEvent()->getData('quote');
        $order = $observer->getEvent()->getData('order');
        if (!$quote instanceof Quote || !$order instanceof Order) {
            return;
        }
        $storeId = (int) $quote->getStoreId();
        $mode = $this->config->mode(Config::CHECKOUT_DECISIONS, $storeId);
        $method = (string) $order->getPayment()?->getMethod();
        if ($mode === Config::OFF || $this->config->isExcludedPaymentMethod($method, $storeId)) {
            return;
        }
        $termsDisplayed = $this->termsDisplay->attested($quote);
        $assessment = $this->assessor->assess($quote, $termsDisplayed);
        if (!$assessment->assessed || ($assessment->failOpen && $assessment->action === 'allow')) {
            return;
        }
        $order->addData([
            'returnsentry_level' => $assessment->level,
            'returnsentry_score' => $assessment->score,
        ]);
        if ($termsDisplayed && $assessment->returnTerms?->isPaid()) {
            $order->addData([
                'returnsentry_return_paid' => 1,
                'returnsentry_return_label_cost' => $assessment->returnTerms->getLabelCost(),
            ]);
        }
        if ($mode === Config::OBSERVE) {
            $order->setData('returnsentry_observed_action', $assessment->action);
            return;
        }
        if ($assessment->action === 'block') {
            throw new PaymentException(__('We cannot complete this order. Please contact our customer service.'));
        }
        if ($method !== '' && $this->restriction->rejects($quote, $method, $assessment)) {
            throw new PaymentException(
                __('This payment method is not available for this order. Please choose another payment method.')
            );
        }
        $order->setData('returnsentry_action', $assessment->action);
        if ($assessment->action === 'review') {
            $order->addCommentToStatusHistory((string) __(
                'ReturnSentry recommends a manual review of this order (level %1, score %2). Reasons: %3',
                $this->riskLevels->label($assessment->level),
                $assessment->score,
                implode('; ', $assessment->reasons) ?: '-'
            ));
        }
    }
}
