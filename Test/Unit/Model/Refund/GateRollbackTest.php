<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model\Refund;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\ResourceConnection\ConnectionFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Model\Order\Creditmemo;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReturnSentry\Connector\Model\Config;
use ReturnSentry\Connector\Model\Http\ApiException;
use ReturnSentry\Connector\Model\Http\Client;
use ReturnSentry\Connector\Model\Mapper\OrderMapper;
use ReturnSentry\Connector\Model\Mapper\RefundMapper;
use ReturnSentry\Connector\Model\Refund\AuthorizationStore;
use ReturnSentry\Connector\Model\Refund\CarrierSummary;
use ReturnSentry\Connector\Model\Refund\Claim;
use ReturnSentry\Connector\Model\Refund\Gate;
use ReturnSentry\Connector\Model\Refund\Override;
use ReturnSentry\Connector\Model\Returns\ReturnStore;
use ReturnSentry\Connector\Model\Sync\Publisher;
use ReturnSentry\Connector\Model\System\RefundControlAlert;
use ReturnSentry\Connector\Test\Unit\Fixtures;

class GateRollbackTest extends TestCase
{
    use Fixtures;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $committed = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $uncommitted = [];

    private bool $inSalesTransaction = false;

    public function testAFraudHoldSurvivesTheRolledBackCreditMemoAndKeepsAnOutageClosed(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('post')->willReturnOnConsecutiveCalls(
            [
                'decision' => 'hold',
                'requestedAmount' => 189,
                'approvedAmount' => 0,
                'reasons' => [['code' => 'inspection_fraud', 'label' => 'Empty parcel']],
                'fraudEvidence' => true,
            ],
            $this->throwException(new ApiException('timeout', true))
        );
        $gate = $this->gate($client);

        $first = $this->refundLikeCreditmemoService($gate);
        $second = $this->refundLikeCreditmemoService($gate);

        $this->assertStringContainsString('ReturnSentry holds this refund.', $first);
        $this->assertStringContainsString('its last decision holds this refund on fraud evidence', $second);
        $this->assertSame('1', (string) $this->committed['ra:100000007:adjustment']['fraud_evidence']);
    }

    private function refundLikeCreditmemoService(Gate $gate): string
    {
        $this->inSalesTransaction = true;
        try {
            $gate->authorize($this->creditmemo(), $this->order([]));
            $this->committed = $this->uncommitted + $this->committed;
            return 'refunded';
        } catch (LocalizedException $exception) {
            return $exception->getMessage();
        } finally {
            $this->uncommitted = [];
            $this->inSalesTransaction = false;
        }
    }

    private function gate(Client $client): Gate
    {
        $config = $this->createMock(Config::class);
        $config->method('mode')->willReturn(Config::ENFORCE);
        $config->method('apiUrl')->willReturn('https://rs.example.com');
        $refundMapper = $this->createMock(RefundMapper::class);
        $refundMapper->method('category')->willReturn('return');
        $returns = $this->createMock(ReturnStore::class);
        $returns->method('refundedBy')
            ->willReturn(['externalId' => 'rma:000000003', 'requestedAt' => '2026-09-02T09:12:00Z']);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection(fn () => $this->inSalesTransaction));
        $resource->method('getTableName')->willReturnArgument(0);
        $factory = $this->createMock(ConnectionFactory::class);
        $factory->method('create')->willReturn($this->connection(static fn () => false));
        $store = new AuthorizationStore($resource, $factory, $this->createMock(DeploymentConfig::class), new Json());
        return new Gate(
            $config,
            $client,
            $refundMapper,
            $this->createMock(OrderMapper::class),
            $returns,
            $store,
            $this->createMock(Override::class),
            new Claim($refundMapper, $store),
            new CarrierSummary(),
            $this->createMock(Publisher::class),
            $this->createMock(RefundControlAlert::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    private function connection(\Closure $inTransaction): AdapterInterface
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('insertOnDuplicate')->willReturnCallback(
            function (string $table, array $row) use ($inTransaction): int {
                if ($inTransaction()) {
                    $this->uncommitted[$row['external_id']] = $row;
                } else {
                    $this->committed[$row['external_id']] = $row;
                }
                return 1;
            }
        );
        $connection->method('fetchAll')->willReturnCallback(
            fn () => array_values($inTransaction() ? $this->uncommitted + $this->committed : $this->committed)
        );
        $connection->method('fetchRow')->willReturnCallback(
            fn () => current($inTransaction() ? $this->uncommitted + $this->committed : $this->committed)
        );
        return $connection;
    }

    private function creditmemo(): Creditmemo
    {
        $creditmemo = $this->getMockBuilder(Creditmemo::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $creditmemo->setData('base_grand_total', 189.0);
        return $creditmemo;
    }
}
