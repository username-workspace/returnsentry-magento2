<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;

class OrderLoader
{
    public function __construct(
        private readonly OrderFactory $orderFactory,
        private readonly OrderResource $orderResource
    ) {
    }

    /**
     * @throws NoSuchEntityException
     */
    public function get(int $orderId): Order
    {
        $order = $this->orderFactory->create();
        $this->orderResource->load($order, $orderId);
        if (!$order->getEntityId()) {
            throw NoSuchEntityException::singleField('entity_id', $orderId);
        }
        return $order;
    }
}
