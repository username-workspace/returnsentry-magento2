<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Model\AbstractModel;
use ReturnSentry\Connector\Model\Returns\RmaReader;
use ReturnSentry\Connector\Model\Sync\Publisher;
use ReturnSentry\Connector\Model\Sync\Sender;

class PublishRmaTrack implements ObserverInterface
{
    public function __construct(
        private readonly Publisher $publisher,
        private readonly RmaReader $rmas
    ) {
    }

    public function execute(Observer $observer): void
    {
        $track = $observer->getEvent()->getData('object');
        if (!$track instanceof AbstractModel || !$track->getData('track_number')) {
            return;
        }
        $rmaId = (int) $track->getData('rma_entity_id');
        $storeId = $rmaId > 0 ? $this->rmas->storeId($rmaId) : null;
        if ($storeId !== null) {
            $this->publisher->publish(Sender::RMA, $rmaId, $storeId);
        }
    }
}
