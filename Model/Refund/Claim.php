<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Refund;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Shipment;
use ReturnSentry\Connector\Model\Mapper\Fields;
use ReturnSentry\Connector\Model\Mapper\RefundMapper;

class Claim
{
    public const OUTBOUND = ['item_not_received', 'empty_package_claim', 'missing_items_claim'];
    public const RETURN_DISPUTE = 'return_not_received_dispute';
    private const MISSING_ITEMS = 'missing_items_claim';

    public function __construct(
        private readonly RefundMapper $refundMapper,
        private readonly AuthorizationStore $authorizations
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function fields(string $category, string $externalId, Creditmemo $creditmemo, Order $order): array
    {
        if ($category !== self::RETURN_DISPUTE && !in_array($category, self::OUTBOUND, true)) {
            return [];
        }
        $items = $this->refundMapper->refundedItems($creditmemo, $order);
        return Fields::compact([
            'category' => $category,
            'claimedAt' => Fields::iso($this->authorizations->claimedAt($externalId) ?? gmdate('Y-m-d H:i:s')),
            'trackingNumber' => $category === self::RETURN_DISPUTE
                ? null
                : $this->trackingNumber($order, array_column($items, 'sku')),
            'items' => $category === self::MISSING_ITEMS ? $items : null,
        ]);
    }

    /**
     * @param string[] $skus
     */
    private function trackingNumber(Order $order, array $skus): ?string
    {
        $numbers = [];
        $shipments = $order->getShipmentsCollection();
        foreach ($shipments ? $shipments->getItems() : [] as $shipment) {
            if (!$shipment instanceof Shipment) {
                continue;
            }
            $shipped = array_map(static fn ($item) => (string) $item->getSku(), $shipment->getAllItems());
            if ($skus !== [] && array_intersect($skus, $shipped) === []) {
                continue;
            }
            foreach ($shipment->getAllTracks() as $track) {
                $numbers[] = trim((string) $track->getTrackNumber());
            }
        }
        $numbers = array_values(array_unique(array_filter($numbers)));
        return count($numbers) === 1 ? Fields::clip($numbers[0], 100) : null;
    }
}
