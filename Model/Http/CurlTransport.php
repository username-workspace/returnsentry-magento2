<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Http;

use Magento\Framework\HTTP\Client\CurlFactory;

class CurlTransport implements TransportInterface
{
    public function __construct(private readonly CurlFactory $curlFactory)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): Response
    {
        $curl = $this->curlFactory->create();
        $curl->setHeaders($headers);
        $options = [
            CURLOPT_NOSIGNAL => 1,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
        ];
        if ($method !== 'GET' && $method !== 'POST') {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
        }
        $curl->setOptions($options);
        try {
            if ($method === 'POST') {
                $curl->post($url, $body ?? '');
            } else {
                $curl->get($url);
            }
        } catch (\Exception $exception) {
            throw new TransportException($exception->getMessage(), 0, $exception);
        }
        return new Response($curl->getStatus(), $curl->getBody());
    }
}
