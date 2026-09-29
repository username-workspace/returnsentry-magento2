<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model\Returns;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Model\Returns\ReturnStore;

class ReturnStoreTest extends TestCase
{
    public function testTheRefundedReturnIsTheOldestReceivedOneHoldingTheRefundedItems(): void
    {
        $returns = [
            ['externalId' => 'rma:2', 'status' => 'requested', 'requestedAt' => '2026-09-10T00:00:00Z',
                'items' => [['sku' => 'A']]],
            ['externalId' => 'rma:1', 'status' => 'received', 'requestedAt' => '2026-09-01T00:00:00Z',
                'receivedAt' => '2026-09-05T00:00:00Z', 'items' => [['sku' => 'A']]],
            ['externalId' => 'rma:0', 'status' => 'rejected', 'requestedAt' => '2026-08-01T00:00:00Z',
                'receivedAt' => '2026-08-03T00:00:00Z', 'items' => [['sku' => 'A']]],
            ['externalId' => 'rma:3', 'status' => 'received', 'requestedAt' => '2026-08-20T00:00:00Z',
                'receivedAt' => '2026-08-22T00:00:00Z', 'items' => [['sku' => 'B']]],
        ];
        $store = new ReturnStore($this->createMock(ResourceConnection::class), new Json());

        $this->assertSame('rma:1', $store->matching($returns, ['A'])['externalId'] ?? null);
        $this->assertSame('rma:3', $store->matching($returns, [])['externalId'] ?? null);
        $this->assertNull($store->matching($returns, ['C']));
    }

    /**
     * @return array<string, array{?string, string|false, bool}>
     */
    public static function receptions(): array
    {
        $requested = '{"externalId":"rma:1","requestedAt":"2026-09-01T00:00:00Z"}';
        $received = '{"externalId":"rma:1","requestedAt":"2026-09-01T00:00:00Z","receivedAt":"2026-09-05T00:00:00Z"}';
        return [
            'parcel just received' => ['2026-09-05T00:00:00Z', $requested, true],
            'reception already known' => ['2026-09-05T00:00:00Z', $received, false],
            'first report, already received' => ['2026-09-05T00:00:00Z', false, true],
            'not received yet' => [null, $requested, false],
        ];
    }

    #[DataProvider('receptions')]
    public function testTheReceptionOfTheParcelCallsForANewAssessment(
        ?string $receivedAt,
        string|false $storedRecord,
        bool $reassess
    ): void {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturn(
            $storedRecord === false ? false : ['record' => $storedRecord, 'assessment' => '{"action":"allow"}']
        );
        $connection->expects($this->once())
            ->method('insertOnDuplicate')
            ->with(
                'returnsentry_return',
                $this->callback(static fn (array $row) => $row['assessment'] === null),
                $reassess
                    ? ['order_id', 'requested_at', 'record', 'assessment']
                    : ['order_id', 'requested_at', 'record']
            );
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $record = ['externalId' => 'rma:1', 'requestedAt' => '2026-09-01T00:00:00Z'];

        (new ReturnStore($resource, new Json()))->save(7, $record + array_filter(['receivedAt' => $receivedAt]));
    }
}
