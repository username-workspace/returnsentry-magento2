<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Returns;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\Serializer\Json;

class InspectionStore
{
    private const TABLE = 'returnsentry_inspection';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Json $json
    ) {
    }

    /**
     * @param array<string, mixed> $record
     */
    public function save(int $orderId, array $record): void
    {
        $this->resource->getConnection()->insertOnDuplicate(
            $this->resource->getTableName(self::TABLE),
            [
                'external_id' => (string) $record['externalId'],
                'order_id' => $orderId,
                'record' => $this->json->serialize($record),
            ],
            ['order_id', 'record']
        );
    }

    /**
     * @return array{orderId: int, record: array<string, mixed>}|null
     */
    public function find(string $externalId): ?array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), ['order_id', 'record'])
                ->where('external_id = ?', $externalId)
        );
        $record = $row ? $this->json->unserialize((string) $row['record']) : null;
        return is_array($record) ? ['orderId' => (int) $row['order_id'], 'record' => $record] : null;
    }

    public function delete(string $externalId): void
    {
        $this->resource->getConnection()->delete(
            $this->resource->getTableName(self::TABLE),
            ['external_id = ?' => $externalId]
        );
    }
}
