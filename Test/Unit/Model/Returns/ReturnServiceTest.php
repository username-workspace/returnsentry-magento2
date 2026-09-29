<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model\Returns;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReturnSentry\Connector\Model\Config;
use ReturnSentry\Connector\Model\Http\Client;
use ReturnSentry\Connector\Model\Mapper\OrderMapper;
use ReturnSentry\Connector\Model\Mapper\ReturnMapper;
use ReturnSentry\Connector\Model\OrderLoader;
use ReturnSentry\Connector\Model\Returns\ReturnService;
use ReturnSentry\Connector\Model\Returns\ReturnStore;
use ReturnSentry\Connector\Model\Returns\RmaReader;

class ReturnServiceTest extends TestCase
{
    public function testAReceivedReturnIsAssessedWithTheDateTheGoodsCameBack(): void
    {
        $store = $this->createMock(ReturnStore::class);
        $store->method('find')->willReturn(['record' => [
            'externalId' => 'rma:1',
            'orderExternalId' => '100000007',
            'customer' => ['externalId' => 'm2:12'],
            'reason' => 'size_fit',
            'requestedAt' => '2026-09-01T00:00:00Z',
            'receivedAt' => '2026-09-05T08:15:00Z',
        ], 'assessment' => null]);
        $store->expects($this->once())->method('saveAssessment');
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('post')
            ->with('/v1/assessments', $this->callback(static fn (array $body) => $body['context'] === 'return'
                && $body['return']['orderExternalId'] === '100000007'
                && $body['return']['goodsBackAt'] === '2026-09-05T08:15:00Z'), 1)
            ->willReturn(['action' => 'inspect_before_refund', 'level' => 'high', 'score' => 64, 'reasons' => []]);
        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $service = new ReturnService(
            $config,
            $client,
            $store,
            $this->createMock(ReturnMapper::class),
            $this->createMock(OrderMapper::class),
            $this->createMock(RmaReader::class),
            $this->createMock(OrderLoader::class),
            $this->createMock(LoggerInterface::class)
        );

        $this->assertSame('inspect_before_refund', $service->assessOnce('rma:1', 1)->action);
    }
}
