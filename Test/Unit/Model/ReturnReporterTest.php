<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Api\Data\Assessment;
use ReturnSentry\Connector\Api\Data\ReturnRequest;
use ReturnSentry\Connector\Model\Mapper\OrderMapper;
use ReturnSentry\Connector\Model\OrderLoader;
use ReturnSentry\Connector\Model\ReturnReporter;
use ReturnSentry\Connector\Model\Returns\ReturnService;
use ReturnSentry\Connector\Model\Sync\Publisher;
use ReturnSentry\Connector\Model\Sync\Sender;
use ReturnSentry\Connector\Test\Unit\Fixtures;

class ReturnReporterTest extends TestCase
{
    use Fixtures;

    public function testAReportedReturnIsAssessedLaterNotDuringTheRequest(): void
    {
        $orders = $this->createMock(OrderLoader::class);
        $orders->method('get')->willReturn($this->order([]));
        $returns = $this->createMock(ReturnService::class);
        $returns->method('record')->willReturn('RET-1');
        $returns->expects($this->never())->method('assessOnce');
        $returns->method('storedAssessment')->with('RET-1')->willReturn(Assessment::notAssessed());
        $publisher = $this->createMock(Publisher::class);
        $publisher->expects($this->once())->method('publish')->with(Sender::RETURN, 'RET-1', 1);

        $assessment = (new ReturnReporter($orders, $returns, $this->createMock(OrderMapper::class), $publisher))
            ->report(new ReturnRequest('RET-1', 7, new \DateTimeImmutable()));

        $this->assertFalse($assessment->assessed);
    }
}
