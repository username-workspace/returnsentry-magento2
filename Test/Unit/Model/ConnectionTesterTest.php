<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model;

use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Model\Config;
use ReturnSentry\Connector\Model\ConnectionTester;
use ReturnSentry\Connector\Model\Http\Client;
use ReturnSentry\Connector\Model\Http\Response;
use ReturnSentry\Connector\Model\Http\TransportInterface;
use ReturnSentry\Connector\Model\System\RefundControlAlert;

class ConnectionTesterTest extends TestCase
{
    public function testAPageThatIsNotTheApiIsNotAConnection(): void
    {
        $alert = $this->createMock(RefundControlAlert::class);
        $alert->expects($this->never())->method('clear');

        $result = $this->test(new Response(200, '<!doctype html><div id="root"></div>'), $alert);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('does not answer like ReturnSentry', (string) $result['message']);
    }

    public function testTheLookupAnswerIsAConnection(): void
    {
        $alert = $this->createMock(RefundControlAlert::class);
        $alert->expects($this->once())->method('clear')->with(1);

        $result = $this->test(new Response(200, '{"customers":[]}'), $alert);

        $this->assertTrue($result['success']);
        $this->assertSame('Connected to ReturnSentry.', (string) $result['message']);
    }

    /**
     * @return array{success: bool, message: \Magento\Framework\Phrase}
     */
    private function test(Response $response, RefundControlAlert $alert): array
    {
        $config = $this->createMock(Config::class);
        $config->method('apiUrl')->willReturn('https://rs.example.com');
        $config->method('secretKey')->willReturn('rs_sk_test');
        $config->method('publishableKey')->willReturn('rs_pk_test');
        $config->method('timeoutMs')->willReturn(3000);
        $transport = $this->createMock(TransportInterface::class);
        $transport->method('send')->willReturn($response);
        return (new ConnectionTester($config, new Client($config, $transport, new Json()), $alert))->test(1);
    }
}
