<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;

class Config
{
    public const CHECKOUT_DECISIONS = 'checkout_decisions';
    public const RETURN_ASSESSMENT = 'return_assessment';
    public const REFUND_AUTHORIZATION = 'refund_authorization';
    public const BEACON = 'beacon';

    public const MIN_SALT_LENGTH = 32;

    public const OFF = 'off';
    public const OBSERVE = 'observe';
    public const ENFORCE = 'enforce';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Json $json
    ) {
    }

    public function isConnected(int|string|null $storeId): bool
    {
        $salt = $this->pseudonymSalt($storeId);
        return $this->flag('general/enabled', $storeId)
            && $this->apiUrl($storeId) !== ''
            && $this->secretKey($storeId) !== ''
            && ($salt === null || strlen($salt) >= self::MIN_SALT_LENGTH);
    }

    public function isEnabled(string $feature, int|string|null $storeId): bool
    {
        return $this->flag('features/' . $feature, $storeId) && $this->isConnected($storeId);
    }

    public function mode(string $decisionPoint, int|string|null $storeId): string
    {
        $mode = $this->value('features/' . $decisionPoint, $storeId);
        $active = in_array($mode, [self::OBSERVE, self::ENFORCE], true) && $this->isConnected($storeId);
        return $active ? $mode : self::OFF;
    }

    public function apiUrl(int|string|null $storeId): string
    {
        return rtrim($this->value('general/api_url', $storeId), '/');
    }

    public function secretKey(int|string|null $storeId): string
    {
        return $this->value('general/secret_key', $storeId);
    }

    public function publishableKey(int|string|null $storeId): string
    {
        return $this->value('general/publishable_key', $storeId);
    }

    public function checkoutTimeoutMs(int|string|null $storeId): int
    {
        return max(50, (int) $this->value('general/checkout_timeout_ms', $storeId));
    }

    public function timeoutMs(int|string|null $storeId): int
    {
        return max(100, (int) $this->value('general/timeout_ms', $storeId));
    }

    /**
     * @return string[]
     */
    public function riskyPaymentMethods(int|string|null $storeId): array
    {
        return array_values(array_filter(explode(',', $this->value('checkout/risky_payment_methods', $storeId))));
    }

    public function isExcludedPaymentMethod(string $method, int|string|null $storeId): bool
    {
        return $method !== ''
            && in_array($method, explode(',', $this->value('features/excluded_payment_methods', $storeId)), true);
    }

    public function pseudonymSalt(int|string|null $storeId): ?string
    {
        return $this->flag('privacy/pseudonymize', $storeId) ? $this->value('privacy/salt', $storeId) : null;
    }

    /**
     * @return array<string, string>
     */
    public function returnReasonMap(int|string|null $storeId): array
    {
        return $this->map('mapping/return_reasons', $storeId);
    }

    public function refundCodeField(int|string|null $storeId): string
    {
        return $this->value('mapping/refund_code_field', $storeId);
    }

    /**
     * @return array<string, string>
     */
    public function refundCodeMap(int|string|null $storeId): array
    {
        return $this->map('mapping/refund_codes', $storeId);
    }

    public function refundCategoryFor(string $situation, int|string|null $storeId): string
    {
        return $this->value('mapping/refund_' . $situation, $storeId) ?: 'other';
    }

    public function beaconLoadsWithoutConsent(int|string|null $storeId): bool
    {
        return $this->value('beacon/loading', $storeId) === 'always';
    }

    public function consentCookie(int|string|null $storeId): string
    {
        return $this->value('beacon/consent_cookie', $storeId);
    }

    public function consentCookieValue(int|string|null $storeId): string
    {
        return $this->value('beacon/consent_cookie_value', $storeId);
    }

    public function consentEvent(int|string|null $storeId): string
    {
        return $this->value('beacon/consent_event', $storeId);
    }

    public function normalizeLabel(string $label): string
    {
        $normalized = \Normalizer::normalize($label, \Normalizer::FORM_C) ?: $label;
        return mb_strtolower(trim(str_replace(['‘', '’'], "'", $normalized)));
    }

    /**
     * @return array<string, string>
     */
    private function map(string $path, int|string|null $storeId): array
    {
        $raw = $this->value($path, $storeId);
        $rows = $raw === '' ? [] : $this->json->unserialize($raw);
        $map = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && !empty($row['shop']) && !empty($row['standard'])) {
                $map[$this->normalizeLabel((string) $row['shop'])] = (string) $row['standard'];
            }
        }
        return $map;
    }

    private function flag(string $path, int|string|null $storeId): bool
    {
        return $this->scopeConfig->isSetFlag('returnsentry/' . $path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    private function value(string $path, int|string|null $storeId): string
    {
        $value = $this->scopeConfig->getValue('returnsentry/' . $path, ScopeInterface::SCOPE_STORE, $storeId);
        return trim((string) $value);
    }
}
