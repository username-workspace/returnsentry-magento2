<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Item;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\ResourceModel\Order\Creditmemo\Collection as CreditmemoCollection;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Collection as ShipmentCollection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Model\Mapper\Weight;

trait Fixtures
{
    /**
     * @param array<string, mixed> $data
     * @param Item[] $items
     * @param Shipment[] $shipments
     * @param Creditmemo[] $creditmemos
     */
    private function order(
        array $data,
        array $items = [],
        ?Address $shipping = null,
        bool $shipped = false,
        array $shipments = [],
        array $creditmemos = []
    ): Order&MockObject {
        /** @var TestCase&Fixtures $this */
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getAllVisibleItems',
                'getAllItems',
                'getItemById',
                'hasShipments',
                'getShippingAddress',
                'getBillingAddress',
                'getPayment',
                'getCreditmemosCollection',
                'getShipmentsCollection',
                'addCommentToStatusHistory',
            ])
            ->getMock();
        $order->setData($data + [
            'entity_id' => 7,
            'increment_id' => '100000007',
            'store_id' => 1,
            'state' => 'processing',
            'status' => 'processing',
            'customer_email' => 'jane@example.com',
            'created_at' => '2026-09-01 10:00:00',
            'base_grand_total' => 120.0,
            'grand_total' => 120.0,
            'order_currency_code' => 'EUR',
            'base_currency_code' => 'EUR',
        ]);
        $order->method('getAllVisibleItems')->willReturn($items);
        $order->method('getAllItems')->willReturn($items);
        $order->method('getItemById')->willReturnCallback(static function ($id) use ($items): ?Item {
            foreach ($items as $item) {
                if ((int) $item->getItemId() === (int) $id) {
                    return $item;
                }
            }
            return null;
        });
        $order->method('hasShipments')->willReturn($shipped);
        $order->method('getShippingAddress')->willReturn($shipping);
        $order->method('getBillingAddress')->willReturn(null);
        $payment = null;
        if (isset($data['payment_method'])) {
            $payment = $this->getMockBuilder(Payment::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
            $payment->setData('method', $data['payment_method']);
        }
        $order->method('getPayment')->willReturn($payment);
        $creditmemoCollection = $this->createMock(CreditmemoCollection::class);
        $creditmemoCollection->method('getItems')->willReturn($creditmemos);
        $order->method('getCreditmemosCollection')->willReturn($creditmemoCollection);
        $shipmentCollection = $this->createMock(ShipmentCollection::class);
        $shipmentCollection->method('getItems')->willReturn($shipments);
        $order->method('getShipmentsCollection')->willReturn($shipmentCollection);
        return $order;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function orderItem(array $data): Item
    {
        /** @var TestCase&Fixtures $this */
        $item = $this->getMockBuilder(Item::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $item->setData($data);
        return $item;
    }

    private function weight(string $unit = 'kgs'): Weight
    {
        /** @var TestCase&Fixtures $this */
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(
            static fn (string $path) => $path === 'general/locale/weight_unit' ? $unit : null
        );
        return new Weight($scope);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function orderAddress(array $data): Address
    {
        /** @var TestCase&Fixtures $this */
        $address = $this->getMockBuilder(Address::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $address->setData($data);
        return $address;
    }
}
