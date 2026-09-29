<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Api\Data;

class ReturnShipment
{
    public function __construct(
        public readonly string $trackingNumber,
        public readonly ?string $carrier = null
    ) {
    }
}
