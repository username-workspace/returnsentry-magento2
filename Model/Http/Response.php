<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Http;

class Response
{
    public function __construct(
        public readonly int $status,
        public readonly string $body
    ) {
    }
}
