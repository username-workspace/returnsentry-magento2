<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Checkout;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Quote\Model\Quote;
use ReturnSentry\Connector\Api\Data\ReturnTerms;

class ReturnTermsDisplay
{
    public const COOKIE = 'rs_return_terms';
    public const HEADER = 'X-ReturnSentry-Return-Terms-Displayed';
    private const SERVED_TTL = 86400;

    public function __construct(
        private readonly CookieManagerInterface $cookies,
        private readonly Http $request,
        private readonly CacheInterface $cache
    ) {
    }

    public function served(Quote $quote, ReturnTerms $terms): void
    {
        $this->cache->save($terms->isPaid() ? '1' : '0', $this->key($quote), [], self::SERVED_TTL);
    }

    public function attested(Quote $quote): bool
    {
        return ($this->request->getHeader(self::HEADER) === '1' || $this->cookies->getCookie(self::COOKIE) === '1')
            && $this->cache->load($this->key($quote)) === '1';
    }

    private function key(Quote $quote): string
    {
        return 'returnsentry_return_terms_' . $quote->getId();
    }
}
