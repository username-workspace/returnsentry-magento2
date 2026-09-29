<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model;

use Magento\Framework\Exception\LocalizedException;
use ReturnSentry\Connector\Api\Data\Assessment;
use ReturnSentry\Connector\Api\Data\ReturnRequest;
use ReturnSentry\Connector\Api\ReturnReporterInterface;
use ReturnSentry\Connector\Model\Mapper\OrderMapper;
use ReturnSentry\Connector\Model\Returns\ReturnService;
use ReturnSentry\Connector\Model\Sync\Publisher;
use ReturnSentry\Connector\Model\Sync\Sender;

class ReturnReporter implements ReturnReporterInterface
{
    public function __construct(
        private readonly OrderLoader $orders,
        private readonly ReturnService $returns,
        private readonly OrderMapper $orderMapper,
        private readonly Publisher $publisher
    ) {
    }

    public function report(ReturnRequest $request): Assessment
    {
        if (trim($request->externalId) === '' || !in_array($request->status, ReturnRequest::STATUSES, true)) {
            throw new LocalizedException(__(
                'A return needs an external ID and one of these statuses: %1.',
                implode(', ', ReturnRequest::STATUSES)
            ));
        }
        $order = $this->orders->get($request->orderId);
        if ($this->orderMapper->hasErasedCustomer($order)) {
            return Assessment::notAssessed();
        }
        $externalId = $this->returns->record($request, $order);
        $this->publisher->publish(Sender::RETURN, $externalId, (int) $order->getStoreId());
        return $this->returns->storedAssessment($externalId);
    }
}
