<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Sync;

use Magento\Framework\App\ResourceConnection;

class RetryStore
{
    private const TABLE = 'returnsentry_sync_retry';
    private const MAX_DELAY_MINUTES = 360;

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function schedule(string $type, string $id, int $storeId, int $attempts, string $error): void
    {
        $delayMinutes = min(self::MAX_DELAY_MINUTES, 2 ** max(0, $attempts - 1));
        $this->resource->getConnection()->insertOnDuplicate(
            $this->resource->getTableName(self::TABLE),
            [
                'entity_type' => $type,
                'entity_id' => $id,
                'store_id' => $storeId,
                'attempts' => $attempts,
                'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + $delayMinutes * 60),
                'last_error' => mb_substr($error, 0, 1000),
                'failed_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['store_id', 'attempts', 'next_attempt_at', 'last_error', 'failed_at']
        );
    }

    public function enqueue(string $type, string $id, int $storeId): void
    {
        $this->resource->getConnection()->insertOnDuplicate(
            $this->resource->getTableName(self::TABLE),
            [
                'entity_type' => $type,
                'entity_id' => $id,
                'store_id' => $storeId,
                'attempts' => 0,
                'next_attempt_at' => gmdate('Y-m-d H:i:s'),
                'last_error' => null,
            ],
            ['store_id', 'next_attempt_at']
        );
    }

    /**
     * @return array{count: int, lastError: ?string}
     */
    public function pending(string $type): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $count = $connection->fetchOne(
            $connection->select()->from($table, ['COUNT(*)'])->where('entity_type = ?', $type)
        );
        $lastError = $connection->fetchOne(
            $connection->select()
                ->from($table, ['last_error'])
                ->where('entity_type = ?', $type)
                ->where('last_error IS NOT NULL')
                ->order('failed_at DESC')
                ->limit(1)
        );
        return ['count' => (int) $count, 'lastError' => $lastError ?: null];
    }

    public function clear(string $type, string $id): void
    {
        $this->resource->getConnection()->delete(
            $this->resource->getTableName(self::TABLE),
            ['entity_type = ?' => $type, 'entity_id = ?' => $id]
        );
    }

    /**
     * @return list<array{entity_type: string, entity_id: string, store_id: int, attempts: int}>
     */
    public function due(int $limit): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE), ['entity_type', 'entity_id', 'store_id', 'attempts'])
            ->where('next_attempt_at <= ?', gmdate('Y-m-d H:i:s'))
            ->order('next_attempt_at')
            ->limit($limit);
        return array_values(array_map(
            static fn (array $row) => [
                'entity_type' => (string) $row['entity_type'],
                'entity_id' => (string) $row['entity_id'],
                'store_id' => (int) $row['store_id'],
                'attempts' => (int) $row['attempts'],
            ],
            $connection->fetchAll($select)
        ));
    }
}
