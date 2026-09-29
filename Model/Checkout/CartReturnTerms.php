<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Checkout;

use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use Magento\Quote\Model\Quote;
use ReturnSentry\Connector\Api\CartReturnTermsInterface;
use ReturnSentry\Connector\Api\Data\ReturnTerms;
use ReturnSentry\Connector\Model\Config;

class CartReturnTerms implements CartReturnTermsInterface
{
    public function __construct(
        private readonly CartRepositoryInterface $carts,
        private readonly MaskedQuoteIdToQuoteIdInterface $maskedIds,
        private readonly Config $config,
        private readonly Assessor $assessor,
        private readonly ReturnTermsDisplay $display
    ) {
    }

    public function get(int $cartId): ReturnTerms
    {
        $quote = $this->carts->getActive($cartId);
        return $quote instanceof Quote ? $this->forQuote($quote) : new ReturnTerms();
    }

    public function getForGuestCart(string $cartId): ReturnTerms
    {
        return $this->get($this->maskedIds->execute($cartId));
    }

    public function forQuote(Quote $quote): ReturnTerms
    {
        $enforced = $this->config->mode(Config::CHECKOUT_DECISIONS, (int) $quote->getStoreId()) === Config::ENFORCE;
        $terms = ($enforced ? $this->assessor->assess($quote)->returnTerms : null) ?? new ReturnTerms();
        $this->display->served($quote, $terms);
        return $terms;
    }
}
