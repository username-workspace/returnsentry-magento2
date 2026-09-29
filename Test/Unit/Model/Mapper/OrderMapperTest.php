<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model\Mapper;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Model\Config;
use ReturnSentry\Connector\Model\Mapper\Identity;
use ReturnSentry\Connector\Model\Mapper\OrderMapper;
use ReturnSentry\Connector\Model\Mapper\ProductSkus;
use ReturnSentry\Connector\Test\Unit\Fixtures;

class OrderMapperTest extends TestCase
{
    use Fixtures;

    public function testMapsAnOrderToTheStandardRecord(): void
    {
        $order = $this->order(
            [
                'customer_id' => 12,
                'returnsentry_visitor_id' => 'Qm9vbGVhbi1zdHJpbmc',
                'remote_ip' => '203.0.113.9',
                'base_discount_amount' => -10.0,
                'shipping_method' => 'flatrate_flatrate',
            ],
            [$this->orderItem([
                'sku' => 'DRESS-M',
                'product_id' => 42,
                'name' => 'Dress',
                'qty_ordered' => 2,
                'base_row_total_incl_tax' => 130.0,
                'base_discount_amount' => 10.0,
                'weight' => 0.4504,
            ])],
            $this->orderAddress([
                'country_id' => 'FR',
                'street' => '1 rue A',
                'postcode' => '75001',
                'city' => 'Paris',
            ]),
            true
        );

        $record = $this->mapper()->toRecord($order);

        $this->assertSame([
            'externalId' => '100000007',
            'customer' => ['externalId' => 'm2:12', 'email' => 'jane@example.com'],
            'placedAt' => '2026-09-01T10:00:00Z',
            'amount' => 120.0,
            'currency' => 'EUR',
            'paidAmount' => 120.0,
            'discountAmount' => 10.0,
            'status' => 'shipped',
            'channel' => 'fr_fr',
            'shippingMethod' => 'flatrate_flatrate',
            'ip' => '203.0.113.9',
            'visitorId' => 'Qm9vbGVhbi1zdHJpbmc',
            'items' => [[
                'sku' => 'DRESS-M',
                'productId' => 'DRESS',
                'name' => 'Dress',
                'quantity' => 2,
                'amount' => 120.0,
                'weightGrams' => 450,
            ]],
            'shippingAddress' => ['line1' => '1 rue A', 'postalCode' => '75001', 'city' => 'Paris', 'country' => 'FR'],
        ], $record);
    }

    public function testUnconfirmedPaymentsAreNotSentYet(): void
    {
        $this->assertNull($this->mapper()->toRecord($this->order(['state' => 'pending_payment'])));
        $this->assertNull($this->mapper()->toRecord($this->order(['state' => 'payment_review'])));
    }

    public function testOrdersOfAnErasedCustomerAreNoLongerSent(): void
    {
        $mapper = $this->mapper();
        $erased = $this->order(['customer_is_guest' => 0, 'customer_id' => null]);

        $this->assertTrue($mapper->hasErasedCustomer($erased));
        $this->assertNull($mapper->toRecord($erased));
        $this->assertFalse($mapper->hasErasedCustomer($this->order(['customer_is_guest' => 1])));
        $this->assertFalse($mapper->hasErasedCustomer($this->order(['customer_is_guest' => 0, 'customer_id' => 12])));
    }

    public function testOrdersErasedByEmailAreNoLongerSentThoughTheShopKeepsTheirCustomer(): void
    {
        $mapper = $this->mapper();
        $account = $this->order(['customer_is_guest' => 0, 'customer_id' => 12, 'returnsentry_erased' => 1]);
        $guest = $this->order(['customer_is_guest' => 1, 'returnsentry_erased' => 1]);

        $this->assertTrue($mapper->hasErasedCustomer($account));
        $this->assertTrue($mapper->hasErasedCustomer($guest));
        $this->assertNull($mapper->toRecord($guest));
    }

    public function testStatusFollowsStateAndShipments(): void
    {
        $mapper = $this->mapper();

        $this->assertSame('cancelled', $mapper->status('canceled', 'canceled', false));
        $this->assertSame('cancelled', $mapper->status('closed', 'closed', false));
        $this->assertSame('placed', $mapper->status('processing', 'processing', false));
        $this->assertSame('shipped', $mapper->status('processing', 'processing', true));
        $this->assertSame('shipped', $mapper->status('closed', 'closed', true));
        $this->assertSame('delivered', $mapper->status('complete', 'complete', true));
    }

    private function mapper(): OrderMapper
    {
        $config = $this->createMock(Config::class);
        $config->method('pseudonymSalt')->willReturn(null);
        $skus = $this->createMock(ProductSkus::class);
        $skus->method('forIds')->with([42])->willReturn([42 => 'DRESS']);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getCode')->willReturn('fr_fr');
        $stores = $this->createMock(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        return new OrderMapper(new Identity($config), $skus, $stores, $this->weight());
    }
}
