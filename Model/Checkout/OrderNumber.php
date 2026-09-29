<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Checkout;

use Magento\Framework\App\ResourceConnection;
use Magento\Quote\Model\Quote;

class OrderNumber
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function reserve(Quote $quote): void
    {
        if ($quote->getReservedOrderId() || !$quote->getId()) {
            return;
        }
        $quote->reserveOrderId();
        $this->resource->getConnection()->update(
            $this->resource->getTableName('quote'),
            ['reserved_order_id' => $quote->getReservedOrderId()],
            ['entity_id = ?' => (int) $quote->getId(), 'reserved_order_id IS NULL']
        );
    }
}
