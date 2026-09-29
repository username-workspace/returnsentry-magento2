<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Returns;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Serialize\Serializer\Json;
use ReturnSentry\Connector\Api\Data\Assessment;

class ReturnStore
{
    private const TABLE = 'returnsentry_return';
    private const CLOSED_STATUSES = ['rejected', 'cancelled'];

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
        $externalId = (string) $record['externalId'];
        $receivedAt = $record['receivedAt'] ?? null;
        $columns = ['order_id', 'requested_at', 'record'];
        if ($receivedAt !== null && ($this->find($externalId)['record']['receivedAt'] ?? null) !== $receivedAt) {
            $columns[] = 'assessment';
        }
        $this->connection()->insertOnDuplicate(
            $this->resource->getTableName(self::TABLE),
            [
                'external_id' => $externalId,
                'order_id' => $orderId,
                'requested_at' => gmdate('Y-m-d H:i:s', (int) strtotime((string) $record['requestedAt'])),
                'record' => $this->json->serialize($record),
                'assessment' => null,
            ],
            $columns
        );
    }

    public function saveAssessment(string $externalId, Assessment $assessment): void
    {
        $this->connection()->update(
            $this->resource->getTableName(self::TABLE),
            ['assessment' => $this->json->serialize($assessment->toArray())],
            ['external_id = ?' => $externalId]
        );
    }

    /**
     * @return array{record: array<string, mixed>, assessment: ?Assessment}|null
     */
    public function find(string $externalId): ?array
    {
        $select = $this->connection()->select()
            ->from($this->resource->getTableName(self::TABLE), ['record', 'assessment'])
            ->where('external_id = ?', $externalId);
        $row = $this->connection()->fetchRow($select);
        return $row ? $this->hydrate($row) : null;
    }

    public function orderId(string $externalId): ?int
    {
        $orderId = $this->connection()->fetchOne(
            $this->connection()->select()
                ->from($this->resource->getTableName(self::TABLE), ['order_id'])
                ->where('external_id = ?', $externalId)
        );
        return $orderId === false ? null : (int) $orderId;
    }

    /**
     * @param string[] $skus
     * @return array<string, mixed>|null
     */
    public function refundedBy(int $orderId, array $skus): ?array
    {
        return $this->matching(array_column($this->forOrder($orderId), 'record'), $skus);
    }

    /**
     * @param array<array<string, mixed>> $returns
     * @param string[] $skus
     * @return array<string, mixed>|null
     */
    public function matching(array $returns, array $skus): ?array
    {
        $candidates = array_values(array_filter(
            $returns,
            static fn (array $return) => !in_array($return['status'] ?? null, self::CLOSED_STATUSES, true)
                && ($skus === [] || array_intersect($skus, array_column($return['items'] ?? [], 'sku')) !== [])
        ));
        usort($candidates, static fn (array $a, array $b) => [empty($a['receivedAt']), (string) $a['requestedAt']]
            <=> [empty($b['receivedAt']), (string) $b['requestedAt']]);
        return $candidates[0] ?? null;
    }

    /**
     * @return list<array{record: array<string, mixed>, assessment: ?Assessment}>
     */
    public function forOrder(int $orderId): array
    {
        $select = $this->connection()->select()
            ->from($this->resource->getTableName(self::TABLE), ['record', 'assessment'])
            ->where('order_id = ?', $orderId)
            ->order('requested_at DESC');
        return array_map(fn (array $row) => $this->hydrate($row), $this->connection()->fetchAll($select));
    }

    /**
     * @param array<string, mixed> $row
     * @return array{record: array<string, mixed>, assessment: ?Assessment}
     */
    private function hydrate(array $row): array
    {
        $record = $this->json->unserialize((string) $row['record']);
        $assessment = $row['assessment'] ? $this->json->unserialize((string) $row['assessment']) : null;
        return [
            'record' => is_array($record) ? $record : [],
            'assessment' => is_array($assessment) ? Assessment::fromArray($assessment) : null,
        ];
    }

    private function connection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }
}
