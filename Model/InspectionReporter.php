<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use ReturnSentry\Connector\Api\Data\InspectionItem;
use ReturnSentry\Connector\Api\Data\InspectionRequest;
use ReturnSentry\Connector\Api\InspectionReporterInterface;
use ReturnSentry\Connector\Model\Mapper\Fields;
use ReturnSentry\Connector\Model\Mapper\OrderMapper;
use ReturnSentry\Connector\Model\Returns\InspectionStore;
use ReturnSentry\Connector\Model\Returns\ReturnStore;
use ReturnSentry\Connector\Model\Sync\Publisher;
use ReturnSentry\Connector\Model\Sync\Sender;

class InspectionReporter implements InspectionReporterInterface
{
    private const MAX_ID_LENGTH = 200;
    private const MAX_QUANTITY = 10000;
    private const MAX_URL_LENGTH = 2000;

    public function __construct(
        private readonly ReturnStore $returns,
        private readonly OrderLoader $orders,
        private readonly OrderMapper $orderMapper,
        private readonly InspectionStore $inspections,
        private readonly Publisher $publisher,
        private readonly ResourceConnection $resource
    ) {
    }

    public function report(InspectionRequest $request): void
    {
        if (!$this->isValid($request)) {
            throw new LocalizedException(__(
                'An inspection needs an external ID of at most %4 characters, 1 to %1 items each with a SKU of '
                . 'at most %4 characters, a quantity from 1 to %5 and one of these outcomes: %2, and at most %3 '
                . 'photo URLs of at most %6 characters.',
                InspectionRequest::MAX_ITEMS,
                implode(', ', InspectionItem::OUTCOMES),
                InspectionRequest::MAX_PHOTOS,
                self::MAX_ID_LENGTH,
                self::MAX_QUANTITY,
                self::MAX_URL_LENGTH
            ));
        }
        $orderId = $this->returns->orderId($request->returnExternalId);
        if ($orderId === null) {
            throw NoSuchEntityException::singleField('returnExternalId', $request->returnExternalId);
        }
        $order = $this->orders->get($orderId);
        if ($this->orderMapper->hasErasedCustomer($order)) {
            return;
        }
        $record = Fields::compact([
            'externalId' => $request->externalId,
            'returnExternalId' => $request->returnExternalId,
            'orderExternalId' => $order->getIncrementId(),
            'source' => 'warehouse',
            'inspectedAt' => Fields::isoFrom($request->inspectedAt),
            'items' => array_map(static fn (InspectionItem $item) => [
                'sku' => $item->sku,
                'quantity' => $item->quantity,
                'outcome' => $item->outcome,
            ], $request->items),
            'photos' => array_map(static fn (string $url) => ['url' => $url], $request->photoUrls),
            'note' => Fields::clip($request->note, 2000),
        ]);
        $connection = $this->resource->getConnection();
        $connection->beginTransaction();
        try {
            $this->inspections->save($orderId, $record);
            $this->publisher->publish(Sender::INSPECTION, $request->externalId, (int) $order->getStoreId());
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
    }

    private function isValid(InspectionRequest $request): bool
    {
        $isId = static fn (string $value): bool => trim($value) !== ''
            && mb_strlen(trim($value)) <= self::MAX_ID_LENGTH;
        $items = array_filter(
            $request->items,
            static fn (InspectionItem $item) => $isId($item->sku)
                && $item->quantity >= 1
                && $item->quantity <= self::MAX_QUANTITY
                && in_array($item->outcome, InspectionItem::OUTCOMES, true)
        );
        $photos = array_filter(
            $request->photoUrls,
            static fn (string $url) => preg_match('#^https?://#i', $url) === 1
                && filter_var($url, FILTER_VALIDATE_URL)
                && strlen($url) <= self::MAX_URL_LENGTH
        );
        return $isId($request->externalId)
            && $request->items !== []
            && count($request->items) <= InspectionRequest::MAX_ITEMS
            && count($items) === count($request->items)
            && count($request->photoUrls) <= InspectionRequest::MAX_PHOTOS
            && count($photos) === count($request->photoUrls);
    }
}
