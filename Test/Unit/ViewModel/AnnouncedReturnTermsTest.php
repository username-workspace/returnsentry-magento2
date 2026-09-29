<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\ViewModel;

use Magento\Directory\Model\Currency;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Model\OrderLoader;
use ReturnSentry\Connector\ViewModel\AnnouncedReturnTerms;

class AnnouncedReturnTermsTest extends TestCase
{
    public function testFreeReturnsRenderNothing(): void
    {
        $this->assertNull($this->viewModel()->notice($this->order([])));
    }

    public function testPaidReturnsWithoutALabel(): void
    {
        $this->assertSame(
            'Returns for this order are at your own cost.',
            $this->viewModel()->notice($this->order(['returnsentry_return_paid' => '1']))
        );
    }

    public function testPaidReturnsCiteTheLabelCostInTheBaseCurrency(): void
    {
        $order = $this->order(['returnsentry_return_paid' => '1', 'returnsentry_return_label_cost' => '6.5000']);

        $this->assertSame(
            'Returns for this order are at your own cost: the return label, €6.50, is deducted from your refund.',
            $this->viewModel()->notice($order)
        );
    }

    public function testTheEmailBlockFindsTheOrderByItsId(): void
    {
        $orders = $this->createMock(OrderLoader::class);
        $orders->method('get')->willReturnCallback(fn (int $id) => $id === 42
            ? $this->order(['returnsentry_return_paid' => '1'])
            : throw NoSuchEntityException::singleField('entity_id', $id));
        $viewModel = new AnnouncedReturnTerms($orders);

        $this->assertSame('Returns for this order are at your own cost.', $viewModel->forOrderId(42));
        $this->assertNull($viewModel->forOrderId(0));
    }

    private function viewModel(): AnnouncedReturnTerms
    {
        return new AnnouncedReturnTerms($this->createMock(OrderLoader::class));
    }

    /**
     * @param array<string, string> $data
     */
    private function order(array $data): Order
    {
        $currency = $this->createMock(Currency::class);
        $currency->method('formatTxt')->willReturnCallback(static fn (float $price) => '€' . number_format($price, 2));
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBaseCurrency'])
            ->getMock();
        $order->setData($data);
        $order->method('getBaseCurrency')->willReturn($currency);
        return $order;
    }
}
