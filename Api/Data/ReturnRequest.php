<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Api\Data;

class ReturnRequest
{
    public const STATUSES = ['requested', 'in_transit', 'received', 'refunded', 'rejected', 'cancelled'];

    /**
     * @param ReturnItem[] $items
     * @param ReturnShipment[] $shipments
     */
    public function __construct(
        public readonly string $externalId,
        public readonly int $orderId,
        public readonly \DateTimeInterface $requestedAt,
        public readonly array $items = [],
        public readonly string $status = 'requested',
        public readonly ?string $reason = null,
        public readonly ?\DateTimeInterface $receivedAt = null,
        public readonly array $shipments = []
    ) {
    }
}
