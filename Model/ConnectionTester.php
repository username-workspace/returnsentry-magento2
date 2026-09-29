<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model;

use Magento\Framework\Phrase;
use ReturnSentry\Connector\Model\Http\ApiException;
use ReturnSentry\Connector\Model\Http\Client;
use ReturnSentry\Connector\Model\System\RefundControlAlert;

class ConnectionTester
{
    public function __construct(
        private readonly Config $config,
        private readonly Client $client,
        private readonly RefundControlAlert $refundControl
    ) {
    }

    /**
     * @return array{success: bool, message: Phrase}
     */
    public function test(?int $storeId): array
    {
        if ($this->config->apiUrl($storeId) === '' || $this->config->secretKey($storeId) === '') {
            return ['success' => false, 'message' => __('Save the ReturnSentry URL and the secret key first.')];
        }
        try {
            $this->client->get('/v1/customers/lookup', ['externalId' => 'returnsentry-connection-test'], $storeId);
        } catch (ApiException $exception) {
            return ['success' => false, 'message' => match (true) {
                $exception->status === 401, $exception->status === 403 => __('ReturnSentry refused the secret key.'),
                $exception->status === 0 => __(
                    'ReturnSentry cannot be reached at this URL: %1',
                    $exception->getMessage()
                ),
                $exception->status < 300 => __(
                    'This URL does not answer like ReturnSentry. Enter the address of your instance, such as %1.',
                    'https://returnsentry.example.com'
                ),
                default => __('ReturnSentry answered with an error: %1', $exception->getMessage()),
            }];
        }
        $this->refundControl->clear($storeId);
        if (!str_starts_with($this->config->publishableKey($storeId), 'rs_pk_')) {
            return [
                'success' => true,
                'message' => __('Connected. Add the publishable key (rs_pk_...) to run the beacon.'),
            ];
        }
        return ['success' => true, 'message' => __('Connected to ReturnSentry.')];
    }
}
