<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use ReturnSentry\Connector\Api\CustomerErasureInterface;
use ReturnSentry\Connector\Model\Mapper\Identity;
use ReturnSentry\Connector\Model\Sync\Publisher;
use ReturnSentry\Connector\Model\Sync\Sender;

class CustomerErasure implements CustomerErasureInterface
{
    private const ORDER_TABLES = [
        'returnsentry_return',
        'returnsentry_refund_authorization',
        'returnsentry_inspection',
    ];

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ResourceConnection $resource,
        private readonly Identity $identity,
        private readonly Publisher $publisher
    ) {
    }

    public function eraseByEmail(string $email, int $websiteId): void
    {
        $email = trim($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new LocalizedException(__('An erasure needs the customer\'s email address.'));
        }
        $website = $this->storeManager->getWebsite($websiteId);
        $storeId = (int) $this->storeManager->getGroup((string) $website->getDefaultGroupId())->getDefaultStoreId();
        $connection = $this->resource->getConnection();
        $accounts = $connection->select()
            ->from($this->resource->getTableName('customer_entity'), 'entity_id')
            ->where('email = ?', $email);
        $accountIds = $connection->fetchCol($accounts);
        $this->eraseOrders($email, $accounts, $websiteId);
        $erased = $this->identity->customer(null, $email, null, $storeId)['email'] ?? $email;
        $this->publisher->publish(Sender::ERASURE, $websiteId . ':' . $erased, $storeId);
        foreach ($accountIds as $accountId) {
            $this->publisher->publish(Sender::ERASURE, (int) $accountId, $storeId);
        }
    }

    private function eraseOrders(string $email, Select $accounts, int $websiteId): void
    {
        $connection = $this->resource->getConnection();
        $stores = $connection->select()
            ->from($this->resource->getTableName('store'), 'store_id')
            ->where('website_id = ?', $websiteId);
        $orderIds = $connection->fetchCol(
            $connection->select()
                ->from($this->resource->getTableName('sales_order'), 'entity_id')
                ->where($connection->quoteInto('store_id IN (?)', $stores))
                ->where(
                    $connection->quoteInto('customer_email = ?', $email)
                    . ' OR ' . $connection->quoteInto('customer_id IN (?)', $accounts)
                )
        );
        if ($orderIds === []) {
            return;
        }
        $connection->update(
            $this->resource->getTableName('sales_order'),
            ['returnsentry_visitor_id' => null, 'returnsentry_erased' => 1],
            ['entity_id IN (?)' => $orderIds]
        );
        foreach (self::ORDER_TABLES as $table) {
            $connection->delete($this->resource->getTableName($table), ['order_id IN (?)' => $orderIds]);
        }
    }
}
