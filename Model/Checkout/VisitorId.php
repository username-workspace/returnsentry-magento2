<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Checkout;

use Magento\Framework\App\Request\Http;
use Magento\Framework\Stdlib\CookieManagerInterface;
use ReturnSentry\Connector\Model\Config;

class VisitorId
{
    public const COOKIE = 'rs_vid';
    public const CONSENT_COOKIE = 'rs_consent';
    public const HEADER = 'X-ReturnSentry-Visitor-Id';
    public const CONSENT_HEADER = 'X-ReturnSentry-Consent';

    public function __construct(
        private readonly CookieManagerInterface $cookies,
        private readonly Http $request,
        private readonly Config $config,
        private readonly ConsentCookie $consentCookie
    ) {
    }

    public function current(int $storeId): ?string
    {
        if (!$this->consented($storeId)) {
            return null;
        }
        $value = (string) ($this->request->getHeader(self::HEADER) ?: $this->cookies->getCookie(self::COOKIE));
        return preg_match('/^[A-Za-z0-9_-]{8,100}$/', $value) === 1 ? $value : null;
    }

    private function consented(int $storeId): bool
    {
        if ($this->config->beaconLoadsWithoutConsent($storeId)
            || $this->cookies->getCookie(self::CONSENT_COOKIE) === '1'
            || $this->request->getHeader(self::CONSENT_HEADER) === '1'
        ) {
            return true;
        }
        $name = $this->config->consentCookie($storeId);
        $cookie = $name === '' ? null : $this->cookies->getCookie($name);
        $expected = $this->config->consentCookieValue($storeId);
        return $cookie !== null && $this->consentCookie->grants((string) $cookie, $expected);
    }
}
