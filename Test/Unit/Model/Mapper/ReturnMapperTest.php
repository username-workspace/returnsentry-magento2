<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model\Mapper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Api\Data\ReturnItem;
use ReturnSentry\Connector\Api\Data\ReturnRequest;
use ReturnSentry\Connector\Api\Data\ReturnShipment;
use ReturnSentry\Connector\Model\Config;
use ReturnSentry\Connector\Model\Mapper\Carrier;
use ReturnSentry\Connector\Model\Mapper\Identity;
use ReturnSentry\Connector\Model\Mapper\OrderMapper;
use ReturnSentry\Connector\Model\Mapper\ProductSkus;
use ReturnSentry\Connector\Model\Mapper\ReturnMapper;
use ReturnSentry\Connector\Model\Source\ReturnReason;
use ReturnSentry\Connector\Test\Unit\Fixtures;

class ReturnMapperTest extends TestCase
{
    use Fixtures;

    private const REASON_MAP = '{"a":{"shop":"Trop petit","standard":"size_fit"},'
        . '"b":{"shop":"Colis non retiré","standard":"return_to_sender"},'
        . '"c":{"shop":"Ce n’est pas ce que j’ai commandé","standard":"wrong_item"}}';

    public function testMapsShopLabelsAndStandardValues(): void
    {
        $mapper = $this->mapper();

        $this->assertSame('size_fit', $mapper->reason('  TROP PETIT ', 1));
        $this->assertSame('wrong_item', $mapper->reason("Ce n'est pas ce que j'ai commandé", 1));
        $this->assertSame('damaged_defective', $mapper->reason('damaged_defective', 1));
        $this->assertSame('unknown', $mapper->reason('Unmapped label', 1));
        $this->assertSame('unknown', $mapper->reason('Colis non retiré', 1));
        $this->assertSame('unknown', $mapper->reason('return_to_sender', 1));
        $this->assertSame('unknown', $mapper->reason(null, 1));
    }

    public function testDominantReasonIgnoresUnknownAndKeepsTheFirstOnTies(): void
    {
        $mapper = $this->mapper();

        $this->assertSame('quality', $mapper->dominant(['unknown', 'quality', 'size_fit', 'quality']));
        $this->assertSame('size_fit', $mapper->dominant(['size_fit', 'quality']));
        $this->assertSame('unknown', $mapper->dominant(['unknown']));
    }

    public function testMapsAReportedReturnWithItemAmountsFromTheOrder(): void
    {
        $order = $this->order([], [
            $this->orderItem([
                'item_id' => 1,
                'sku' => 'DRESS-M',
                'qty_ordered' => 2,
                'base_row_total_incl_tax' => 200.0,
            ]),
            $this->orderItem(['item_id' => 2, 'sku' => 'BAG', 'qty_ordered' => 1, 'base_row_total_incl_tax' => 80.0]),
        ]);
        $request = new ReturnRequest(
            'RET-1',
            7,
            new \DateTimeImmutable('2026-09-10T08:00:00+02:00'),
            [new ReturnItem(1, 1, 'Trop petit'), new ReturnItem(2, 0)],
            'received',
            null,
            new \DateTimeImmutable('2026-09-14T09:30:00Z'),
            [new ReturnShipment('1Z999AA10123456784', 'UPS Standard')]
        );

        $record = $this->mapper()->fromRequest($request, $order);

        $this->assertSame([
            'externalId' => 'RET-1',
            'orderExternalId' => '100000007',
            'customer' => ['email' => 'jane@example.com'],
            'kind' => 'customer_return',
            'status' => 'received',
            'requestedAt' => '2026-09-10T06:00:00Z',
            'receivedAt' => '2026-09-14T09:30:00Z',
            'reason' => 'size_fit',
            'amount' => 100.0,
            'items' => [[
                'sku' => 'DRESS-M',
                'quantity' => 1,
                'amount' => 100.0,
                'reason' => 'size_fit',
                'reasonText' => 'Trop petit',
            ]],
            'shipments' => [['trackingNumber' => '1Z999AA10123456784', 'carrier' => 'ups']],
        ], $record);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rmaStatuses(): array
    {
        return [
            'authorized' => ['authorized', 'requested'],
            'received' => ['received', 'received'],
            'approved after inspection, refund still to pay' => ['approved', 'received'],
            'approved item' => ['approved_on_item', 'received'],
            'processed and closed' => ['processed_closed', 'refunded'],
            'rejected' => ['rejected', 'rejected'],
            'closed without processing' => ['closed', 'cancelled'],
        ];
    }

    #[DataProvider('rmaStatuses')]
    public function testAnRmaStatusMapsToTheReturnStatus(string $rmaStatus, string $expected): void
    {
        $order = $this->order([], [$this->orderItem(['item_id' => 1, 'sku' => 'BAG', 'qty_ordered' => 1])]);

        $record = $this->mapper()->fromRma(
            ['increment_id' => '000000003', 'date_requested' => '2026-09-02 12:00:00', 'status' => $rmaStatus],
            [['orderItemId' => 1, 'sku' => 'BAG', 'quantity' => 1, 'reason' => null]],
            [],
            $order
        );

        $this->assertSame($expected, $record['status']);
    }

    public function testAnRmaIsACustomerReturnEvenWhenItsReasonSaysNotCollected(): void
    {
        $order = $this->order([], [$this->orderItem(['item_id' => 1, 'sku' => 'BAG', 'qty_ordered' => 1])]);

        $record = $this->mapper()->fromRma(
            [
                'increment_id' => '000000003',
                'date_requested' => '2026-09-02 12:00:00',
                'status' => 'received_on_item',
                'received_at' => '2026-09-06 08:15:00',
            ],
            [['orderItemId' => 1, 'sku' => 'BAG', 'quantity' => 1, 'reason' => 'Colis non retiré']],
            [
                ['number' => ' 8R00123456789 ', 'code' => 'custom', 'title' => 'Colissimo retour'],
                ['number' => '', 'code' => 'ups', 'title' => null],
            ],
            $order
        );

        $this->assertSame('rma:000000003', $record['externalId']);
        $this->assertSame('customer_return', $record['kind']);
        $this->assertSame('received', $record['status']);
        $this->assertSame('2026-09-06T08:15:00Z', $record['receivedAt']);
        $this->assertSame('unknown', $record['reason']);
        $this->assertSame('unknown', $record['items'][0]['reason']);
        $this->assertSame([['trackingNumber' => '8R00123456789', 'carrier' => 'colissimo']], $record['shipments']);
    }

    public function testOnlyAnExplicitReturnToSenderOnABaseCurrencyOrderIsAReturnToSender(): void
    {
        $item = $this->orderItem(['item_id' => 1, 'sku' => 'BAG', 'qty_ordered' => 1]);
        $request = static fn (?string $reason) => new ReturnRequest(
            'RET-2',
            7,
            new \DateTimeImmutable('2026-09-10T08:00:00Z'),
            [new ReturnItem(1)],
            'received',
            $reason
        );
        $mapper = $this->mapper();
        $domestic = $this->order([], [$item]);
        $crossBorder = $this->order(['order_currency_code' => 'GBP'], [$item]);

        $this->assertSame('return_to_sender', $mapper->fromRequest($request('return_to_sender'), $domestic)['kind']);
        $this->assertSame('customer_return', $mapper->fromRequest($request('return_to_sender'), $crossBorder)['kind']);
        $this->assertSame('customer_return', $mapper->fromRequest($request('Colis non retiré'), $domestic)['kind']);
    }

    private function mapper(): ReturnMapper
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturn(false);
        $scope->method('getValue')->willReturnCallback(
            static fn (string $path) => $path === 'returnsentry/mapping/return_reasons' ? self::REASON_MAP : null
        );
        $config = new Config($scope, new Json());
        $orderMapper = new OrderMapper(
            new Identity($config),
            $this->createMock(ProductSkus::class),
            $this->createMock(StoreManagerInterface::class),
            $this->weight()
        );
        return new ReturnMapper($config, $orderMapper, new Carrier(), new ReturnReason());
    }
}
