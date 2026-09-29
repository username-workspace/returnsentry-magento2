<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model\Sync;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\CustomerRegistry;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Shipment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Model\Config;
use ReturnSentry\Connector\Model\Http\ApiException;
use ReturnSentry\Connector\Model\Http\Client;
use ReturnSentry\Connector\Model\Mapper\Identity;
use ReturnSentry\Connector\Model\Mapper\OrderMapper;
use ReturnSentry\Connector\Model\Mapper\RefundMapper;
use ReturnSentry\Connector\Model\Mapper\ShipmentMapper;
use ReturnSentry\Connector\Model\OrderLoader;
use ReturnSentry\Connector\Model\Refund\AuthorizationStore;
use ReturnSentry\Connector\Model\Returns\InspectionStore;
use ReturnSentry\Connector\Model\Returns\ReturnService;
use ReturnSentry\Connector\Model\Returns\ReturnStore;
use ReturnSentry\Connector\Model\Sync\Sender;
use ReturnSentry\Connector\Test\Unit\Fixtures;

class SenderTest extends TestCase
{
    use Fixtures;

    private Client&MockObject $client;
    private OrderMapper&MockObject $orderMapper;
    private ShipmentMapper&MockObject $shipmentMapper;
    private AuthorizationStore&MockObject $authorizations;
    private CustomerRepositoryInterface&MockObject $customers;
    private CustomerRegistry&MockObject $registry;
    private InspectionStore&MockObject $inspections;

    protected function setUp(): void
    {
        $this->inspections = $this->createMock(InspectionStore::class);
        $this->client = $this->createMock(Client::class);
        $this->orderMapper = $this->createMock(OrderMapper::class);
        $this->shipmentMapper = $this->createMock(ShipmentMapper::class);
        $this->authorizations = $this->createMock(AuthorizationStore::class);
        $this->customers = $this->createMock(CustomerRepositoryInterface::class);
        $this->registry = $this->createMock(CustomerRegistry::class);
    }

    public function testSendsAnAdministratorOverrideAsTheShopDecision(): void
    {
        $this->authorizations->method('override')->with('ra:100000007:1')
            ->willReturn(['decidedBy' => 'jane', 'reason' => 'Parcel found by the carrier']);
        $this->client->expects($this->once())
            ->method('post')
            ->with('/v1/refund-authorizations/ra%3A100000007%3A1/decision', [
                'decision' => 'approve',
                'decidedBy' => 'jane',
                'reason' => 'Parcel found by the carrier',
            ], 1)
            ->willReturn(['decision' => 'approve']);

        $this->sender()->send(Sender::OVERRIDE, 'ra:100000007:1', 1);
    }

    public function testARefusalIsFlaggedWhileAnOutageIsNot(): void
    {
        $this->authorizations->method('override')
            ->willReturn(['decidedBy' => 'jane', 'reason' => 'Parcel found by the carrier']);
        $this->authorizations->expects($this->exactly(2))
            ->method('markOverrideRejected')
            ->willReturnCallback(fn (string $externalId, int $status) => $this->assertContains($status, [409, 401]));
        $this->client->method('post')->willReturnOnConsecutiveCalls(
            $this->throwException(new ApiException('ReturnSentry refused the request: already approved', false, 409)),
            $this->throwException(new ApiException('ReturnSentry refused the request: HTTP 401', false, 401)),
            $this->throwException(new ApiException('ReturnSentry refused the request: HTTP 503', true, 503))
        );

        foreach ([false, true, true] as $retryable) {
            try {
                $this->sender()->send(Sender::OVERRIDE, 'ra:100000007:1', 1);
                $this->fail('The send should fail');
            } catch (ApiException $exception) {
                $this->assertSame($retryable, $exception->retryable());
            }
        }
    }

    public function testASentOverrideClearsAnEarlierRefusal(): void
    {
        $this->authorizations->method('override')
            ->willReturn(['decidedBy' => 'jane', 'reason' => 'Parcel found by the carrier']);
        $this->authorizations->expects($this->once())->method('markOverrideRejected')->with('ra:100000007:1', 0);
        $this->client->method('post')->willReturn([]);

        $this->sender()->send(Sender::OVERRIDE, 'ra:100000007:1', 1);
    }

    public function testAnOverrideMissingLocallyIsDropped(): void
    {
        $this->authorizations->method('override')->willReturn(null);
        $this->client->expects($this->never())->method('post');

        $this->expectException(NoSuchEntityException::class);
        $this->sender()->send(Sender::OVERRIDE, 'ra:100000007:1', 1);
    }

    public function testSendsTheCustomerAsStoredNowNotAsThisProcessFirstLoadedIt(): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getEmail')->willReturn('anonymized-12@example.com');
        $loaded = [];
        $this->registry->method('remove')->willReturnCallback(static function (int $id) use (&$loaded): void {
            $loaded[] = 'forget ' . $id;
        });
        $this->customers->method('getById')->willReturnCallback(
            static function (int $id) use (&$loaded, $customer): CustomerInterface {
                $loaded[] = 'load ' . $id;
                return $customer;
            }
        );
        $this->client->expects($this->once())
            ->method('post')
            ->with('/v1/customers', $this->callback(
                static fn (array $record): bool => $record['email'] === 'anonymized-12@example.com'
            ), 1);

        $this->sender()->send(Sender::CUSTOMER, '12', 1);

        $this->assertSame(['forget 12', 'load 12'], $loaded);
    }

    public function testForwardsACustomerDeletionAsAnErasure(): void
    {
        $this->client->expects($this->once())
            ->method('delete')
            ->with('/v1/customers', ['externalId' => 'm2:12'], 1);

        $this->sender()->send(Sender::ERASURE, '12', 1);
    }

    public function testForwardsAnErasureByEmailToEveryAccountAtThatEmail(): void
    {
        $this->client->expects($this->once())
            ->method('delete')
            ->with('/v1/customers', ['email' => 'jane@example.com'], 3);

        $this->sender()->send(Sender::ERASURE, '1:jane@example.com', 3);
    }

    public function testDoesNotSendTheCreditMemoOfAnErasedCustomer(): void
    {
        $this->orderMapper->method('hasErasedCustomer')->willReturn(true);
        $this->client->expects($this->never())->method('post');

        $this->sender()->send(Sender::CREDITMEMO, '3', 1);
    }

    public function testSendsAStoredInspectionThenForgetsIt(): void
    {
        $record = ['externalId' => 'CTL-1', 'returnExternalId' => 'rma:000000003'];
        $this->inspections->method('find')->with('CTL-1')->willReturn(['orderId' => 7, 'record' => $record]);
        $this->client->expects($this->once())->method('post')->with('/v1/inspections', $record, 1)->willReturn([]);
        $this->inspections->expects($this->once())->method('delete')->with('CTL-1');

        $this->sender()->send(Sender::INSPECTION, 'CTL-1', 1);
    }

    public function testAnInspectionReturnSentryRefusesIsForgottenWhileARefusedKeyOrAnOutageIsRetried(): void
    {
        $this->inspections->method('find')->willReturn(['orderId' => 7, 'record' => ['externalId' => 'CTL-1']]);
        $this->inspections->expects($this->once())->method('delete')->with('CTL-1');
        $this->client->method('post')->willReturnOnConsecutiveCalls(
            $this->throwException(new ApiException('ReturnSentry refused the request: HTTP 400', false, 400)),
            $this->throwException(new ApiException('ReturnSentry refused the request: HTTP 401', false, 401)),
            $this->throwException(new ApiException('ReturnSentry refused the request: HTTP 404', false, 404)),
            $this->throwException(new ApiException('ReturnSentry refused the request: HTTP 503', true, 503))
        );

        foreach ([false, true, true, true] as $retryable) {
            try {
                $this->sender()->send(Sender::INSPECTION, 'CTL-1', 1);
                $this->fail('The send should fail');
            } catch (ApiException $exception) {
                $this->assertSame($retryable, $exception->retryable());
            }
        }
    }

    public function testTheInspectionOfAnErasedCustomerIsForgottenUnsent(): void
    {
        $this->inspections->method('find')->willReturn(['orderId' => 7, 'record' => ['externalId' => 'CTL-1']]);
        $this->orderMapper->method('hasErasedCustomer')->willReturn(true);
        $this->client->expects($this->never())->method('post');
        $this->inspections->expects($this->once())->method('delete')->with('CTL-1');

        $this->sender()->send(Sender::INSPECTION, 'CTL-1', 1);
    }

    public function testAnInspectionNoLongerStoredIsNotSent(): void
    {
        $this->inspections->method('find')->willReturn(null);
        $this->client->expects($this->never())->method('post');

        $this->sender()->send(Sender::INSPECTION, 'CTL-1', 1);
    }

    public function testSendsEachParcelOfAShipment(): void
    {
        $parcels = [
            ['trackingNumber' => '6A1', 'carrier' => 'colissimo'],
            ['trackingNumber' => '6A2', 'carrier' => 'ups'],
        ];
        $this->shipmentMapper->method('toRecords')->willReturn($parcels);
        $sent = [];
        $this->client->expects($this->exactly(2))
            ->method('post')
            ->willReturnCallback(static function (string $path, array $body) use (&$sent): array {
                $sent[] = [$path, $body['trackingNumber']];
                return [];
            });

        $this->sender()->send(Sender::SHIPMENT, '5', 1);

        $this->assertSame([['/v1/shipments', '6A1'], ['/v1/shipments', '6A2']], $sent);
    }

    private function sender(): Sender
    {
        $creditmemo = $this->getMockBuilder(Creditmemo::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $creditmemo->setData('order_id', 7);
        $creditmemos = $this->createMock(CreditmemoRepositoryInterface::class);
        $creditmemos->method('get')->willReturn($creditmemo);
        $shipment = $this->getMockBuilder(Shipment::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $shipment->setData('order_id', 7);
        $shipments = $this->createMock(ShipmentRepositoryInterface::class);
        $shipments->method('get')->willReturn($shipment);
        $orders = $this->createMock(OrderLoader::class);
        $orders->method('get')->willReturn($this->order([]));
        return new Sender(
            $this->client,
            $this->customers,
            $this->registry,
            $orders,
            $creditmemos,
            new Identity($this->createMock(Config::class)),
            $this->orderMapper,
            $this->createMock(RefundMapper::class),
            $this->createMock(ReturnService::class),
            $this->createMock(ReturnStore::class),
            $shipments,
            $this->shipmentMapper,
            $this->authorizations,
            $this->inspections
        );
    }
}
