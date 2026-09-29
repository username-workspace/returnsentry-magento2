<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Returns;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

class RmaReader
{
    private const RECEIVED_STATUSES = ['received', 'received_on_item'];

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function isAvailable(): bool
    {
        return $this->connection()->isTableExists($this->resource->getTableName('magento_rma'));
    }

    /**
     * @param int[] $orderIds
     * @return array<int, int[]>
     */
    public function idsByOrder(array $orderIds): array
    {
        if ($orderIds === [] || !$this->isAvailable()) {
            return [];
        }
        $select = $this->connection()->select()
            ->from($this->resource->getTableName('magento_rma'), ['entity_id', 'order_id'])
            ->where('order_id IN (?)', $orderIds)
            ->order('entity_id');
        $byOrder = [];
        foreach ($this->connection()->fetchAll($select) as $row) {
            $byOrder[(int) $row['order_id']][] = (int) $row['entity_id'];
        }
        return $byOrder;
    }

    public function storeId(int $rmaId): ?int
    {
        $storeId = $this->connection()->fetchOne(
            $this->connection()->select()
                ->from($this->resource->getTableName('magento_rma'), ['store_id'])
                ->where('entity_id = ?', $rmaId)
        );
        return is_numeric($storeId) ? (int) $storeId : null;
    }

    /**
     * @return array{
     *     rma: array<string, mixed>,
     *     items: list<array{orderItemId: int, sku: ?string, quantity: int, reason: ?string}>,
     *     tracks: list<array{number: string, code: ?string, title: ?string}>
     * }|null
     */
    public function read(int $rmaId): ?array
    {
        $connection = $this->connection();
        $rma = $connection->fetchRow(
            $connection->select()
                ->from(
                    $this->resource->getTableName('magento_rma'),
                    ['order_id', 'increment_id', 'date_requested', 'status']
                )
                ->where('entity_id = ?', $rmaId)
        );
        if (!$rma) {
            return null;
        }
        $rma['received_at'] = $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('magento_rma_status_history'), ['MIN(created_at)'])
                ->where('rma_entity_id = ?', $rmaId)
                ->where('status IN (?)', self::RECEIVED_STATUSES)
        ) ?: null;
        $attributes = $connection->fetchPairs(
            $connection->select()
                ->from(['a' => $this->resource->getTableName('eav_attribute')], ['attribute_code', 'attribute_id'])
                ->join(
                    ['t' => $this->resource->getTableName('eav_entity_type')],
                    't.entity_type_id = a.entity_type_id',
                    []
                )
                ->where('t.entity_type_code = ?', 'rma_item')
                ->where('a.attribute_code IN (?)', ['reason', 'reason_other'])
        );
        $select = $connection->select()
            ->from(
                ['i' => $this->resource->getTableName('magento_rma_item_entity')],
                ['order_item_id', 'product_sku', 'qty_requested']
            )
            ->joinLeft(
                ['r' => $this->resource->getTableName('magento_rma_item_entity_int')],
                $connection->quoteInto(
                    'r.entity_id = i.entity_id AND r.attribute_id = ?',
                    (int) ($attributes['reason'] ?? 0)
                ),
                []
            )
            ->joinLeft(
                ['o' => $this->resource->getTableName('eav_attribute_option_value')],
                'o.option_id = r.value AND o.store_id = 0',
                ['reason' => 'value']
            )
            ->joinLeft(
                ['t' => $this->resource->getTableName('magento_rma_item_entity_varchar')],
                $connection->quoteInto(
                    't.entity_id = i.entity_id AND t.attribute_id = ?',
                    (int) ($attributes['reason_other'] ?? 0)
                ),
                ['reason_other' => 'value']
            )
            ->where('i.rma_entity_id = ?', $rmaId)
            ->order('i.entity_id');
        $items = array_map(
            static fn (array $row) => [
                'orderItemId' => (int) $row['order_item_id'],
                'sku' => $row['product_sku'] === null ? null : (string) $row['product_sku'],
                'quantity' => (int) $row['qty_requested'],
                'reason' => $row['reason'] ?? ($row['reason_other'] ? 'other' : null),
            ],
            $connection->fetchAll($select)
        );
        return ['rma' => $rma, 'items' => array_values($items), 'tracks' => $this->tracks($rmaId)];
    }

    /**
     * @return list<array{number: string, code: ?string, title: ?string}>
     */
    private function tracks(int $rmaId): array
    {
        $connection = $this->connection();
        $select = $connection->select()
            ->from(
                $this->resource->getTableName('magento_rma_shipping_label'),
                ['track_number', 'carrier_code', 'carrier_title']
            )
            ->where('rma_entity_id = ?', $rmaId)
            ->where('track_number IS NOT NULL')
            ->order('entity_id');
        return array_values(array_map(
            static fn (array $row) => [
                'number' => (string) $row['track_number'],
                'code' => $row['carrier_code'] === null ? null : (string) $row['carrier_code'],
                'title' => $row['carrier_title'] === null ? null : (string) $row['carrier_title'],
            ],
            $connection->fetchAll($select)
        ));
    }

    private function connection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }
}
