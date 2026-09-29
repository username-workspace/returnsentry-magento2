<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model;

use Magento\Customer\Model\ResourceModel\Customer\Collection as CustomerCollection;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Model\Backfill;
use ReturnSentry\Connector\Model\Config;
use ReturnSentry\Connector\Model\Http\Client;
use ReturnSentry\Connector\Model\Mapper\Identity;
use ReturnSentry\Connector\Model\Mapper\OrderMapper;
use ReturnSentry\Connector\Model\Mapper\RefundMapper;
use ReturnSentry\Connector\Model\Mapper\ReturnMapper;
use ReturnSentry\Connector\Model\Mapper\ShipmentMapper;
use ReturnSentry\Connector\Model\Returns\ReturnStore;
use ReturnSentry\Connector\Model\Returns\RmaReader;
use ReturnSentry\Connector\Test\Unit\Fixtures;

class BackfillTest extends TestCase
{
    use Fixtures;

    protected function setUp(): void
    {
        $stubs = [OrderCollectionFactory::class => 'Order', CustomerCollectionFactory::class => 'Customer'];
        foreach ($stubs as $class => $stub) {
            if (!class_exists($class)) {
                require_once __DIR__ . '/../Stub/' . $stub . 'CollectionFactory.php';
            }
        }
    }

    public function testReportsEachRejectedRecordAndCountsTheWrittenOnes(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('post')
            ->with('/v1/batch', $this->callback(static fn (array $body) => array_column($body['records'], 'type') === [
                'order',
                'order',
                'order',
                'shipment',
            ]), 1)
            ->willReturn([
                'accepted' => 2,
                'created' => 2,
                'updated' => 0,
                'errors' => [
                    ['index' => 1, 'message' => 'data.amount Invalid input'],
                    ['index' => 3, 'message' => 'data.carrier Invalid option'],
                ],
            ]);

        $stats = $this->backfill($client)->run(1, '2026-01-01', '2026-02-01', false, static fn () => null);

        $this->assertSame(3, $stats['orders']);
        $this->assertSame(2, $stats['created']);
        $this->assertSame([
            'batch 1, record 1, order 100000002: data.amount Invalid input',
            'batch 1, record 3, shipment 6A123: data.carrier Invalid option',
        ], $stats['errors']);
    }

    public function testDryRunMapsWithoutSending(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects($this->never())->method('post');

        $stats = $this->backfill($client)->run(1, '2026-01-01', '2026-02-01', true, static fn () => null);

        $this->assertSame(4, $stats['records']);
    }

    private function backfill(Client $client): Backfill
    {
        $orders = [];
        foreach ([1, 2, 3] as $id) {
            $shipments = $id === 3 ? [$this->createMock(Shipment::class)] : [];
            $data = ['entity_id' => $id, 'increment_id' => '10000000' . $id];
            $orders[] = $this->order($data, [], null, false, $shipments);
        }
        $collection = $this->createMock(OrderCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('setCurPage')->willReturnSelf();
        $collection->method('getItems')->willReturn($orders);
        $orderCollections = $this->createMock(OrderCollectionFactory::class);
        $orderCollections->method('create')->willReturn($collection);
        $orderMapper = $this->createMock(OrderMapper::class);
        $orderMapper->method('toRecord')
            ->willReturnCallback(static fn ($order) => ['externalId' => $order->getIncrementId()]);
        $shipmentMapper = $this->createMock(ShipmentMapper::class);
        $shipmentMapper->method('toRecords')->willReturn([['trackingNumber' => '6A123', 'carrier' => 'colissimo']]);
        return new Backfill(
            $orderCollections,
            $this->createMock(CustomerCollectionFactory::class),
            $client,
            new Identity($this->createMock(Config::class)),
            $orderMapper,
            $this->createMock(RefundMapper::class),
            $this->createMock(ReturnMapper::class),
            $this->createMock(RmaReader::class),
            $this->createMock(ReturnStore::class),
            $shipmentMapper
        );
    }
}
