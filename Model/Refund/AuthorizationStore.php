<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Refund;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\ResourceConnection\ConnectionFactory;
use Magento\Framework\Config\ConfigOptionsListConstants;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Serialize\Serializer\Json;

class AuthorizationStore
{
    public const RELEASED = 'released';
    public const OVERRIDDEN = 'overridden';
    public const MISCONFIGURED = 'misconfigured';

    private const TABLE = 'returnsentry_refund_authorization';

    private ?AdapterInterface $decisionConnection = null;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ConnectionFactory $connectionFactory,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly Json $json
    ) {
    }

    public function save(
        string $externalId,
        int $orderId,
        Decision $decision,
        bool $observed,
        ?string $category = null,
        ?string $claimedAt = null
    ): void {
        $this->write($this->decisionConnection(), $externalId, $orderId, [
            'decision' => $decision->decision,
            'requested_amount' => $decision->requestedAmount,
            'approved_amount' => $decision->approvedAmount,
            'observed' => (int) $observed,
            'fraud_evidence' => (int) $decision->fraudEvidence,
            'note' => null,
            'category' => $category,
            'claimed_at' => $this->utc($claimedAt),
            'hold_until' => $this->utc($decision->holdUntil),
            'carrier' => $decision->carrier === null ? null : $this->json->serialize($decision->carrier),
        ]);
    }

    public function claimedAt(string $externalId): ?string
    {
        return ($this->find($externalId)['claimed_at'] ?? null) ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $externalId): ?array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE))
                ->where('external_id = ?', $externalId)
        );
        return $row ?: null;
    }

    public function record(
        string $externalId,
        int $orderId,
        string $outcome,
        float $amount,
        string $note,
        ?string $decidedBy = null
    ): void {
        $this->write($this->resource->getConnection(), $externalId, $orderId, [
            'decision' => $outcome,
            'requested_amount' => $amount,
            'approved_amount' => $amount,
            'observed' => 0,
            'fraud_evidence' => 0,
            'note' => mb_substr($note, 0, 1000),
            'decided_by' => $decidedBy,
        ]);
    }

    public function markOverrideRejected(string $externalId, int $status): void
    {
        $this->resource->getConnection()->update(
            $this->resource->getTableName(self::TABLE),
            ['override_rejected' => $status],
            ['external_id = ?' => $externalId]
        );
    }

    /**
     * @return array{decidedBy: string, reason: string}|null
     */
    public function override(string $externalId): ?array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), ['decided_by', 'note'])
                ->where('external_id = ?', $externalId)
                ->where('decision = ?', self::OVERRIDDEN)
        );
        return $row ? ['decidedBy' => (string) $row['decided_by'], 'reason' => (string) $row['note']] : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestForOrder(int $orderId): ?array
    {
        return $this->forOrder($orderId)[0] ?? null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forOrder(int $orderId): array
    {
        $connection = $this->resource->getConnection();
        return $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE))
                ->where('order_id = ?', $orderId)
                ->order(['updated_at DESC', 'external_id DESC'])
        );
    }

    /**
     * The credit memo transaction rolls back when a decision stops it: decisions are written on their own connection.
     */
    private function decisionConnection(): AdapterInterface
    {
        if ($this->decisionConnection === null) {
            $config = $this->deploymentConfig->get(ConfigOptionsListConstants::CONFIG_PATH_DB_CONNECTIONS . '/default');
            $this->decisionConnection = $this->connectionFactory->create(is_array($config) ? $config : []);
        }
        return $this->decisionConnection;
    }

    private function utc(?string $isoDateTime): ?string
    {
        return $isoDateTime === null ? null : gmdate('Y-m-d H:i:s', (int) strtotime($isoDateTime));
    }

    /**
     * @param array<string, mixed> $values
     */
    private function write(AdapterInterface $connection, string $externalId, int $orderId, array $values): void
    {
        $connection->insertOnDuplicate(
            $this->resource->getTableName(self::TABLE),
            ['external_id' => $externalId, 'order_id' => $orderId] + $values,
            array_keys($values)
        );
    }
}
