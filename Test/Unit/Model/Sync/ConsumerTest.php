<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model\Sync;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReturnSentry\Connector\Model\Http\ApiException;
use ReturnSentry\Connector\Model\Sync\Consumer;
use ReturnSentry\Connector\Model\Sync\RetryStore;
use ReturnSentry\Connector\Model\Sync\Sender;

class ConsumerTest extends TestCase
{
    private Sender&MockObject $sender;
    private RetryStore&MockObject $retries;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->sender = $this->createMock(Sender::class);
        $this->retries = $this->createMock(RetryStore::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testSendsTheQueuedEntity(): void
    {
        $this->sender->expects($this->once())->method('send')->with('order', '42', 1);
        $this->retries->expects($this->once())->method('clear')->with('order', '42');

        $this->consumer()->process('{"type":"order","id":"42","store":1}');
    }

    public function testSchedulesARetryWithBackoffOnATransientFailure(): void
    {
        $this->sender->method('send')->willThrowException(new ApiException('HTTP 503', true, 503));
        $this->retries->expects($this->once())->method('schedule')->with('order', '42', 1, 1, 'HTTP 503');
        $this->retries->expects($this->never())->method('clear');

        $this->consumer()->process('{"type":"order","id":"42","store":1}');
    }

    public function testRetriesAnOverridePastTheLastAttemptUntilReturnSentryDecides(): void
    {
        $this->sender->method('send')->willThrowException(new ApiException('HTTP 401', false, 401));
        $this->retries->expects($this->once())
            ->method('schedule')
            ->with('override', 'ra:1:1', 1, Consumer::MAX_ATTEMPTS + 1, 'HTTP 401');
        $this->retries->expects($this->never())->method('clear');

        $this->consumer()->run('override', 'ra:1:1', 1, Consumer::MAX_ATTEMPTS);
    }

    public function testRetriesWhileTheSecretKeyIsRefused(): void
    {
        $this->sender->method('send')->willThrowException(new ApiException('HTTP 401', false, 401));
        $this->retries->expects($this->once())->method('schedule')->with('order', '42', 1, 1, 'HTTP 401');
        $this->retries->expects($this->never())->method('clear');

        $this->consumer()->process('{"type":"order","id":"42","store":1}');
    }

    public function testGivesUpOnAPermanentFailureOrAfterTheLastAttempt(): void
    {
        $this->sender->method('send')->willReturnOnConsecutiveCalls(
            $this->throwException(new ApiException('amount is required', false, 400)),
            $this->throwException(new ApiException('HTTP 503', true, 503))
        );
        $this->retries->expects($this->never())->method('schedule');
        $this->retries->expects($this->exactly(2))->method('clear');
        $this->logger->expects($this->exactly(2))->method('error');

        $this->consumer()->run('order', '42', 1, 0);
        $this->consumer()->run('order', '42', 1, Consumer::MAX_ATTEMPTS);
    }

    public function testAnErasureIsRetriedUntilItSucceeds(): void
    {
        $this->sender->method('send')->willReturnOnConsecutiveCalls(
            $this->throwException(new ApiException('HTTP 401', false, 401)),
            $this->throwException(new ApiException('HTTP 503', true, 503))
        );
        $this->retries->expects($this->exactly(2))->method('schedule');
        $this->retries->expects($this->never())->method('clear');

        $this->consumer()->run(Sender::ERASURE, '12', 1, 0);
        $this->consumer()->run(Sender::ERASURE, '12', 1, Consumer::MAX_ATTEMPTS + 5);
    }

    public function testDropsDeletedEntitiesAndMalformedMessages(): void
    {
        $this->sender->method('send')->willThrowException(new NoSuchEntityException());
        $this->retries->expects($this->once())->method('clear')->with('customer', '9');
        $this->logger->expects($this->once())->method('error');

        $this->consumer()->process('{"type":"customer","id":"9","store":1}');
        $this->consumer()->process('{"unexpected":true}');
    }

    private function consumer(): Consumer
    {
        return new Consumer($this->sender, $this->retries, new Json(), $this->logger);
    }
}
