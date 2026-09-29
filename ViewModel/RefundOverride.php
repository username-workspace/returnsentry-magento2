<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\ViewModel;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use ReturnSentry\Connector\Model\Config;
use ReturnSentry\Connector\Model\OrderLoader;

class RefundOverride implements ArgumentInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly OrderLoader $orders,
        private readonly Config $config
    ) {
    }

    public function isAvailable(): bool
    {
        try {
            $order = $this->orders->get((int) $this->request->getParam('order_id'));
        } catch (NoSuchEntityException) {
            return false;
        }
        return $this->config->mode(Config::REFUND_AUTHORIZATION, (int) $order->getStoreId()) === Config::ENFORCE;
    }
}
