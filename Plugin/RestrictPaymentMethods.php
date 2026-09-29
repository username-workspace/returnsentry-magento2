<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Plugin;

use Magento\Payment\Model\MethodInterface;
use Magento\Payment\Model\MethodList;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use ReturnSentry\Connector\Model\Checkout\Assessor;
use ReturnSentry\Connector\Model\Checkout\PaymentRestriction;

class RestrictPaymentMethods
{
    public function __construct(
        private readonly Assessor $assessor,
        private readonly PaymentRestriction $restriction
    ) {
    }

    /**
     * @param MethodInterface[] $result
     * @return MethodInterface[]
     */
    public function afterGetAvailableMethods(MethodList $subject, array $result, ?CartInterface $quote = null): array
    {
        if (!$quote instanceof Quote || !$this->restriction->isActive((int) $quote->getStoreId())) {
            return $result;
        }
        $assessment = $this->restriction->placingAssessment() ?? $this->assessor->assess($quote);
        return $this->restriction->filter($result, $assessment, (int) $quote->getStoreId());
    }
}
