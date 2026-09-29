<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model\Sync;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Model\Sync\RetryStore;

class RetryStoreTest extends TestCase
{
    public function testAFailureIsDatedSoTheLatestErrorCanBeShown(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())
            ->method('insertOnDuplicate')
            ->with(
                'returnsentry_sync_retry',
                $this->callback(static fn (array $row) => is_string($row['failed_at'] ?? null)),
                $this->callback(static fn (array $columns) => in_array('failed_at', $columns, true))
            );

        $this->store($connection)->schedule('erasure', '12', 1, 3, 'HTTP 503');
    }

    public function testPendingErasuresQuoteTheMostRecentError(): void
    {
        $count = $this->createMock(Select::class);
        $count->method('from')->willReturnSelf();
        $count->method('where')->willReturnSelf();
        $latest = $this->createMock(Select::class);
        $latest->method('from')->with('returnsentry_sync_retry', ['last_error'])->willReturnSelf();
        $latest->method('where')->willReturnSelf();
        $latest->expects($this->once())->method('order')->with('failed_at DESC')->willReturnSelf();
        $latest->method('limit')->with(1)->willReturnSelf();
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturnOnConsecutiveCalls($count, $latest);
        $connection->method('fetchOne')->willReturnMap([[$count, [], '2'], [$latest, [], 'HTTP 401']]);

        $this->assertSame(['count' => 2, 'lastError' => 'HTTP 401'], $this->store($connection)->pending('erasure'));
    }

    private function store(AdapterInterface $connection): RetryStore
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return new RetryStore($resource);
    }
}
