<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Plugin;

use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\RefundAdapterInterface;
use ReturnSentry\Connector\Exception\RefundStoppedException;
use ReturnSentry\Connector\Model\Refund\Gate;

class AuthorizeRefund
{
    public function __construct(private readonly Gate $gate)
    {
    }

    /**
     * @throws RefundStoppedException
     */
    public function beforeRefund(
        RefundAdapterInterface $subject,
        CreditmemoInterface $creditmemo,
        OrderInterface $order
    ): void {
        if ($creditmemo instanceof Creditmemo && $order instanceof Order) {
            $this->gate->authorize($creditmemo, $order);
        }
    }
}
