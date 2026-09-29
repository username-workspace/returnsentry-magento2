<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Observer;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Model\AbstractModel;
use Magento\Store\Model\StoreManagerInterface;
use ReturnSentry\Connector\Model\Sync\Publisher;
use ReturnSentry\Connector\Model\Sync\Sender;

class PublishSync implements ObserverInterface
{
    private const EVENT_KEYS = [
        Sender::CUSTOMER => 'customer_data_object',
        Sender::ORDER => 'order',
        Sender::CREDITMEMO => 'creditmemo',
        Sender::RMA => 'rma',
        Sender::ERASURE => 'customer',
    ];

    public function __construct(
        private readonly Publisher $publisher,
        private readonly StoreManagerInterface $storeManager,
        private readonly string $type
    ) {
    }

    public function execute(Observer $observer): void
    {
        $entity = $observer->getEvent()->getData(self::EVENT_KEYS[$this->type] ?? $this->type);
        if ($entity instanceof CustomerInterface && $entity->getId()) {
            $storeId = $this->storeOf((int) $entity->getStoreId(), (int) $entity->getWebsiteId());
            $this->publisher->publish($this->type, (int) $entity->getId(), $storeId);
        } elseif ($entity instanceof AbstractModel && $entity->getId()) {
            $storeId = $this->storeOf((int) $entity->getData('store_id'), (int) $entity->getData('website_id'));
            $this->publisher->publish($this->type, (int) $entity->getId(), $storeId);
        }
    }

    private function storeOf(int $storeId, int $websiteId): int
    {
        if ($storeId > 0 || $websiteId === 0) {
            return $storeId;
        }
        $website = $this->storeManager->getWebsite($websiteId);
        return (int) $this->storeManager->getGroup((string) $website->getDefaultGroupId())->getDefaultStoreId();
    }
}
