<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Api\Data\InspectionItem;
use ReturnSentry\Connector\Api\Data\InspectionRequest;
use ReturnSentry\Connector\Model\InspectionReporter;
use ReturnSentry\Connector\Model\Mapper\OrderMapper;
use ReturnSentry\Connector\Model\OrderLoader;
use ReturnSentry\Connector\Model\Returns\InspectionStore;
use ReturnSentry\Connector\Model\Returns\ReturnStore;
use ReturnSentry\Connector\Model\Sync\Publisher;
use ReturnSentry\Connector\Model\Sync\Sender;
use ReturnSentry\Connector\Test\Unit\Fixtures;

class InspectionReporterTest extends TestCase
{
    use Fixtures;

    private InspectionStore&MockObject $inspections;
    private Publisher&MockObject $publisher;
    private AdapterInterface&MockObject $connection;
    private bool $erased = false;

    protected function setUp(): void
    {
        $this->inspections = $this->createMock(InspectionStore::class);
        $this->publisher = $this->createMock(Publisher::class);
        $this->connection = $this->createMock(AdapterInterface::class);
    }

    public function testAWarehouseInspectionIsKeptThenQueuedForTheReturnTheModuleKnows(): void
    {
        $this->inspections->expects($this->once())->method('save')->with(7, [
            'externalId' => 'CTL-1',
            'returnExternalId' => 'rma:000000003',
            'orderExternalId' => '100000007',
            'source' => 'warehouse',
            'inspectedAt' => '2026-09-20T08:30:00Z',
            'items' => [
                ['sku' => 'DRESS-M', 'quantity' => 1, 'outcome' => 'wrong_item'],
                ['sku' => 'BAG', 'quantity' => 2, 'outcome' => 'conforming'],
            ],
            'photos' => [['url' => 'https://warehouse.example.com/ctl-1.jpg']],
            'note' => 'Code 2 on line 14',
        ]);
        $this->publisher->expects($this->once())->method('publish')->with(Sender::INSPECTION, 'CTL-1', 1);

        $this->reporter()->report(new InspectionRequest(
            externalId: 'CTL-1',
            returnExternalId: 'rma:000000003',
            inspectedAt: new \DateTimeImmutable('2026-09-20 10:30:00', new \DateTimeZone('Europe/Paris')),
            items: [new InspectionItem('DRESS-M', 1, 'wrong_item'), new InspectionItem('BAG', 2, 'conforming')],
            note: 'Code 2 on line 14',
            photoUrls: ['https://warehouse.example.com/ctl-1.jpg']
        ));
    }

    public function testAnInspectionIsKeptOnlyWithItsQueuedSend(): void
    {
        $this->connection->expects($this->once())->method('beginTransaction');
        $this->publisher->method('publish')->willThrowException(new \RuntimeException('Lock wait timeout'));
        $this->connection->expects($this->once())->method('rollBack');
        $this->connection->expects($this->never())->method('commit');
        $this->expectException(\RuntimeException::class);

        $this->reporter()->report($this->request());
    }

    public function testAReturnTheModuleDoesNotKnowIsRefused(): void
    {
        $this->inspections->expects($this->never())->method('save');
        $this->expectException(NoSuchEntityException::class);

        $this->reporter()->report($this->request(returnExternalId: 'rma:unknown'));
    }

    public function testNothingIsSentForAnErasedCustomer(): void
    {
        $this->erased = true;
        $this->inspections->expects($this->never())->method('save');
        $this->publisher->expects($this->never())->method('publish');

        $this->reporter()->report($this->request());
    }

    /**
     * @return array<string, array{InspectionRequest}>
     */
    public static function invalidRequests(): array
    {
        return [
            'no external ID' => [self::request(externalId: ' ')],
            'no item' => [self::request(items: [])],
            'no quantity' => [self::request(items: [new InspectionItem('DRESS-M', 0, 'conforming')])],
            'no SKU' => [self::request(items: [new InspectionItem('', 1, 'conforming')])],
            'unknown outcome' => [self::request(items: [new InspectionItem('DRESS-M', 1, 'damaged')])],
            'photo that is not a URL' => [self::request(photoUrls: ['ctl-1.jpg'])],
            'too many photos' => [self::request(photoUrls: array_fill(0, 31, 'https://w.example/p.jpg'))],
            'external ID too long' => [self::request(externalId: str_repeat('C', 201))],
            'SKU too long' => [self::request(items: [new InspectionItem(str_repeat('S', 201), 1, 'conforming')])],
            'quantity too large' => [self::request(items: [new InspectionItem('DRESS-M', 10001, 'conforming')])],
            'photo URL too long' => [self::request(photoUrls: ['https://w.example/' . str_repeat('p', 1990)])],
        ];
    }

    #[DataProvider('invalidRequests')]
    public function testAnInspectionTheApiWouldRefuseIsRefusedBeforeItIsQueued(InspectionRequest $request): void
    {
        $this->publisher->expects($this->never())->method('publish');
        $this->expectException(LocalizedException::class);

        $this->reporter()->report($request);
    }

    /**
     * @param InspectionItem[]|null $items
     * @param string[] $photoUrls
     */
    private static function request(
        string $externalId = 'CTL-1',
        string $returnExternalId = 'rma:000000003',
        ?array $items = null,
        array $photoUrls = []
    ): InspectionRequest {
        return new InspectionRequest(
            $externalId,
            $returnExternalId,
            new \DateTimeImmutable('2026-09-20T08:30:00Z'),
            $items ?? [new InspectionItem('DRESS-M', 1, 'conforming')],
            photoUrls: $photoUrls
        );
    }

    private function reporter(): InspectionReporter
    {
        $returns = $this->createMock(ReturnStore::class);
        $returns->method('orderId')
            ->willReturnCallback(static fn (string $externalId) => $externalId === 'rma:000000003' ? 7 : null);
        $orders = $this->createMock(OrderLoader::class);
        $orders->method('get')->with(7)->willReturn($this->order([]));
        $orderMapper = $this->createMock(OrderMapper::class);
        $orderMapper->method('hasErasedCustomer')->willReturnCallback(fn () => $this->erased);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        return new InspectionReporter(
            $returns,
            $orders,
            $orderMapper,
            $this->inspections,
            $this->publisher,
            $resource
        );
    }
}
