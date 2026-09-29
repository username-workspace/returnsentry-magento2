<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Csp;

use Magento\Csp\Api\PolicyCollectorInterface;
use Magento\Csp\Model\Policy\FetchPolicy;
use Magento\Store\Model\StoreManagerInterface;
use ReturnSentry\Connector\Model\Config;

class BeaconPolicyCollector implements PolicyCollectorInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function collect(array $defaultPolicies = []): array
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        if (!$this->config->isEnabled(Config::BEACON, $storeId)) {
            return $defaultPolicies;
        }
        if (preg_match('#^https?://[^/?\#]+#i', $this->config->apiUrl($storeId), $match) !== 1) {
            return $defaultPolicies;
        }
        $origin = $match[0];
        $defaultPolicies[] = new FetchPolicy('script-src', false, [$origin]);
        $defaultPolicies[] = new FetchPolicy('connect-src', false, [$origin]);
        return $defaultPolicies;
    }
}
