<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Model\Retention;

class RetentionTest extends TestCase
{
    private AdapterInterface&MockObject $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
    }

    public function testRefundAuthorizationsLeaveWithTheirOrder(): void
    {
        $orders = $this->createMock(Select::class);
        $orders->method('from')->with('sales_order', 'entity_id')->willReturnSelf();
        $this->connection->method('select')->willReturn($orders);
        $this->connection->expects($this->once())
            ->method('delete')
            ->with('returnsentry_refund_authorization', ['order_id NOT IN (?)' => $orders])
            ->willReturn(2);

        $this->assertSame(2, $this->retention()->deleteOrphanAuthorizations());
    }

    public function testAnInspectionNoSendIsPendingForLeaves(): void
    {
        $pending = $this->createMock(Select::class);
        $pending->method('from')->with('returnsentry_sync_retry', 'entity_id')->willReturnSelf();
        $pending->expects($this->once())->method('where')->with('entity_type = ?', 'inspection')->willReturnSelf();
        $this->connection->method('select')->willReturn($pending);
        $this->connection->expects($this->once())
            ->method('delete')
            ->with('returnsentry_inspection', ['external_id NOT IN (?)' => $pending])
            ->willReturn(1);

        $this->assertSame(1, $this->retention()->deleteAbandonedInspections());
    }

    public function testVisitorIdsLeaveOrdersAtTheTrackerRetention(): void
    {
        $this->connection->expects($this->once())
            ->method('update')
            ->with(
                'sales_order',
                ['returnsentry_visitor_id' => null],
                [
                    'returnsentry_visitor_id IS NOT NULL',
                    'created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MONTH)' => 13,
                ]
            )
            ->willReturn(5);

        $this->assertSame(13, Retention::TRACKER_RETENTION_MONTHS);
        $this->assertSame(5, $this->retention()->purgeVisitorIds());
    }

    private function retention(): Retention
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return new Retention($resource);
    }
}
