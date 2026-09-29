<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model\Checkout;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Model\Checkout\OrderNumber;

class OrderNumberTest extends TestCase
{
    private AdapterInterface&MockObject $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
    }

    public function testReservesTheNumberMagentoWillGiveTheOrderAndKeepsItOnTheCart(): void
    {
        $quote = $this->quote(5, null);
        $quote->expects($this->once())->method('reserveOrderId')->willReturnCallback(
            static fn () => $quote->setData('reserved_order_id', '000000077')
        );
        $this->connection->expects($this->once())->method('update')->with(
            'quote',
            ['reserved_order_id' => '000000077'],
            ['entity_id = ?' => 5, 'reserved_order_id IS NULL']
        );

        $this->orderNumber()->reserve($quote);
    }

    public function testKeepsANumberAlreadyReserved(): void
    {
        $quote = $this->quote(5, '000000042');
        $quote->expects($this->never())->method('reserveOrderId');
        $this->connection->expects($this->never())->method('update');

        $this->orderNumber()->reserve($quote);
    }

    public function testLeavesAnUnsavedCartAlone(): void
    {
        $this->connection->expects($this->never())->method('update');

        $this->orderNumber()->reserve($this->quote(null, null));
    }

    private function quote(?int $id, ?string $reserved): Quote&MockObject
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['reserveOrderId', 'getId'])
            ->getMock();
        $quote->method('getId')->willReturn($id);
        $quote->setData('reserved_order_id', $reserved);
        return $quote;
    }

    private function orderNumber(): OrderNumber
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return new OrderNumber($resource);
    }
}
