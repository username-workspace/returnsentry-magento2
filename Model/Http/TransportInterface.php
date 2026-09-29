<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Http;

interface TransportInterface
{
    /**
     * @param array<string, string> $headers
     * @throws TransportException
     */
    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): Response;
}
