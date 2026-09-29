<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Api\Data;

class ReturnItem
{
    public function __construct(
        public readonly int $orderItemId,
        public readonly int $quantity = 1,
        public readonly ?string $reason = null
    ) {
    }
}
