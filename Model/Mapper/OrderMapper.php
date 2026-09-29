<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Mapper;

use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item;
use Magento\Store\Model\StoreManagerInterface;

class OrderMapper
{
    private const UNCONFIRMED_STATES = [Order::STATE_PENDING_PAYMENT, Order::STATE_PAYMENT_REVIEW];
    private const DELIVERED_STATUSES = ['complete', 'delivered', 'partially_delivered'];

    public function __construct(
        private readonly Identity $identity,
        private readonly ProductSkus $productSkus,
        private readonly StoreManagerInterface $storeManager,
        private readonly Weight $weight
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function toRecord(Order $order): ?array
    {
        if (in_array($order->getState(), self::UNCONFIRMED_STATES, true) || $this->hasErasedCustomer($order)) {
            return null;
        }
        $storeId = (int) $order->getStoreId();
        $items = $order->getAllVisibleItems();
        $parentSkus = $this->productSkus->forIds(
            array_map(static fn (Item $item) => (int) $item->getProductId(), $items)
        );
        $payment = $order->getPayment();
        return Fields::compact([
            'externalId' => $order->getIncrementId(),
            'customer' => $this->customerRef($order),
            'placedAt' => Fields::iso($order->getCreatedAt()),
            'amount' => Fields::money($order->getBaseGrandTotal()),
            'currency' => $order->getOrderCurrencyCode(),
            'paidAmount' => Fields::money($order->getGrandTotal()),
            'discountAmount' => Fields::money(abs((float) $order->getBaseDiscountAmount())),
            'status' => $this->status(
                (string) $order->getState(),
                (string) $order->getStatus(),
                $order->hasShipments()
            ),
            'channel' => Fields::clip($this->storeManager->getStore($storeId)->getCode(), 60),
            'shippingMethod' => Fields::clip($order->getShippingMethod(), 120),
            'paymentMethod' => Fields::clip($payment?->getMethod(), 60),
            'paymentFingerprint' => $this->paymentFingerprint($payment),
            'ip' => $this->identity->isPseudonymized($storeId) ? null : Fields::clip($order->getRemoteIp(), 64),
            'visitorId' => Fields::clip((string) $order->getData('returnsentry_visitor_id'), 100),
            'items' => array_map(fn (Item $item) => $this->item($item, $parentSkus, $storeId), $items),
        ] + $this->identity->address($order->getShippingAddress() ?: null, $storeId));
    }

    public function paidInBaseCurrency(Order $order): bool
    {
        return $order->getOrderCurrencyCode() === $order->getBaseCurrencyCode();
    }

    public function hasErasedCustomer(Order $order): bool
    {
        return (bool) $order->getData('returnsentry_erased')
            || ($order->getCustomerIsGuest() !== null && !$order->getCustomerIsGuest() && !$order->getCustomerId());
    }

    /**
     * @return array<string, string>
     */
    public function customerRef(Order $order): array
    {
        $address = $order->getShippingAddress() ?: $order->getBillingAddress();
        return $this->identity->customer(
            (int) $order->getCustomerId() ?: null,
            $order->getCustomerEmail(),
            $address?->getTelephone(),
            $order->getStoreId()
        );
    }

    public function status(string $state, string $status, bool $shipped): string
    {
        if ($state === Order::STATE_CANCELED || ($state === Order::STATE_CLOSED && !$shipped)) {
            return 'cancelled';
        }
        if (!$shipped) {
            return 'placed';
        }
        return in_array($status, self::DELIVERED_STATUSES, true) ? 'delivered' : 'shipped';
    }

    public function lineAmount(OrderItemInterface $item): float
    {
        return Fields::money((float) $item->getBaseRowTotalInclTax() - (float) $item->getBaseDiscountAmount());
    }

    private function paymentFingerprint(?OrderPaymentInterface $payment): ?string
    {
        $last4 = (string) $payment?->getCcLast4();
        if ($payment === null || $last4 === '') {
            return null;
        }
        $expiry = $payment->getCcExpMonth()
            ? sprintf('%02d%s', (int) $payment->getCcExpMonth(), substr((string) $payment->getCcExpYear(), -2))
            : '';
        return strtolower(implode(':', [(string) $payment->getCcType() ?: 'card', '', $last4, $expiry]));
    }

    /**
     * @param array<int, string> $parentSkus
     * @return array<string, mixed>
     */
    private function item(Item $item, array $parentSkus, int $storeId): array
    {
        return Fields::compact([
            'sku' => Fields::clip($item->getSku(), 200) ?? (string) $item->getProductId(),
            'productId' => Fields::clip($parentSkus[(int) $item->getProductId()] ?? null, 200),
            'name' => Fields::clip($item->getName(), 300),
            'quantity' => max(1, (int) $item->getQtyOrdered()),
            'amount' => $this->lineAmount($item),
            'weightGrams' => $this->weight->grams($item->getWeight(), $storeId),
        ]);
    }
}
