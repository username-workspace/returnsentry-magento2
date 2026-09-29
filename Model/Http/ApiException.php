<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Http;

class ApiException extends \RuntimeException
{
    private const REFUSED_CREDENTIALS = [401, 403];

    public function __construct(
        string $message,
        public readonly bool $transient,
        public readonly int $status = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function retryable(): bool
    {
        return $this->transient || in_array($this->status, self::REFUSED_CREDENTIALS, true);
    }

    public function refusesKeyOrUrl(): bool
    {
        return in_array($this->status, [401, 403, 404], true);
    }
}
