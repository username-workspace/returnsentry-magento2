<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Observer;

use Magento\Customer\Model\Customer;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Store\Api\Data\GroupInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Model\Sync\Publisher;
use ReturnSentry\Connector\Model\Sync\Sender;
use ReturnSentry\Connector\Observer\PublishSync;

class PublishSyncTest extends TestCase
{
    public function testACustomerWithoutStoreIsErasedWithItsWebsiteKeys(): void
    {
        $customer = $this->getMockBuilder(Customer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $customer->method('getId')->willReturn(12);
        $customer->setData(['store_id' => 0, 'website_id' => 2]);
        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getDefaultGroupId')->willReturn(3);
        $group = $this->createMock(GroupInterface::class);
        $group->method('getDefaultStoreId')->willReturn(7);
        $stores = $this->createMock(StoreManagerInterface::class);
        $stores->method('getWebsite')->with(2)->willReturn($website);
        $stores->method('getGroup')->with('3')->willReturn($group);
        $publisher = $this->createMock(Publisher::class);
        $publisher->expects($this->once())->method('publish')->with(Sender::ERASURE, 12, 7);

        (new PublishSync($publisher, $stores, Sender::ERASURE))
            ->execute(new Observer(['event' => new Event(['customer' => $customer])]));
    }
}
