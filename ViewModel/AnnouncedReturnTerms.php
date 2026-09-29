<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\ViewModel;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Sales\Model\Order;
use ReturnSentry\Connector\Model\OrderLoader;

class AnnouncedReturnTerms implements ArgumentInterface
{
    public function __construct(private readonly OrderLoader $orders)
    {
    }

    public function forOrderId(int $orderId): ?string
    {
        try {
            return $this->notice($this->orders->get($orderId));
        } catch (NoSuchEntityException) {
            return null;
        }
    }

    public function notice(Order $order): ?string
    {
        if ((int) $order->getData('returnsentry_return_paid') !== 1) {
            return null;
        }
        $labelCost = $order->getData('returnsentry_return_label_cost');
        if ($labelCost === null) {
            return (string) __('Returns for this order are at your own cost.');
        }
        return (string) __(
            'Returns for this order are at your own cost: the return label, %1, is deducted from your refund.',
            $order->getBaseCurrency()->formatTxt((float) $labelCost)
        );
    }
}
