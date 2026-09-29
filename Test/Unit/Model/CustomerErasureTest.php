<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\Group;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Model\Config;
use ReturnSentry\Connector\Model\CustomerErasure;
use ReturnSentry\Connector\Model\Mapper\Identity;
use ReturnSentry\Connector\Model\Sync\Publisher;
use ReturnSentry\Connector\Model\Sync\Sender;

class CustomerErasureTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    private Publisher&MockObject $publisher;
    private ?string $salt = null;

    /**
     * @var string[]
     */
    private array $accountIds = [];

    /**
     * @var string[]
     */
    private array $orderIds = ['7', '8'];

    /**
     * @var list<array{string, int|string, int}>
     */
    private array $published = [];

    protected function setUp(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('quoteInto')->willReturn('1');
        $this->publisher = $this->createMock(Publisher::class);
        $this->publisher->method('publish')->willReturnCallback(
            function (string $type, int|string $id, int $storeId): void {
                $this->published[] = [$type, $id, $storeId];
            }
        );
    }

    public function testQueuesTheErasureOfEveryAccountAtThatEmailForTheWebsiteConnection(): void
    {
        $this->erasure()->eraseByEmail(' jane@example.com ', 1);

        $this->assertSame([[Sender::ERASURE, '1:jane@example.com', 3]], $this->published);
    }

    public function testAnAccountAtThatEmailIsAlsoErasedByItsIdInCaseItsEmailChangesBeforeTheErasureLeaves(): void
    {
        $this->accountIds = ['42'];

        $this->erasure()->eraseByEmail('jane@example.com', 1);

        $this->assertSame([
            [Sender::ERASURE, '1:jane@example.com', 3],
            [Sender::ERASURE, 42, 3],
        ], $this->published);
    }

    public function testAPseudonymizingWebsiteErasesTheEmailToken(): void
    {
        $this->salt = str_repeat('s', 32);
        $token = (new Identity($this->createMock(Config::class)))->pseudonymousEmail('jane@example.com', $this->salt);

        $this->erasure()->eraseByEmail('jane@example.com', 1);

        $this->assertSame([[Sender::ERASURE, '1:' . $token, 3]], $this->published);
    }

    public function testClearsWhatTheModuleKeepsOnTheCustomersOrdersAndStopsSendingThem(): void
    {
        $this->connection->expects($this->once())
            ->method('update')
            ->with(
                'sales_order',
                ['returnsentry_visitor_id' => null, 'returnsentry_erased' => 1],
                ['entity_id IN (?)' => ['7', '8']]
            );
        $deleted = [];
        $this->connection->method('delete')->willReturnCallback(
            static function (string $table, array $where) use (&$deleted): int {
                $deleted[$table] = $where;
                return 1;
            }
        );

        $this->erasure()->eraseByEmail('jane@example.com', 1);

        $this->assertSame([
            'returnsentry_return' => ['order_id IN (?)' => ['7', '8']],
            'returnsentry_refund_authorization' => ['order_id IN (?)' => ['7', '8']],
            'returnsentry_inspection' => ['order_id IN (?)' => ['7', '8']],
        ], $deleted);
    }

    public function testACustomerWithoutOrdersIsStillErasedInReturnSentry(): void
    {
        $this->orderIds = [];
        $this->connection->expects($this->never())->method('update');
        $this->connection->expects($this->never())->method('delete');

        $this->erasure()->eraseByEmail('jane@example.com', 1);

        $this->assertCount(1, $this->published);
    }

    #[DataProvider('notEmails')]
    public function testAnErasureNeedsAnEmailAddress(string $value): void
    {
        try {
            $this->erasure()->eraseByEmail($value, 1);
            $this->fail('The erasure should have been refused');
        } catch (LocalizedException) {
            $this->assertSame([], $this->published);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notEmails(): array
    {
        return ['no at sign' => ['12'], 'no domain' => ['jane@'], 'two at signs' => ['jane@@example.com']];
    }

    private function erasure(): CustomerErasure
    {
        $this->connection->method('fetchCol')->willReturnOnConsecutiveCalls($this->accountIds, $this->orderIds);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $website = $this->createMock(Website::class);
        $website->method('getDefaultGroupId')->willReturn('2');
        $group = $this->createMock(Group::class);
        $group->method('getDefaultStoreId')->willReturn('3');
        $stores = $this->createMock(StoreManagerInterface::class);
        $stores->method('getWebsite')->with(1)->willReturn($website);
        $stores->method('getGroup')->with('2')->willReturn($group);
        $config = $this->createMock(Config::class);
        $config->method('pseudonymSalt')->with(3)->willReturnCallback(fn () => $this->salt);
        return new CustomerErasure($stores, $resource, new Identity($config), $this->publisher);
    }
}
