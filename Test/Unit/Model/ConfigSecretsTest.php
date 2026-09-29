<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model;

use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Model\Config;
use ReturnSentry\Connector\Model\Http\Client;
use ReturnSentry\Connector\Model\Http\Response;
use ReturnSentry\Connector\Model\Http\TransportInterface;
use ReturnSentry\Connector\Test\Unit\Support\MagentoConfig;

class ConfigSecretsTest extends TestCase
{
    use MagentoConfig;

    public function testTheSecretKeyReachesTheApiAsTheAdminTypedIt(): void
    {
        $config = new Config($this->magentoScopeConfig([
            'returnsentry/general/enabled' => '1',
            'returnsentry/general/api_url' => 'https://rs.example.com',
            'returnsentry/general/secret_key' => 'rs_sk_live_0123456789',
        ]), new Json());
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects($this->once())
            ->method('send')
            ->with('GET', $this->anything(), $this->callback(
                static fn (array $headers) => $headers['Authorization'] === 'Bearer rs_sk_live_0123456789'
            ))
            ->willReturn(new Response(200, '{"customers":[]}'));

        (new Client($config, $transport, new Json()))->get('/v1/customers/lookup', ['externalId' => 'x'], 1);

        $this->assertSame('rs_sk_live_0123456789', $config->secretKey(1));
        $this->assertTrue($config->isConnected(1));
    }

    public function testThePseudonymizationSecretIsServedInClear(): void
    {
        $salt = str_repeat('s', 40);
        $config = new Config($this->magentoScopeConfig([
            'returnsentry/general/enabled' => '1',
            'returnsentry/general/api_url' => 'https://rs.example.com',
            'returnsentry/general/secret_key' => 'rs_sk_live_0123456789',
            'returnsentry/privacy/pseudonymize' => '1',
            'returnsentry/privacy/salt' => $salt,
        ]), new Json());

        $this->assertSame($salt, $config->pseudonymSalt(1));
    }
}
