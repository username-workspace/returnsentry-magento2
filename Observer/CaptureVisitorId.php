<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use ReturnSentry\Connector\Model\Checkout\VisitorId;
use ReturnSentry\Connector\Model\Config;

class CaptureVisitorId implements ObserverInterface
{
    public function __construct(
        private readonly VisitorId $visitorId,
        private readonly Config $config
    ) {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');
        if (!$order instanceof Order || !$this->config->isConnected((int) $order->getStoreId())) {
            return;
        }
        $visitorId = $this->visitorId->current((int) $order->getStoreId());
        if ($visitorId !== null) {
            $order->setData('returnsentry_visitor_id', $visitorId);
        }
    }
}
