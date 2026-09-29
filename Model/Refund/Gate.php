<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Refund;

use Magento\Framework\Phrase;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Psr\Log\LoggerInterface;
use ReturnSentry\Connector\Exception\RefundStoppedException;
use ReturnSentry\Connector\Model\Config;
use ReturnSentry\Connector\Model\Http\ApiException;
use ReturnSentry\Connector\Model\Http\Client;
use ReturnSentry\Connector\Model\Mapper\Fields;
use ReturnSentry\Connector\Model\Mapper\OrderMapper;
use ReturnSentry\Connector\Model\Mapper\RefundMapper;
use ReturnSentry\Connector\Model\Returns\ReturnStore;
use ReturnSentry\Connector\Model\Sync\Publisher;
use ReturnSentry\Connector\Model\Sync\Sender;
use ReturnSentry\Connector\Model\System\RefundControlAlert;

class Gate
{
    private const NEUTRAL_CATEGORIES = ['defective_product', 'merchant_error', 'commercial_gesture', 'other'];
    private const MAX_NAMED_LINES = 120;

    private ?RefundStoppedException $stopped = null;

    public function __construct(
        private readonly Config $config,
        private readonly Client $client,
        private readonly RefundMapper $refundMapper,
        private readonly OrderMapper $orderMapper,
        private readonly ReturnStore $returns,
        private readonly AuthorizationStore $authorizations,
        private readonly Override $override,
        private readonly Claim $claim,
        private readonly CarrierSummary $carrierSummary,
        private readonly Publisher $publisher,
        private readonly RefundControlAlert $refundControl,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @throws RefundStoppedException
     */
    public function authorize(Creditmemo $creditmemo, Order $order): void
    {
        $this->stopped = null;
        $storeId = (int) $order->getStoreId();
        $mode = $this->config->mode(Config::REFUND_AUTHORIZATION, $storeId);
        if ($mode === Config::OFF
            || $this->config->isExcludedPaymentMethod((string) $order->getPayment()?->getMethod(), $storeId)
            || $this->orderMapper->hasErasedCustomer($order)
            || $creditmemo->getData('payment_refund_disallowed')
            || $creditmemo->getData('automatically_created')
        ) {
            return;
        }
        $orderId = (int) $order->getEntityId();
        $return = $this->returns->refundedBy($orderId, $this->refundMapper->refundedSkus($creditmemo));
        $category = $this->refundMapper->category($creditmemo, $order, $return);
        if (in_array($category, self::NEUTRAL_CATEGORIES, true)) {
            return;
        }
        if (in_array($category, Claim::OUTBOUND, true)) {
            $return = null;
        }
        $externalId = $this->authorizationId($creditmemo, $order);
        $amount = Fields::money($creditmemo->getBaseGrandTotal());
        $requested = Fields::money($amount + (float) $creditmemo->getBaseAdjustmentNegative());
        $claim = $this->claim->fields($category, $externalId, $creditmemo, $order);
        $refused = null;
        try {
            $decision = $this->decide($externalId, $order, $return, $claim, $requested, $mode);
            $this->refundControl->clear($storeId);
        } catch (ApiException $exception) {
            $decision = null;
            $refused = $this->failed($exception, $order, $mode);
        }
        if ($decision === null) {
            if ($mode === Config::ENFORCE) {
                $this->withoutAnswer($externalId, $order, $amount, $storeId, $claim !== [], $refused);
            }
            return;
        }
        $this->authorizations->save(
            $externalId,
            $orderId,
            $decision,
            $mode === Config::OBSERVE,
            $claim['category'] ?? null,
            $claim['claimedAt'] ?? null
        );
        if ($decision->allows($amount)) {
            return;
        }
        if ($mode === Config::OBSERVE) {
            $this->logger->info('ReturnSentry would have stopped this credit memo', [
                'order' => $order->getIncrementId(),
                'decision' => $decision->decision,
                'approvedAmount' => $decision->approvedAmount,
            ]);
            return;
        }
        $currency = (string) $order->getBaseCurrencyCode();
        $this->stop($externalId, $order, $amount, new RefundStoppedException(
            $this->explain($decision, $amount, $currency, $storeId),
            $decision->decision,
            $decision->reasons,
            Fields::iso($decision->holdUntil),
            $decision->evidenceUrl
        ));
    }

    public function takeStop(): ?RefundStoppedException
    {
        [$stopped, $this->stopped] = [$this->stopped, null];
        return $stopped;
    }

    private function authorizationId(Creditmemo $creditmemo, Order $order): string
    {
        $lines = $this->refundMapper->refundedLines($creditmemo);
        $created = $order->getCreditmemosCollection();
        $repeats = 0;
        foreach ($created ? $created->getItems() : [] as $earlier) {
            if ($earlier instanceof Creditmemo && $this->refundMapper->refundedLines($earlier) === $lines) {
                $repeats++;
            }
        }
        $name = match (true) {
            $lines === '' => 'adjustment',
            strlen($lines) > self::MAX_NAMED_LINES => sha1($lines),
            default => $lines,
        };
        return sprintf('ra:%s:%s', $order->getIncrementId(), $repeats === 0 ? $name : $name . ':' . ($repeats + 1));
    }

    /**
     * @param array<string, mixed>|null $return
     * @param array<string, mixed> $claim
     */
    private function decide(
        string $externalId,
        Order $order,
        ?array $return,
        array $claim,
        float $requested,
        string $mode
    ): ?Decision {
        $decision = Decision::fromResponse($this->client->post('/v1/refund-authorizations', Fields::compact([
            'externalId' => $externalId,
            'returnExternalId' => $return['externalId'] ?? null,
            'orderExternalId' => $order->getIncrementId(),
            'customer' => $this->orderMapper->customerRef($order),
            'amount' => $requested,
            'withdrawnAt' => $return['requestedAt'] ?? null,
            'goodsBackAt' => $return['receivedAt'] ?? null,
        ] + $claim + ['mode' => $mode]), (int) $order->getStoreId()));
        if ($decision->degraded) {
            $this->logger->error('ReturnSentry refund authorization degraded', [
                'order' => $order->getIncrementId(),
                'mode' => $mode,
            ]);
            return null;
        }
        return $decision;
    }

    private function failed(ApiException $exception, Order $order, string $mode): ?int
    {
        $refused = $exception->refusesKeyOrUrl() ? $exception->status : null;
        $this->logger->error('ReturnSentry refund authorization failed', [
            'order' => $order->getIncrementId(),
            'mode' => $mode,
            'error' => $exception->getMessage(),
        ]);
        if ($refused !== null) {
            $this->refundControl->raise((int) $order->getStoreId(), $refused);
        }
        return $refused;
    }

    /**
     * @throws RefundStoppedException
     */
    private function withoutAnswer(
        string $externalId,
        Order $order,
        float $amount,
        int $storeId,
        bool $claim,
        ?int $refused
    ): void {
        $own = $this->authorizations->find($externalId);
        $last = $own ?? ($claim ? $this->authorizations->latestForOrder((int) $order->getEntityId()) : null);
        $stored = $this->storedStop($own, $last, $amount, (string) $order->getBaseCurrencyCode());
        if ($stored !== []) {
            $message = $this->sentences([
                ...$stored,
                __('Try again in a few minutes.'),
                $this->overrideHint(),
                $this->consoleLink($storeId),
            ]);
            $this->stop($externalId, $order, $amount, new RefundStoppedException(
                $message,
                (string) ($last['decision'] ?? ''),
                holdUntil: Fields::iso($last['hold_until'] ?? null)
            ));
            return;
        }
        $this->authorizations->record(
            $externalId,
            (int) $order->getEntityId(),
            $refused === null ? AuthorizationStore::RELEASED : AuthorizationStore::MISCONFIGURED,
            $amount,
            $refused === null ? 'ReturnSentry unavailable' : sprintf('HTTP %d', $refused)
        );
        $order->addCommentToStatusHistory((string) ($refused === null
            ? __('ReturnSentry did not answer: this refund went through unchecked.')
            : __('ReturnSentry refused the secret key or the URL: this refund went through unchecked.')));
        $this->logger->warning('ReturnSentry unavailable, credit memo let through', [
            'order' => $order->getIncrementId(),
            'externalId' => $externalId,
        ]);
    }

    /**
     * @throws RefundStoppedException
     */
    private function stop(string $externalId, Order $order, float $amount, RefundStoppedException $stop): void
    {
        $override = $this->override->requested();
        if ($override === null) {
            $this->stopped = $stop;
            throw $stop;
        }
        $this->authorizations->record(
            $externalId,
            (int) $order->getEntityId(),
            AuthorizationStore::OVERRIDDEN,
            $amount,
            $override['reason'],
            $override['decidedBy']
        );
        $this->publisher->publish(Sender::OVERRIDE, $externalId, (int) $order->getStoreId());
        $note = sprintf('%s: %s', $override['decidedBy'], $override['reason']);
        $order->addCommentToStatusHistory((string) __('Refunded despite ReturnSentry, by %1', $note));
        $this->logger->warning('ReturnSentry decision overridden', [
            'order' => $order->getIncrementId(),
            'externalId' => $externalId,
            'note' => $note,
        ]);
    }

    /**
     * @param array<string, mixed>|null $own
     * @param array<string, mixed>|null $last
     * @return Phrase[]
     */
    private function storedStop(?array $own, ?array $last, float $amount, string $currency): array
    {
        $money = static fn (float $value): string => sprintf('%.2f %s', $value, $currency);
        $approved = (float) ($own['approved_amount'] ?? 0);
        return match (true) {
            ($last['decision'] ?? null) === 'hold' && (int) ($last['fraud_evidence'] ?? 0) === 1 => [$own === null
                ? __('ReturnSentry did not answer, and its last decision holds this order on fraud evidence.')
                : __('ReturnSentry did not answer, and its last decision holds this refund on fraud evidence.')],
            ($last['decision'] ?? null) === 'deny' => [$own === null
                ? __('ReturnSentry did not answer, and its last decision on this order is a denial.')
                : __('ReturnSentry did not answer, and its last decision denies this refund.')],
            ($own['decision'] ?? null) === 'approve_partial' && round($amount, 2) > round($approved, 2) => [
                __('ReturnSentry did not answer, and its last decision approves %1 of this refund.', $money($approved)),
                __(
                    'To refund the approved amount, add an adjustment fee of %1 and submit the credit memo again.',
                    $money($amount - $approved)
                ),
            ],
            default => [],
        };
    }

    private function overrideHint(): Phrase
    {
        return __('An administrator allowed to override can refund anyway from the credit memo form, with a reason.');
    }

    private function consoleLink(int $storeId, ?string $decisionId = null): Phrase
    {
        $refunds = $this->config->apiUrl($storeId) . '/refunds';
        return __(
            'An operator can change this decision in the console: %1',
            $decisionId === null ? $refunds : $refunds . '?refund=' . rawurlencode($decisionId)
        );
    }

    private function explain(Decision $decision, float $amount, string $currency, int $storeId): Phrase
    {
        $money = static fn (float $value): string => sprintf('%.2f %s', $value, $currency);
        $parts = match (true) {
            $decision->decision === 'approve_partial' => [
                __(
                    'ReturnSentry approves %1 of the %2 requested.',
                    $money($decision->approvedAmount),
                    $money($decision->requestedAmount)
                ),
                __(
                    'To refund the approved amount, add an adjustment fee of %1 and submit the credit memo again.',
                    $money(max(0.0, $amount - $decision->approvedAmount))
                ),
            ],
            $decision->decision === 'deny' => [__('ReturnSentry denies this refund.')],
            default => [__('ReturnSentry holds this refund.')],
        };
        if ($decision->carrier !== null) {
            $parts[] = $this->carrierSummary->describe($decision->carrier);
        }
        if ($decision->holdUntil !== null) {
            $parts[] = __(
                'Without new evidence, a credit memo submitted from %1 will be approved.',
                substr($decision->holdUntil, 0, 10)
            );
        }
        if ($decision->reasons !== []) {
            $parts[] = __('Reasons: %1.', implode('; ', $decision->reasons));
        }
        if ($decision->evidenceUrl !== null) {
            $parts[] = __('Evidence to share with the customer: %1', $decision->evidenceUrl);
        }
        $parts[] = $this->overrideHint();
        $parts[] = $this->consoleLink($storeId, $decision->id);
        return $this->sentences($parts);
    }

    /**
     * @param Phrase[] $parts
     */
    private function sentences(array $parts): Phrase
    {
        return new Phrase(implode(' ', array_map('strval', $parts)));
    }
}
