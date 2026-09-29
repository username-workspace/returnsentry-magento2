<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model;

use Magento\Framework\App\ResourceConnection;
use ReturnSentry\Connector\Model\Sync\Sender;

class Retention
{
    public const TRACKER_RETENTION_MONTHS = 13;
    private const AUTHORIZATIONS = 'returnsentry_refund_authorization';

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function purgeVisitorIds(): int
    {
        return $this->resource->getConnection()->update(
            $this->resource->getTableName('sales_order'),
            ['returnsentry_visitor_id' => null],
            [
                'returnsentry_visitor_id IS NOT NULL',
                'created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MONTH)' => self::TRACKER_RETENTION_MONTHS,
            ]
        );
    }

    public function deleteOrphanAuthorizations(): int
    {
        $connection = $this->resource->getConnection();
        $orders = $connection->select()->from($this->resource->getTableName('sales_order'), 'entity_id');
        return $connection->delete(
            $this->resource->getTableName(self::AUTHORIZATIONS),
            ['order_id NOT IN (?)' => $orders]
        );
    }

    public function deleteAbandonedInspections(): int
    {
        $connection = $this->resource->getConnection();
        $pending = $connection->select()
            ->from($this->resource->getTableName('returnsentry_sync_retry'), 'entity_id')
            ->where('entity_type = ?', Sender::INSPECTION);
        return $connection->delete(
            $this->resource->getTableName('returnsentry_inspection'),
            ['external_id NOT IN (?)' => $pending]
        );
    }
}
