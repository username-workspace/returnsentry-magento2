<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model\Mapper;

use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Creditmemo\Item;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Model\Config;
use ReturnSentry\Connector\Model\Mapper\Identity;
use ReturnSentry\Connector\Model\Mapper\OrderMapper;
use ReturnSentry\Connector\Model\Mapper\ProductSkus;
use ReturnSentry\Connector\Model\Mapper\RefundMapper;
use ReturnSentry\Connector\Model\Source\RefundCategory;
use ReturnSentry\Connector\Test\Unit\Fixtures;

class RefundMapperTest extends TestCase
{
    use Fixtures;

    /**
     * @return array<string, array{0: ?string, 1: ?array<string, string>, 2: float, 3: bool, 4: string, 5?: string}>
     */
    public static function situations(): array
    {
        return [
            'mapped code wins' => ['CDI001', ['kind' => 'customer_return'], 1, true, 'empty_package_claim'],
            'unmapped code is other' => ['ZZZ', null, 1, true, 'other'],
            'customer return' => [null, ['kind' => 'customer_return'], 1, true, 'return'],
            'return to sender' => [null, ['kind' => 'return_to_sender'], 1, true, 'return_to_sender'],
            'mapped return to sender' => ['RTS', null, 1, true, 'return_to_sender'],
            'cross-border mapped return to sender' => ['RTS', null, 1, true, 'return', 'GBP'],
            'cross-border return to sender' => [null, ['kind' => 'return_to_sender'], 1, true, 'return', 'CHF'],
            'adjustment only' => [null, null, 0, true, 'commercial_gesture'],
            'not shipped' => [null, null, 1, false, 'merchant_error'],
            'shipped without return' => [null, null, 1, true, 'item_not_received'],
            'return dispute with its return' => [
                'RND',
                ['kind' => 'customer_return'],
                1,
                true,
                'return_not_received_dispute',
            ],
            'return dispute without a return' => ['RND', null, 1, true, 'return'],
        ];
    }

    /**
     * @param array<string, string>|null $return
     */
    #[DataProvider('situations')]
    public function testCategoryFollowsTheConfiguredSituations(
        ?string $code,
        ?array $return,
        float $quantity,
        bool $shipped,
        string $expected,
        string $paidCurrency = 'EUR'
    ): void {
        $order = $this->order(['order_currency_code' => $paidCurrency], [], null, $shipped);

        $this->assertSame($expected, $this->mapper()->category($this->creditmemo($code, $quantity), $order, $return));
    }

    public function testMapsACreditMemoToTheStandardRefund(): void
    {
        $record = $this->mapper()->toRecord(
            $this->creditmemo('CDI001', 1),
            $this->order([]),
            ['externalId' => 'rma:000000003', 'kind' => 'customer_return']
        );

        $this->assertSame([
            'externalId' => 'cm:200000001',
            'orderExternalId' => '100000007',
            'returnExternalId' => 'rma:000000003',
            'customer' => ['email' => 'jane@example.com'],
            'refundedAt' => '2026-09-15T08:00:00Z',
            'amount' => 45.5,
            'category' => 'empty_package_claim',
            'reasonText' => 'CDI001',
        ], $record);
    }

    public function testRefundedItemsAreTheOrderLinesAddedUpBySku(): void
    {
        $order = $this->order([], [
            $this->orderItem(['item_id' => 1, 'sku' => 'DRESS-M']),
            $this->orderItem(['item_id' => 2, 'sku' => 'DRESS-M', 'parent_item_id' => 1]),
            $this->orderItem(['item_id' => 3, 'sku' => 'BAG']),
        ]);

        $this->assertSame(
            [['sku' => 'DRESS-M', 'quantity' => 2]],
            $this->mapper()->refundedItems($this->refundedDresses(), $order)
        );
    }

    public function testRefundedLinesNameTheOrderItemsAndQuantitiesRefunded(): void
    {
        $this->assertSame('1x2+2x1', $this->mapper()->refundedLines($this->refundedDresses()));
        $this->assertSame('', $this->mapper()->refundedLines($this->creditmemo(null, 0)));
    }

    private function refundedDresses(): Creditmemo
    {
        $lines = [
            ['order_item_id' => 2, 'sku' => 'DRESS-M', 'qty' => 1],
            ['order_item_id' => 1, 'sku' => 'DRESS-M', 'qty' => 1],
            ['order_item_id' => 3, 'sku' => 'BAG', 'qty' => 0],
            ['order_item_id' => 1, 'sku' => 'DRESS-M', 'qty' => 1],
        ];
        $items = [];
        foreach ($lines as $line) {
            $item = $this->getMockBuilder(Item::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
            $item->setData($line);
            $items[] = $item;
        }
        $creditmemo = $this->getMockBuilder(Creditmemo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAllItems'])
            ->getMock();
        $creditmemo->method('getAllItems')->willReturn($items);
        return $creditmemo;
    }

    private function creditmemo(?string $code, float $quantity): Creditmemo
    {
        $item = $this->getMockBuilder(Item::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $item->setData('qty', $quantity);
        $creditmemo = $this->getMockBuilder(Creditmemo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAllItems'])
            ->getMock();
        $creditmemo->method('getAllItems')->willReturn([$item]);
        $creditmemo->setData([
            'increment_id' => '200000001',
            'created_at' => '2026-09-15 08:00:00',
            'base_grand_total' => 45.5,
            'reason_code' => $code,
        ]);
        return $creditmemo;
    }

    private function mapper(): RefundMapper
    {
        $config = $this->createMock(Config::class);
        $config->method('refundCodeField')->willReturn('reason_code');
        $config->method('refundCodeMap')->willReturn([
            'cdi001' => 'empty_package_claim',
            'rts' => 'return_to_sender',
            'rnd' => 'return_not_received_dispute',
            'zzz' => 'not_a_category',
        ]);
        $config->method('normalizeLabel')->willReturnCallback(static fn (string $label) => strtolower(trim($label)));
        $config->method('refundCategoryFor')->willReturnMap([
            ['with_return', 1, 'return'],
            ['adjustment_only', 1, 'commercial_gesture'],
            ['not_shipped', 1, 'merchant_error'],
            ['shipped', 1, 'item_not_received'],
        ]);
        $identity = new Identity($this->createMock(Config::class));
        $orderMapper = new OrderMapper(
            $identity,
            $this->createMock(ProductSkus::class),
            $this->createMock(StoreManagerInterface::class),
            $this->weight()
        );
        return new RefundMapper($config, $orderMapper, new RefundCategory());
    }
}
