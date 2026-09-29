<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Http;

use Magento\Framework\Serialize\Serializer\Json;
use ReturnSentry\Connector\Model\Config;

class Client
{
    public function __construct(
        private readonly Config $config,
        private readonly TransportInterface $transport,
        private readonly Json $json
    ) {
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     * @throws ApiException
     */
    public function post(string $path, array $body, int|string|null $storeId, ?int $timeoutMs = null): array
    {
        return $this->request('POST', $path, $this->json->serialize($body), $storeId, $timeoutMs);
    }

    /**
     * @param array<string, string> $query
     * @return array<string, mixed>
     * @throws ApiException
     */
    public function get(string $path, array $query, int|string|null $storeId, ?int $timeoutMs = null): array
    {
        return $this->request('GET', $path . '?' . http_build_query($query), null, $storeId, $timeoutMs);
    }

    /**
     * @param array<string, string> $query
     * @return array<string, mixed>
     * @throws ApiException
     */
    public function delete(string $path, array $query, int|string|null $storeId): array
    {
        return $this->request('DELETE', $path . '?' . http_build_query($query), null, $storeId, null);
    }

    /**
     * @return array<string, mixed>
     * @throws ApiException
     */
    private function request(
        string $method,
        string $path,
        ?string $body,
        int|string|null $storeId,
        ?int $timeoutMs
    ): array {
        if ($this->config->apiUrl($storeId) === '' || $this->config->secretKey($storeId) === '') {
            throw new ApiException('ReturnSentry is not configured for this store view', true);
        }
        $headers = [
            'Authorization' => 'Bearer ' . $this->config->secretKey($storeId),
            'Accept' => 'application/json',
            'User-Agent' => 'returnsentry-magento2',
        ];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        try {
            $response = $this->transport->send(
                $method,
                $this->config->apiUrl($storeId) . $path,
                $headers,
                $body,
                $timeoutMs ?? $this->config->timeoutMs($storeId)
            );
        } catch (TransportException $exception) {
            throw new ApiException('ReturnSentry is unreachable: ' . $exception->getMessage(), true, 0, $exception);
        }
        $decoded = $this->decode($response->body);
        if ($response->status >= 200 && $response->status < 300) {
            return $decoded ?? throw new ApiException(
                sprintf('ReturnSentry answered HTTP %d without a JSON object: check its URL', $response->status),
                true,
                $response->status
            );
        }
        $message = is_string($decoded['message'] ?? null) ? $decoded['message'] : 'HTTP ' . $response->status;
        throw new ApiException(
            'ReturnSentry refused the request: ' . $message,
            $response->status === 429 || $response->status >= 500,
            $response->status
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $body): ?array
    {
        try {
            $decoded = $this->json->unserialize($body);
        } catch (\InvalidArgumentException) {
            return null;
        }
        return is_array($decoded) && str_starts_with(ltrim($body), '{') ? $decoded : null;
    }
}
