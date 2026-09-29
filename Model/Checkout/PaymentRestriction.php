<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Checkout;

use Magento\Payment\Model\MethodInterface;
use Magento\Payment\Model\MethodList;
use Magento\Quote\Model\Quote;
use ReturnSentry\Connector\Api\Data\Assessment;
use ReturnSentry\Connector\Model\Config;

class PaymentRestriction
{
    private ?Assessment $placing = null;

    public function __construct(
        private readonly Config $config,
        private readonly MethodList $methodList
    ) {
    }

    public function isActive(int $storeId): bool
    {
        return $this->config->mode(Config::CHECKOUT_DECISIONS, $storeId) === Config::ENFORCE
            && $this->config->riskyPaymentMethods($storeId) !== [];
    }

    /**
     * @param MethodInterface[] $methods
     * @return MethodInterface[]
     */
    public function filter(array $methods, Assessment $assessment, int $storeId): array
    {
        if ($assessment->action !== 'restrict_payment') {
            return $methods;
        }
        $risky = $this->config->riskyPaymentMethods($storeId);
        $allowed = array_values(array_filter(
            $methods,
            static fn (MethodInterface $method) => !in_array($method->getCode(), $risky, true)
        ));
        return $allowed === [] ? $methods : $allowed;
    }

    public function rejects(Quote $quote, string $method, Assessment $assessment): bool
    {
        $storeId = (int) $quote->getStoreId();
        if ($assessment->action !== 'restrict_payment'
            || !$this->isActive($storeId)
            || !in_array($method, $this->config->riskyPaymentMethods($storeId), true)
        ) {
            return false;
        }
        $this->placing = $assessment;
        try {
            $available = array_map(
                static fn (MethodInterface $available) => $available->getCode(),
                $this->methodList->getAvailableMethods($quote)
            );
        } finally {
            $this->placing = null;
        }
        return !in_array($method, $available, true);
    }

    public function placingAssessment(): ?Assessment
    {
        return $this->placing;
    }
}
