<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model\Http;

use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Model\Config;
use ReturnSentry\Connector\Model\Http\ApiException;
use ReturnSentry\Connector\Model\Http\Client;
use ReturnSentry\Connector\Model\Http\Response;
use ReturnSentry\Connector\Model\Http\TransportException;
use ReturnSentry\Connector\Model\Http\TransportInterface;

class ClientTest extends TestCase
{
    public function testPostsJsonWithTheSecretKeyAndDecodesTheAnswer(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects($this->once())
            ->method('send')
            ->with(
                'POST',
                'https://rs.example.com/v1/assessments',
                $this->callback(static fn (array $headers) => $headers['Authorization'] === 'Bearer rs_sk_test'
                    && $headers['Content-Type'] === 'application/json'),
                '{"context":"checkout"}',
                800
            )
            ->willReturn(new Response(200, '{"action":"review"}'));

        $response = $this->client($transport)->post('/v1/assessments', ['context' => 'checkout'], 1, 800);

        $this->assertSame(['action' => 'review'], $response);
    }

    public function testUsesTheDefaultTimeoutAndEncodesTheQuery(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects($this->once())
            ->method('send')
            ->with(
                'GET',
                'https://rs.example.com/v1/customers/lookup?externalId=m2%3A12',
                $this->anything(),
                null,
                3000
            )
            ->willReturn(new Response(200, '{"customers":[]}'));

        $response = $this->client($transport)->get('/v1/customers/lookup', ['externalId' => 'm2:12'], 1);

        $this->assertSame(['customers' => []], $response);
    }

    public function testDeletesWithTheQuery(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects($this->once())
            ->method('send')
            ->with('DELETE', 'https://rs.example.com/v1/customers?externalId=m2%3A12', $this->anything(), null, 3000)
            ->willReturn(new Response(200, '{"erased":1,"photos":0}'));

        $response = $this->client($transport)->delete('/v1/customers', ['externalId' => 'm2:12'], 1);

        $this->assertSame(['erased' => 1, 'photos' => 0], $response);
    }

    public function testNothingIsSentWithoutAConfiguredKey(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects($this->never())->method('send');
        $config = $this->createMock(Config::class);
        $config->method('apiUrl')->willReturn('https://rs.example.com');
        $config->method('secretKey')->willReturn('');

        try {
            (new Client($config, $transport, new Json()))->delete('/v1/customers', ['externalId' => 'm2:1'], 1);
            $this->fail('An ApiException was expected');
        } catch (ApiException $exception) {
            $this->assertTrue($exception->transient);
        }
    }

    public function testClientErrorsArePermanentAndCarryTheApiMessage(): void
    {
        $exception = $this->failure(new Response(400, '{"error":"validation","message":"amount is required"}'));

        $this->assertFalse($exception->transient);
        $this->assertSame(400, $exception->status);
        $this->assertStringContainsString('amount is required', $exception->getMessage());
    }

    public function testASuccessThatIsNotAJsonObjectIsATransientFailure(): void
    {
        foreach (['<!doctype html><title>ReturnSentry</title>', '', '[]', '"ok"'] as $body) {
            $exception = $this->failure(new Response(200, $body));
            $this->assertTrue($exception->transient, $body);
            $this->assertSame(200, $exception->status);
            $this->assertStringContainsString('without a JSON object', $exception->getMessage());
        }
    }

    public function testServerErrorsAndRateLimitsAreTransient(): void
    {
        $this->assertTrue($this->failure(new Response(503, 'Service Unavailable'))->transient);
        $this->assertTrue($this->failure(new Response(429, '{}'))->transient);
    }

    public function testNetworkFailuresAreTransient(): void
    {
        $transport = $this->createMock(TransportInterface::class);
        $transport->method('send')->willThrowException(new TransportException('Operation timed out after 800 ms'));

        try {
            $this->client($transport)->post('/v1/orders', [], 1);
            $this->fail('An ApiException was expected');
        } catch (ApiException $exception) {
            $this->assertTrue($exception->transient);
            $this->assertSame(0, $exception->status);
        }
    }

    private function failure(Response $response): ApiException
    {
        $transport = $this->createMock(TransportInterface::class);
        $transport->method('send')->willReturn($response);
        try {
            $this->client($transport)->post('/v1/orders', [], 1);
        } catch (ApiException $exception) {
            return $exception;
        }
        $this->fail('An ApiException was expected');
    }

    private function client(TransportInterface $transport): Client
    {
        $config = $this->createMock(Config::class);
        $config->method('apiUrl')->willReturn('https://rs.example.com');
        $config->method('secretKey')->willReturn('rs_sk_test');
        $config->method('timeoutMs')->willReturn(3000);
        return new Client($config, $transport, new Json());
    }
}
