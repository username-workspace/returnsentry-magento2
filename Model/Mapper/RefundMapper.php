<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Mapper;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use ReturnSentry\Connector\Model\Config;
use ReturnSentry\Connector\Model\Source\RefundCategory;

class RefundMapper
{
    private const MAX_ITEMS = 500;

    public function __construct(
        private readonly Config $config,
        private readonly OrderMapper $orderMapper,
        private readonly RefundCategory $categories
    ) {
    }

    /**
     * @param array<string, mixed>|null $return
     * @return array<string, mixed>
     */
    public function toRecord(Creditmemo $creditmemo, Order $order, ?array $return): array
    {
        return Fields::compact([
            'externalId' => 'cm:' . $creditmemo->getIncrementId(),
            'orderExternalId' => $order->getIncrementId(),
            'returnExternalId' => $return['externalId'] ?? null,
            'customer' => $this->orderMapper->customerRef($order),
            'refundedAt' => Fields::iso($creditmemo->getCreatedAt()),
            'amount' => Fields::money($creditmemo->getBaseGrandTotal()),
            'category' => $this->category($creditmemo, $order, $return),
            'reasonText' => Fields::clip($this->code($creditmemo, $order->getStoreId()), 300),
        ]);
    }

    /**
     * @param array<string, mixed>|null $return
     */
    public function category(Creditmemo $creditmemo, Order $order, ?array $return): string
    {
        $category = $this->mappedCategory($creditmemo, $order, $return);
        return match (true) {
            $category === 'return_to_sender' && !$this->orderMapper->paidInBaseCurrency($order),
            $category === 'return_not_received_dispute' && $return === null => 'return',
            default => $category,
        };
    }

    /**
     * @param array<string, mixed>|null $return
     */
    private function mappedCategory(Creditmemo $creditmemo, Order $order, ?array $return): string
    {
        $storeId = $order->getStoreId();
        $code = $this->code($creditmemo, $storeId);
        if ($code !== null) {
            $codes = $this->config->refundCodeMap($storeId);
            return $this->known($codes[$this->config->normalizeLabel($code)] ?? 'other');
        }
        if ($return !== null) {
            return ($return['kind'] ?? null) === ReturnMapper::RETURN_TO_SENDER
                ? 'return_to_sender'
                : $this->known($this->config->refundCategoryFor('with_return', $storeId));
        }
        $refundedQuantity = 0.0;
        foreach ($creditmemo->getAllItems() as $item) {
            $refundedQuantity += (float) $item->getQty();
        }
        $situation = match (true) {
            $refundedQuantity <= 0 => 'adjustment_only',
            $order->hasShipments() => 'shipped',
            default => 'not_shipped',
        };
        return $this->known($this->config->refundCategoryFor($situation, $storeId));
    }

    /**
     * @return string[]
     */
    public function refundedSkus(Creditmemo $creditmemo): array
    {
        $skus = [];
        foreach ($creditmemo->getAllItems() as $item) {
            if ((float) $item->getQty() > 0 && (string) $item->getSku() !== '') {
                $skus[] = (string) $item->getSku();
            }
        }
        return array_values(array_unique($skus));
    }

    public function refundedLines(Creditmemo $creditmemo): string
    {
        $quantities = [];
        foreach ($creditmemo->getAllItems() as $item) {
            if ((float) $item->getQty() > 0) {
                $orderItemId = (int) $item->getOrderItemId();
                $quantities[$orderItemId] = ($quantities[$orderItemId] ?? 0.0) + (float) $item->getQty();
            }
        }
        ksort($quantities);
        return implode('+', array_map(
            static fn (int $orderItemId, float $quantity) => $orderItemId . 'x' . $quantity,
            array_keys($quantities),
            $quantities
        ));
    }

    /**
     * @return list<array{sku: string, quantity: int}>
     */
    public function refundedItems(Creditmemo $creditmemo, Order $order): array
    {
        $quantities = [];
        foreach ($creditmemo->getAllItems() as $item) {
            $sku = Fields::clip((string) $item->getSku(), 200);
            $quantity = (int) $item->getQty();
            if ($sku === null || $quantity <= 0 || $order->getItemById($item->getOrderItemId())?->getParentItemId()) {
                continue;
            }
            $quantities[$sku] = ($quantities[$sku] ?? 0) + $quantity;
        }
        $items = [];
        foreach ($quantities as $sku => $quantity) {
            $items[] = ['sku' => (string) $sku, 'quantity' => $quantity];
        }
        return array_slice($items, 0, self::MAX_ITEMS);
    }

    private function code(Creditmemo $creditmemo, int|string|null $storeId): ?string
    {
        $field = $this->config->refundCodeField($storeId);
        return $field === '' ? null : Fields::clip((string) $creditmemo->getData($field), 300);
    }

    private function known(string $category): string
    {
        $standard = array_column($this->categories->toOptionArray(), 'value');
        return in_array($category, $standard, true) ? $category : 'other';
    }
}
