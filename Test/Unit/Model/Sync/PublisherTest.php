<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model\Sync;

use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReturnSentry\Connector\Model\Config;
use ReturnSentry\Connector\Model\Sync\Publisher;
use ReturnSentry\Connector\Model\Sync\RetryStore;
use ReturnSentry\Connector\Model\Sync\Sender;

class PublisherTest extends TestCase
{
    public function testErasuresOverridesAndInspectionsGoToTheDurableRetryStoreEvenWhenTheStoreIsDisconnected(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isConnected')->willReturn(false);
        $config->method('apiUrl')->willReturn('https://rs.example.com');
        $config->method('secretKey')->willReturn('rs_sk_test');
        $queue = $this->createMock(PublisherInterface::class);
        $queue->expects($this->never())->method('publish');
        $retries = $this->createMock(RetryStore::class);
        $enqueued = [];
        $retries->expects($this->exactly(3))
            ->method('enqueue')
            ->willReturnCallback(static function (string $type, string $id) use (&$enqueued): void {
                $enqueued[] = [$type, $id];
            });
        $publisher = new Publisher($queue, $config, new Json(), $this->createMock(LoggerInterface::class), $retries);

        $publisher->publish(Sender::ORDER, 42, 1);
        $publisher->publish(Sender::ERASURE, 12, 1);
        $publisher->publish(Sender::OVERRIDE, 'ra:100000007:1', 1);
        $publisher->publish(Sender::INSPECTION, 'CTL-1', 1);

        $this->assertSame(
            [[Sender::ERASURE, '12'], [Sender::OVERRIDE, 'ra:100000007:1'], [Sender::INSPECTION, 'CTL-1']],
            $enqueued
        );
    }

    public function testAStoreWithoutKeysKeepsNoErasureItCouldNeverSend(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('apiUrl')->willReturn('');
        $config->method('secretKey')->willReturn('');
        $retries = $this->createMock(RetryStore::class);
        $retries->expects($this->never())->method('enqueue');
        $queue = $this->createMock(PublisherInterface::class);

        (new Publisher($queue, $config, new Json(), $this->createMock(LoggerInterface::class), $retries))
            ->publish(Sender::ERASURE, 12, 1);
    }

    public function testAQueueFailureNeverBreaksTheSave(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isConnected')->willReturn(true);
        $queue = $this->createMock(PublisherInterface::class);
        $queue->method('publish')->willThrowException(new \RuntimeException('queue down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        (new Publisher($queue, $config, new Json(), $logger, $this->createMock(RetryStore::class)))
            ->publish(Sender::ORDER, 42, 1);
    }
}
