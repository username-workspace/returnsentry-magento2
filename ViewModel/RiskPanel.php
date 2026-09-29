<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\ViewModel;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Phrase;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Psr\Log\LoggerInterface;
use ReturnSentry\Connector\Model\Config;
use ReturnSentry\Connector\Model\Http\ApiException;
use ReturnSentry\Connector\Model\Http\Client;
use ReturnSentry\Connector\Model\Mapper\Identity;
use ReturnSentry\Connector\Model\Mapper\OrderMapper;
use ReturnSentry\Connector\Model\OrderLoader;
use ReturnSentry\Connector\Model\Refund\AuthorizationStore;
use ReturnSentry\Connector\Model\Refund\CarrierSummary;
use ReturnSentry\Connector\Model\Returns\ReturnStore;
use ReturnSentry\Connector\Model\Source\RefundCategory;
use ReturnSentry\Connector\Model\Source\RiskLevel;

class RiskPanel implements ArgumentInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly OrderLoader $orders,
        private readonly CustomerRepositoryInterface $customers,
        private readonly Config $config,
        private readonly Client $client,
        private readonly OrderMapper $orderMapper,
        private readonly Identity $identity,
        private readonly ReturnStore $returns,
        private readonly AuthorizationStore $authorizations,
        private readonly CarrierSummary $carrierSummary,
        private readonly RefundCategory $categories,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
        private readonly AnnouncedReturnTerms $announcedTerms,
        private readonly RiskLevel $riskLevels
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forOrder(): ?array
    {
        try {
            $order = $this->orders->get((int) $this->request->getParam('order_id'));
        } catch (NoSuchEntityException) {
            return null;
        }
        $storeId = (int) $order->getStoreId();
        if (!$this->config->isConnected($storeId)) {
            return null;
        }
        $action = (string) $order->getData('returnsentry_action');
        $observedAction = (string) $order->getData('returnsentry_observed_action');
        $paidReturns = (int) $order->getData('returnsentry_return_paid') === 1;
        return [
            'checkout' => $action !== '' || $observedAction !== '' ? [
                'action' => $action !== ''
                    ? $this->checkoutActionLabel($action)
                    : $this->wouldHave($this->checkoutActionLabel($observedAction)),
                'level' => $this->riskLevels->label((string) $order->getData('returnsentry_level')),
                'score' => (int) $order->getData('returnsentry_score'),
            ] : null,
            'returnTerms' => $this->announcedTerms->notice($order),
            'risk' => $this->lookup($this->orderMapper->customerRef($order), $storeId),
            'returns' => array_map(fn (array $return) => [
                'externalId' => (string) ($return['record']['externalId'] ?? ''),
                'action' => $return['assessment'] ? $this->returnActionLabel($return['assessment']->action) : null,
                'meaning' => $return['assessment']
                    ? $this->returnActionMeaning($return['assessment']->action, $paidReturns)
                    : null,
            ], $this->returns->forOrder((int) $order->getEntityId())),
            'refunds' => array_map(fn (array $row) => [
                'externalId' => (string) $row['external_id'],
                'decision' => $row['observed']
                    ? $this->wouldHave($this->decisionLabel((string) $row['decision']))
                    : $this->decisionLabel((string) $row['decision']),
                'requested' => (float) $row['requested_amount'],
                'approved' => (float) $row['approved_amount'],
                'currency' => (string) $order->getBaseCurrencyCode(),
                'details' => $this->refundDetails($row),
            ], $this->authorizations->forOrder((int) $order->getEntityId())),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return list<Phrase|string>
     */
    private function refundDetails(array $row): array
    {
        $details = [];
        foreach ($this->categories->toOptionArray() as $option) {
            if ($option['value'] === ($row['category'] ?? null)) {
                $details[] = $option['label'];
            }
        }
        $carrier = ($row['carrier'] ?? null) ? $this->json->unserialize((string) $row['carrier']) : null;
        if (is_array($carrier)) {
            $details[] = $this->carrierSummary->describe($carrier);
        }
        if (($row['decision'] ?? null) === 'hold' && ($row['hold_until'] ?? null)) {
            $details[] = __('On hold until %1.', substr((string) $row['hold_until'], 0, 10));
        }
        if (($row['note'] ?? '') !== '') {
            $details[] = ($row['decided_by'] ?? '') !== ''
                ? sprintf('%s: %s', $row['decided_by'], $row['note'])
                : (string) $row['note'];
        }
        $rejected = (int) ($row['override_rejected'] ?? 0);
        if ($rejected === 1 || $rejected === 409) {
            $details[] = __('Not sent to ReturnSentry: decision already final.');
        } elseif ($rejected === 401 || $rejected === 403) {
            $details[] = __(
                'Not sent to ReturnSentry yet: it refused the secret key (HTTP %1). Sent again once the key is fixed.',
                $rejected
            );
        } elseif ($rejected > 0) {
            $details[] = __(
                'Not sent to ReturnSentry: it refused the override (HTTP %1). Record it in the console.',
                $rejected
            );
        }
        return $details;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forCustomer(): ?array
    {
        try {
            $customer = $this->customers->getById((int) $this->request->getParam('id'));
        } catch (NoSuchEntityException) {
            return null;
        }
        $storeId = (int) $customer->getStoreId();
        if (!$this->config->isConnected($storeId)) {
            return null;
        }
        $externalId = $this->identity->customerExternalId((int) $customer->getId());
        return ['risk' => $this->lookup(['externalId' => $externalId], $storeId)];
    }

    /**
     * @param array<string, string> $reference
     * @return array<string, mixed>
     */
    private function lookup(array $reference, int $storeId): array
    {
        $query = array_intersect_key($reference, ['externalId' => true])
            ?: array_intersect_key($reference, ['email' => true]);
        if ($query === []) {
            return ['status' => 'unknown'];
        }
        try {
            $timeoutMs = $this->config->checkoutTimeoutMs($storeId);
            $response = $this->client->get('/v1/customers/lookup', $query, $storeId, $timeoutMs);
        } catch (ApiException $exception) {
            $this->logger->warning('ReturnSentry lookup failed', ['error' => $exception->getMessage()]);
            return ['status' => 'unavailable'];
        }
        $customer = is_array($response['customers'] ?? null) ? ($response['customers'][0] ?? null) : null;
        if (!is_array($customer)) {
            return ['status' => 'unknown'];
        }
        $reasons = array_filter(array_map(
            static fn ($reason) => is_array($reason) ? (string) ($reason['label'] ?? '') : '',
            is_array($customer['reasons'] ?? null) ? $customer['reasons'] : []
        ));
        return [
            'status' => 'known',
            'level' => $this->riskLevels->label((string) ($customer['level'] ?? '')),
            'score' => (int) ($customer['score'] ?? 0),
            'reasons' => array_values($reasons),
            'url' => $this->config->apiUrl($storeId) . '/customers/' . rawurlencode((string) ($customer['id'] ?? '')),
        ];
    }

    private function wouldHave(Phrase|string $decision): Phrase
    {
        return __('would have: %1', $decision);
    }

    private function checkoutActionLabel(string $action): Phrase|string
    {
        return match ($action) {
            'allow' => __('Allow'),
            'review' => __('Review'),
            'restrict_payment' => __('Restricted payment'),
            'block' => __('Blocked'),
            default => $action,
        };
    }

    private function returnActionLabel(string $action): Phrase|string
    {
        return match ($action) {
            'allow' => __('Usual conditions'),
            'paid_return' => __('Paid return'),
            'inspect_before_refund' => __('Inspect before refund'),
            'statutory_only' => __('Legal withdrawal only'),
            default => $action,
        };
    }

    private function returnActionMeaning(string $action, bool $paidReturns): Phrase|string
    {
        return match ($action) {
            'allow' => __('Return on your usual conditions.'),
            'paid_return' => __('The customer pays the direct cost of the label, if announced before the order.'),
            'inspect_before_refund' => __('Refund once the parcel has been inspected, within the legal deadline.'),
            'statutory_only' => $paidReturns
                ? __('Legal withdrawal only, always accepted: 14 days, return at own cost, inspection before refund.')
                : __('Legal withdrawal only, always accepted: 14 days, free return, inspection before refund.'),
            default => '',
        };
    }

    private function decisionLabel(string $decision): Phrase|string
    {
        return match ($decision) {
            'approve' => __('Approved'),
            'approve_partial' => __('Partially approved'),
            'hold' => __('On hold'),
            'deny' => __('Denied'),
            AuthorizationStore::RELEASED => __('Refunded unchecked, ReturnSentry unavailable'),
            AuthorizationStore::MISCONFIGURED => __(
                'Refunded unchecked, ReturnSentry refused the secret key or the URL'
            ),
            AuthorizationStore::OVERRIDDEN => __('Refunded despite ReturnSentry'),
            default => $decision,
        };
    }
}
