<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model\Mapper;

use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\Item as ShipmentItem;
use Magento\Sales\Model\Order\Shipment\Track;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Model\Config;
use ReturnSentry\Connector\Model\Mapper\Carrier;
use ReturnSentry\Connector\Model\Mapper\Identity;
use ReturnSentry\Connector\Model\Mapper\OrderMapper;
use ReturnSentry\Connector\Model\Mapper\ProductSkus;
use ReturnSentry\Connector\Model\Mapper\ShipmentMapper;
use ReturnSentry\Connector\Test\Unit\Fixtures;

class ShipmentMapperTest extends TestCase
{
    use Fixtures;

    public function testAParcelCarriesTheItemsAndDeclaredWeightOfItsShipment(): void
    {
        $shipment = $this->shipment(
            ['total_weight' => '1.2500', 'created_at' => '2026-09-02 08:00:00'],
            [$this->track('6a 123', 'custom', 'Colissimo Expert')],
            [
                $this->shipmentItem('DRESS-M', 1, 1),
                $this->shipmentItem('DRESS-M', 1, 2),
                $this->shipmentItem('BAG', 0, 3),
            ]
        );

        $records = $this->mapper()->toRecords($shipment, $this->orderWithItems());

        $this->assertSame([[
            'trackingNumber' => '6a 123',
            'carrier' => 'colissimo',
            'carrierName' => 'Colissimo Expert',
            'orderExternalId' => '100000007',
            'declaredWeightGrams' => 1250,
            'items' => [['sku' => 'DRESS-M', 'quantity' => 1]],
            'shippedAt' => '2026-09-02T08:00:00Z',
        ]], $records);
    }

    public function testParcelsSharingAShipmentLeaveItsItemsAndWeightOut(): void
    {
        $shipment = $this->shipment(
            ['total_weight' => '3.0000', 'created_at' => '2026-09-02 08:00:00'],
            [
                $this->track('1Z1', 'ups', 'United Parcel Service'),
                $this->track('1Z2', 'ups', null),
                $this->track(' ', 'ups', null),
            ],
            [$this->shipmentItem('DRESS-M', 2, 1)]
        );

        $records = $this->mapper()->toRecords($shipment, $this->orderWithItems());

        $this->assertSame(['1Z1', '1Z2'], array_column($records, 'trackingNumber'));
        $this->assertSame(['United Parcel Service', 'ups'], array_column($records, 'carrierName'));
        $this->assertSame([], array_column($records, 'items'));
        $this->assertSame([], array_column($records, 'declaredWeightGrams'));
    }

    public function testNothingIsSentForAnErasedCustomer(): void
    {
        $shipment = $this->shipment([], [$this->track('6A1', 'custom', 'Colissimo')], []);
        $order = $this->order(['customer_is_guest' => 0, 'customer_id' => null]);

        $this->assertSame([], $this->mapper()->toRecords($shipment, $order));
    }

    private function orderWithItems(): \Magento\Sales\Model\Order
    {
        return $this->order(['customer_id' => 12], [
            $this->orderItem(['item_id' => 1, 'sku' => 'DRESS-M', 'product_type' => 'configurable']),
            $this->orderItem(['item_id' => 2, 'sku' => 'DRESS-M', 'parent_item_id' => 1]),
            $this->orderItem(['item_id' => 3, 'sku' => 'BAG']),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @param Track[] $tracks
     * @param ShipmentItem[] $items
     */
    private function shipment(array $data, array $tracks, array $items): Shipment
    {
        $shipment = $this->getMockBuilder(Shipment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAllTracks', 'getAllItems'])
            ->getMock();
        $shipment->setData($data);
        $shipment->method('getAllTracks')->willReturn($tracks);
        $shipment->method('getAllItems')->willReturn($items);
        return $shipment;
    }

    private function track(string $number, string $code, ?string $title): Track
    {
        $track = $this->getMockBuilder(Track::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $track->setData(['track_number' => $number, 'carrier_code' => $code, 'title' => $title]);
        return $track;
    }

    private function shipmentItem(string $sku, int $qty, int $orderItemId): ShipmentItem
    {
        $item = $this->getMockBuilder(ShipmentItem::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $item->setData(['sku' => $sku, 'qty' => $qty, 'order_item_id' => $orderItemId]);
        return $item;
    }

    private function mapper(): ShipmentMapper
    {
        $orderMapper = new OrderMapper(
            new Identity($this->createMock(Config::class)),
            $this->createMock(ProductSkus::class),
            $this->createMock(StoreManagerInterface::class),
            $this->weight()
        );
        return new ShipmentMapper($orderMapper, new Carrier(), $this->weight());
    }
}
