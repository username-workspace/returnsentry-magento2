<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Model\Checkout\VisitorId;
use ReturnSentry\Connector\Model\Config;
use ReturnSentry\Connector\Observer\CaptureVisitorId;

class CaptureVisitorIdTest extends TestCase
{
    public function testAConnectedStoreViewKeepsTheConsentedVisitorId(): void
    {
        $this->assertSame('k3Jd9QxW2bL0pZ7aR4tY1g', $this->capture(true)->getData('returnsentry_visitor_id'));
    }

    public function testAStoreViewNotConnectedKeepsNothing(): void
    {
        $this->assertNull($this->capture(false)->getData('returnsentry_visitor_id'));
    }

    private function capture(bool $connected): Order
    {
        $order = $this->getMockBuilder(Order::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $order->setData('store_id', 1);
        $config = $this->createMock(Config::class);
        $config->method('isConnected')->with(1)->willReturn($connected);
        $visitorId = $this->createMock(VisitorId::class);
        $visitorId->method('current')->willReturn('k3Jd9QxW2bL0pZ7aR4tY1g');

        (new CaptureVisitorId($visitorId, $config))->execute(new Observer(['event' => new Event(['order' => $order])]));

        return $order;
    }
}
