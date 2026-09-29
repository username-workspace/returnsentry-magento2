<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Checkout;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\HTTP\Header;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use Psr\Log\LoggerInterface;
use ReturnSentry\Connector\Api\Data\Assessment;
use ReturnSentry\Connector\Model\Config;
use ReturnSentry\Connector\Model\Http\ApiException;
use ReturnSentry\Connector\Model\Http\Client;
use ReturnSentry\Connector\Model\Mapper\Fields;
use ReturnSentry\Connector\Model\Mapper\Identity;

class Assessor
{
    private const CACHE_TTL = 600;
    private const FAIL_OPEN_CACHE_TTL = 60;
    private const STAFF_CHANNELS = [UserContextInterface::USER_TYPE_ADMIN, UserContextInterface::USER_TYPE_INTEGRATION];

    public function __construct(
        private readonly Config $config,
        private readonly Client $client,
        private readonly Identity $identity,
        private readonly VisitorId $visitorId,
        private readonly OrderNumber $orderNumber,
        private readonly UserContextInterface $userContext,
        private readonly CacheInterface $cache,
        private readonly Json $json,
        private readonly RemoteAddress $remoteAddress,
        private readonly Header $header,
        private readonly LoggerInterface $logger
    ) {
    }

    public function assess(Quote $quote, bool $returnTermsDisplayed = false): Assessment
    {
        if (in_array($this->userContext->getUserType(), self::STAFF_CHANNELS, true)) {
            return Assessment::notAssessed();
        }
        $storeId = (int) $quote->getStoreId();
        $body = $this->body($quote, $storeId, $returnTermsDisplayed);
        if ($body === null) {
            return Assessment::notAssessed();
        }
        $key = 'returnsentry_checkout_' . $quote->getId() . '_' . sha1($this->json->serialize($body));
        $cached = $this->cache->load($key);
        if ($cached) {
            $data = $this->json->unserialize($cached);
            return Assessment::fromArray(is_array($data) ? $data : []);
        }
        try {
            $timeoutMs = $this->config->checkoutTimeoutMs($storeId);
            $response = $this->client->post('/v1/assessments', $body, $storeId, $timeoutMs);
            $assessment = Assessment::fromArray($response);
        } catch (ApiException $exception) {
            $this->logger->warning('ReturnSentry checkout assessment failed open', [
                'quote' => $quote->getId(),
                'error' => $exception->getMessage(),
            ]);
            $assessment = Assessment::failedOpen();
        }
        $this->cache->save(
            $this->json->serialize($assessment->toArray()),
            $key,
            [],
            $assessment->failOpen ? self::FAIL_OPEN_CACHE_TTL : self::CACHE_TTL
        );
        return $assessment;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function body(Quote $quote, int $storeId, bool $returnTermsDisplayed): ?array
    {
        $address = $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();
        if (!$quote->isVirtual() && !$address->getCountryId()) {
            return null;
        }
        $email = $quote->getCustomerEmail() ?: $quote->getBillingAddress()->getEmail() ?: $address->getEmail();
        $customer = $this->identity->customer(
            (int) $quote->getCustomerId() ?: null,
            $email ? (string) $email : null,
            $address->getTelephone(),
            $storeId
        );
        $visitorId = $this->visitorId->current($storeId);
        if ($customer === [] && $visitorId === null) {
            return null;
        }
        $this->orderNumber->reserve($quote);
        $pseudonymized = $this->identity->isPseudonymized($storeId);
        $mode = $this->config->mode(Config::CHECKOUT_DECISIONS, $storeId);
        return Fields::compact([
            'context' => 'checkout',
            'mode' => $mode === Config::OFF ? null : $mode,
            'returnTermsDisplayed' => $returnTermsDisplayed,
            'customer' => $customer,
            'device' => [
                'visitorId' => $visitorId,
                'ip' => $pseudonymized ? null : Fields::clip((string) $this->remoteAddress->getRemoteAddress(), 64),
                'userAgent' => Fields::clip($this->header->getHttpUserAgent(), 500),
            ],
            'order' => [
                'externalId' => Fields::clip((string) $quote->getReservedOrderId(), 200),
                'amount' => Fields::money($quote->getBaseGrandTotal()),
                'currency' => $quote->getQuoteCurrencyCode(),
                'items' => array_map(fn (Item $item) => $this->item($item), $quote->getAllVisibleItems()),
            ] + $this->identity->address($address, $storeId),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function item(Item $item): array
    {
        return Fields::compact([
            'sku' => Fields::clip((string) $item->getSku(), 200) ?? (string) $item->getProductId(),
            'productId' => Fields::clip((string) $item->getProduct()->getData('sku'), 200),
            'name' => Fields::clip((string) $item->getName(), 300),
            'quantity' => max(1, (int) $item->getQty()),
            'amount' => Fields::money((float) $item->getBaseRowTotalInclTax() - (float) $item->getBaseDiscountAmount()),
        ]);
    }
}
