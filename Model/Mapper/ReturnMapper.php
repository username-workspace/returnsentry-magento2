<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Mapper;

use Magento\Sales\Model\Order;
use Magento\Sales\Api\Data\OrderItemInterface;
use ReturnSentry\Connector\Api\Data\ReturnRequest;
use ReturnSentry\Connector\Model\Config;
use ReturnSentry\Connector\Model\Source\ReturnReason;

class ReturnMapper
{
    public const RETURN_TO_SENDER = 'return_to_sender';
    private const MAX_SHIPMENTS = 20;
    private const RMA_STATUSES = [
        'pending' => 'requested',
        'unconfirmed' => 'requested',
        'authorized' => 'requested',
        'partially_authorized' => 'requested',
        'received' => 'received',
        'received_on_item' => 'received',
        'approved' => 'received',
        'approved_on_item' => 'received',
        'processed_closed' => 'refunded',
        'rejected' => 'rejected',
        'rejected_on_item' => 'rejected',
        'denied' => 'rejected',
        'closed' => 'cancelled',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly OrderMapper $orderMapper,
        private readonly Carrier $carrier,
        private readonly ReturnReason $reasons
    ) {
    }

    public function reason(?string $label, int|string|null $storeId): string
    {
        $normalized = $this->config->normalizeLabel((string) $label);
        if ($normalized === '') {
            return 'unknown';
        }
        $standard = array_column($this->reasons->toOptionArray(), 'value');
        if (in_array($normalized, $standard, true)) {
            return $normalized;
        }
        $mapped = $this->config->returnReasonMap($storeId)[$normalized] ?? 'unknown';
        return in_array($mapped, $standard, true) ? $mapped : 'unknown';
    }

    /**
     * @param array<string,mixed> $rma
     * @param list<array{orderItemId:int,sku:?string,quantity:int,reason:?string}> $items
     * @param list<array{number:string,code:?string,title:?string}> $tracks
     * @return array<string, mixed>
     */
    public function fromRma(array $rma, array $items, array $tracks, Order $order): array
    {
        $shipments = array_map(
            fn (array $track) => $this->shipment($track['number'], $track['code'], $track['title']),
            $tracks
        );
        return $this->build(
            'rma:' . $rma['increment_id'],
            $order,
            self::RMA_STATUSES[(string) $rma['status']] ?? 'requested',
            (string) Fields::iso((string) $rma['date_requested']),
            Fields::iso(isset($rma['received_at']) ? (string) $rma['received_at'] : null),
            null,
            $items,
            $shipments,
            false
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function fromRequest(ReturnRequest $request, Order $order): array
    {
        $items = array_map(
            static fn ($item) => [
                'orderItemId' => $item->orderItemId,
                'sku' => null,
                'quantity' => $item->quantity,
                'reason' => $item->reason,
            ],
            $request->items
        );
        $returnToSender = $this->config->normalizeLabel((string) $request->reason) === self::RETURN_TO_SENDER
            && $this->orderMapper->paidInBaseCurrency($order);
        return $this->build(
            $request->externalId,
            $order,
            $request->status,
            (string) Fields::isoFrom($request->requestedAt),
            Fields::isoFrom($request->receivedAt),
            $request->reason,
            $items,
            array_map(
                fn ($shipment) => $this->shipment($shipment->trackingNumber, $shipment->carrier),
                $request->shipments
            ),
            $returnToSender
        );
    }

    /**
     * @param list<array{orderItemId:int,sku:?string,quantity:int,reason:?string}> $items
     * @param array<array<string,string>> $shipments
     * @return array<string, mixed>
     */
    private function build(
        string $externalId,
        Order $order,
        string $status,
        string $requestedAt,
        ?string $receivedAt,
        ?string $reason,
        array $items,
        array $shipments,
        bool $returnToSender
    ): array {
        $storeId = $order->getStoreId();
        $lines = [];
        foreach ($order->getAllItems() as $line) {
            $lines[(int) $line->getItemId()] = $line;
        }
        $returnItems = [];
        $reasons = [];
        foreach ($items as $item) {
            if ($item['quantity'] <= 0) {
                continue;
            }
            $line = $lines[$item['orderItemId']] ?? null;
            $itemReason = $this->reason($item['reason'] ?? $reason, $storeId);
            $reasons[] = $itemReason;
            $returnItems[] = Fields::compact([
                'sku' => Fields::clip($item['sku'] ?? $line?->getSku(), 200) ?? 'unknown',
                'quantity' => $item['quantity'],
                'amount' => $line === null ? null : Fields::money($this->unitAmount($line) * $item['quantity']),
                'reason' => $itemReason,
                'reasonText' => Fields::clip($item['reason'] ?? $reason, 300),
            ]);
        }
        $amounts = array_column($returnItems, 'amount');
        return Fields::compact([
            'externalId' => $externalId,
            'orderExternalId' => $order->getIncrementId(),
            'customer' => $this->orderMapper->customerRef($order),
            'kind' => $returnToSender ? self::RETURN_TO_SENDER : 'customer_return',
            'status' => $status,
            'requestedAt' => $requestedAt,
            'receivedAt' => $receivedAt,
            'reason' => $this->dominant($reasons === [] ? [$this->reason($reason, $storeId)] : $reasons),
            'reasonText' => Fields::clip($reason, 300),
            'amount' => $amounts === [] ? null : Fields::money(array_sum($amounts)),
            'items' => $returnItems,
            'shipments' => array_slice(array_values(array_filter($shipments)), 0, self::MAX_SHIPMENTS),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function shipment(string $trackingNumber, ?string ...$carrierLabels): array
    {
        $trackingNumber = Fields::clip($trackingNumber, 100);
        return $trackingNumber === null
            ? []
            : ['trackingNumber' => $trackingNumber, 'carrier' => $this->carrier->standard(...$carrierLabels)];
    }

    private function unitAmount(OrderItemInterface $line): float
    {
        $source = $line->getParentItem() ?? $line;
        return $this->orderMapper->lineAmount($source) / max(1, (float) $source->getQtyOrdered());
    }

    /**
     * @param string[] $reasons
     */
    public function dominant(array $reasons): string
    {
        $counts = array_count_values(array_diff($reasons, ['unknown']));
        if ($counts === []) {
            return 'unknown';
        }
        arsort($counts);
        return (string) array_key_first($counts);
    }
}
