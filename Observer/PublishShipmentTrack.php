<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Shipment\Track;
use ReturnSentry\Connector\Model\Sync\Publisher;
use ReturnSentry\Connector\Model\Sync\Sender;

class PublishShipmentTrack implements ObserverInterface
{
    public function __construct(private readonly Publisher $publisher)
    {
    }

    public function execute(Observer $observer): void
    {
        $track = $observer->getEvent()->getData('track');
        if ($track instanceof Track && $track->getParentId()) {
            $this->publisher->publish(Sender::SHIPMENT, (int) $track->getParentId(), (int) $track->getStoreId());
        }
    }
}
