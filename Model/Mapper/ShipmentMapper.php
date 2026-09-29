<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Mapper;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\Item;
use Magento\Sales\Model\Order\Shipment\Track;

class ShipmentMapper
{
    private const MAX_ITEMS = 500;

    public function __construct(
        private readonly OrderMapper $orderMapper,
        private readonly Carrier $carrier,
        private readonly Weight $weight
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toRecords(Shipment $shipment, Order $order): array
    {
        if ($this->orderMapper->hasErasedCustomer($order)) {
            return [];
        }
        $tracks = array_values(array_filter(
            $shipment->getAllTracks(),
            static fn ($track) => $track instanceof Track && trim((string) $track->getTrackNumber()) !== ''
        ));
        $whole = count($tracks) === 1;
        return array_map(fn (Track $track) => Fields::compact([
            'trackingNumber' => Fields::clip((string) $track->getTrackNumber(), 100),
            'carrier' => $this->carrier->standard($track->getCarrierCode(), $track->getTitle()),
            'carrierName' => Fields::clip((string) ($track->getTitle() ?: $track->getCarrierCode()), 100),
            'orderExternalId' => $order->getIncrementId(),
            'declaredWeightGrams' => $whole
                ? $this->weight->grams($shipment->getTotalWeight(), $order->getStoreId())
                : null,
            'items' => $whole ? $this->items($shipment, $order) : null,
            'shippedAt' => Fields::iso($shipment->getCreatedAt()),
        ]), $tracks);
    }

    /**
     * @return list<array{sku: string, quantity: int}>
     */
    private function items(Shipment $shipment, Order $order): array
    {
        $items = [];
        foreach ($shipment->getAllItems() as $item) {
            $sku = $item instanceof Item ? Fields::clip($item->getSku(), 200) : null;
            $quantity = (int) $item->getQty();
            if ($sku === null || $quantity <= 0 || $order->getItemById($item->getOrderItemId())?->getParentItemId()) {
                continue;
            }
            $items[] = ['sku' => $sku, 'quantity' => $quantity];
        }
        return array_slice($items, 0, self::MAX_ITEMS);
    }
}
