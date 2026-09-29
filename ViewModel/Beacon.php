<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\ViewModel;

use Magento\Framework\App\Request\Http;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use ReturnSentry\Connector\Model\Config;
use ReturnSentry\Connector\Model\Config\Backend\PublishableKey;

class Beacon implements ArgumentInterface
{
    private const PAGE_TYPES = [
        'cms_index_index' => 'home',
        'catalog_category_view' => 'listing',
        'catalogsearch_result_index' => 'listing',
        'catalogsearch_advanced_result' => 'listing',
        'catalog_product_view' => 'product',
        'checkout_cart_index' => 'cart',
        'checkout_index_index' => 'checkout',
        'checkout_onepage_success' => 'checkout',
    ];
    private const ACCOUNT_PREFIXES = [
        'customer_',
        'sales_order_',
        'wishlist_',
        'newsletter_manage_',
        'review_customer_',
        'vault_cards_',
    ];
    private const AUTHENTICATION_PAGES = ['customer_account_login', 'customer_account_create'];

    public function __construct(
        private readonly Config $config,
        private readonly Http $request,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function settings(): ?array
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        $key = $this->config->publishableKey($storeId);
        if ($key === ''
            || str_starts_with($key, PublishableKey::SECRET_PREFIX)
            || !$this->config->isEnabled(Config::BEACON, $storeId)
        ) {
            return null;
        }
        $action = $this->request->getFullActionName();
        return [
            'src' => $this->config->apiUrl($storeId) . '/beacon.js',
            'key' => $key,
            'page' => $this->pageType($action),
            'step' => $action === 'checkout_onepage_success' ? 'confirmation' : null,
            'checkoutSteps' => $action === 'checkout_index_index',
            'loadWithoutConsent' => $this->config->beaconLoadsWithoutConsent($storeId)
                || in_array($action, self::AUTHENTICATION_PAGES, true),
            'consentCookie' => $this->config->consentCookie($storeId),
            'consentCookieValue' => $this->config->consentCookieValue($storeId),
            'consentEvent' => $this->config->consentEvent($storeId),
        ];
    }

    private function pageType(string $action): string
    {
        if (isset(self::PAGE_TYPES[$action])) {
            return self::PAGE_TYPES[$action];
        }
        foreach (self::ACCOUNT_PREFIXES as $prefix) {
            if (str_starts_with($action, $prefix)) {
                return 'account';
            }
        }
        return 'other';
    }
}
