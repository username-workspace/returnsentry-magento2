<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Observer;

use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Model\AbstractModel;
use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Model\Returns\RmaReader;
use ReturnSentry\Connector\Model\Sync\Publisher;
use ReturnSentry\Connector\Model\Sync\Sender;
use ReturnSentry\Connector\Observer\PublishRmaTrack;

class PublishRmaTrackTest extends TestCase
{
    public function testATrackingNumberAddedToAnRmaSendsTheReturnAgain(): void
    {
        $rmas = $this->createMock(RmaReader::class);
        $rmas->method('storeId')->with(4)->willReturn(2);
        $publisher = $this->createMock(Publisher::class);
        $publisher->expects($this->once())->method('publish')->with(Sender::RMA, 4, 2);

        $observer = new PublishRmaTrack($publisher, $rmas);

        $observer->execute($this->saved(['track_number' => '8R001', 'rma_entity_id' => 4]));
    }

    public function testOtherSavesAreIgnored(): void
    {
        $rmas = $this->createMock(RmaReader::class);
        $rmas->method('storeId')->willReturn(null);
        $publisher = $this->createMock(Publisher::class);
        $publisher->expects($this->never())->method('publish');
        $observer = new PublishRmaTrack($publisher, $rmas);

        $observer->execute($this->saved(['track_number' => '6A1', 'parent_id' => 3]));
        $observer->execute($this->saved(['track_number' => '', 'rma_entity_id' => 4]));
        $observer->execute($this->saved(['track_number' => '8R001', 'rma_entity_id' => 404]));
        $observer->execute(new Observer(['event' => new Event(['object' => new DataObject(['rma_entity_id' => 4])])]));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function saved(array $data): Observer
    {
        $model = $this->getMockBuilder(AbstractModel::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $model->setData($data);
        return new Observer(['event' => new Event(['object' => $model])]);
    }
}
