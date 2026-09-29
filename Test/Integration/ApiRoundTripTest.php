<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Integration;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\HTTP\Header;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\Item as ShipmentItem;
use Magento\Sales\Model\Order\Shipment\Track;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReturnSentry\Connector\Api\Data\ReturnItem;
use ReturnSentry\Connector\Api\Data\ReturnRequest;
use ReturnSentry\Connector\Api\Data\ReturnShipment;
use ReturnSentry\Connector\Model\Checkout\Assessor;
use ReturnSentry\Connector\Model\Checkout\OrderNumber;
use ReturnSentry\Connector\Model\Checkout\VisitorId;
use ReturnSentry\Connector\Model\Config;
use ReturnSentry\Connector\Model\Http\ApiException;
use ReturnSentry\Connector\Model\Http\Client;
use ReturnSentry\Connector\Model\Http\CurlTransport;
use ReturnSentry\Connector\Model\Mapper\Carrier;
use ReturnSentry\Connector\Model\Mapper\Identity;
use ReturnSentry\Connector\Model\Mapper\OrderMapper;
use ReturnSentry\Connector\Model\Mapper\ProductSkus;
use ReturnSentry\Connector\Model\Mapper\RefundMapper;
use ReturnSentry\Connector\Model\Mapper\ReturnMapper;
use ReturnSentry\Connector\Model\Mapper\ShipmentMapper;
use ReturnSentry\Connector\Model\OrderLoader;
use ReturnSentry\Connector\Model\Refund\AuthorizationStore;
use ReturnSentry\Connector\Model\Refund\CarrierSummary;
use ReturnSentry\Connector\Model\Refund\Claim;
use ReturnSentry\Connector\Model\Refund\Gate;
use ReturnSentry\Connector\Model\Refund\Override;
use ReturnSentry\Connector\Model\Returns\ReturnService;
use ReturnSentry\Connector\Model\Returns\ReturnStore;
use ReturnSentry\Connector\Model\Returns\RmaReader;
use ReturnSentry\Connector\Model\Source\RefundCategory;
use ReturnSentry\Connector\Model\Source\ReturnReason;
use ReturnSentry\Connector\Model\Sync\Publisher;
use ReturnSentry\Connector\Model\Sync\Sender;
use ReturnSentry\Connector\Test\Unit\Fixtures;
use ReturnSentry\Connector\Test\Unit\Support\MagentoConfig;

class ApiRoundTripTest extends TestCase
{
    use Fixtures;
    use MagentoConfig;

    private const ADDRESS = [
        'country_id' => 'FR',
        'street' => '1 rue Fictive',
        'postcode' => '75001',
        'city' => 'Paris',
    ];
    private const RETURN_ACTIONS = ['allow', 'paid_return', 'inspect_before_refund', 'statutory_only'];

    private string $run;
    private string $url;
    private string $key;
    private Config $config;
    private Client $client;

    protected function setUp(): void
    {
        $url = (string) getenv('RS_E2E_URL');
        $key = (string) getenv('RS_E2E_SECRET_KEY');
        if ($url === '' || $key === '') {
            $this->markTestSkipped('Set RS_E2E_URL and RS_E2E_SECRET_KEY to run the end-to-end test.');
        }
        if (!class_exists(CurlFactory::class, false)) {
            require_once __DIR__ . '/CurlFactory.php';
        }
        $this->run = bin2hex(random_bytes(4));
        $this->url = $url;
        $this->key = $key;
        $this->config = $this->storeConfig(Config::ENFORCE, $url, $key);
        $this->client = new Client($this->config, new CurlTransport(new CurlFactory()), new Json());
    }

    /**
     * @param array<string, string> $mapping
     */
    private function storeConfig(
        string $refundMode,
        string $url,
        string $key,
        string $timeoutMs = '5000',
        array $mapping = []
    ): Config {
        return new Config($this->magentoScopeConfig($mapping + [
            'returnsentry/general/enabled' => '1',
            'returnsentry/general/api_url' => rtrim($url, '/'),
            'returnsentry/general/secret_key' => $key,
            'returnsentry/general/timeout_ms' => $timeoutMs,
            'returnsentry/general/checkout_timeout_ms' => '2000',
            'returnsentry/features/refund_authorization' => $refundMode,
            'returnsentry/mapping/return_reasons' => '{"r1":{"shop":"Trop petit","standard":"size_fit"}}',
        ]), new Json());
    }

    public function testTheModuleTalksToReturnSentryFromOrderToRefund(): void
    {
        $order = $this->order(
            [
                'increment_id' => 'E2E-' . $this->run,
                'customer_id' => 900000 + random_int(1, 99999),
                'customer_email' => 'e2e-' . $this->run . '@example.com',
                'created_at' => gmdate('Y-m-d H:i:s', time() - 20 * 86400),
                'base_grand_total' => 189.0,
                'grand_total' => 189.0,
                'returnsentry_visitor_id' => 'e2eVisitor' . $this->run,
            ],
            [$this->orderItem([
                'item_id' => 1,
                'sku' => 'E2E-DRESS-M',
                'product_id' => 1,
                'name' => 'Robe fictive',
                'qty_ordered' => 1,
                'base_row_total_incl_tax' => 189.0,
                'weight' => 0.35,
            ])],
            $this->orderAddress(self::ADDRESS),
            true
        );
        $orderMapper = $this->orderMapper();

        $orderRecord = $orderMapper->toRecord($order);
        $this->assertNotNull($orderRecord);
        $this->assertSame(350, $orderRecord['items'][0]['weightGrams']);
        $created = $this->client->post('/v1/orders', $orderRecord, 1);
        $this->assertTrue($created['created']);
        $this->assertNotNull($created['customerId']);

        $parcels = (new ShipmentMapper($orderMapper, new Carrier(), $this->weight()))
            ->toRecords($this->shipment('6A' . $this->run), $order);
        $this->assertSame('colissimo', $parcels[0]['carrier']);
        $this->assertSame(420, $parcels[0]['declaredWeightGrams']);
        $this->assertTrue($this->client->post('/v1/shipments', $parcels[0], 1)['created']);

        $returnParcel = '8R' . $this->run;
        $returnMapper = new ReturnMapper($this->config, $orderMapper, new Carrier(), new ReturnReason());
        $return = $returnMapper->fromRequest(new ReturnRequest(
            'E2E-RET-' . $this->run,
            7,
            new \DateTimeImmutable('-5 days'),
            [new ReturnItem(1, 1, 'Trop petit')],
            'received',
            null,
            new \DateTimeImmutable('-1 day'),
            [new ReturnShipment($returnParcel, 'La Poste')]
        ), $order);
        $this->assertSame('size_fit', $return['reason']);
        $this->assertTrue($this->client->post('/v1/returns', $return, 1)['created']);
        $this->assertFalse($this->client->post('/v1/shipments', [
            'trackingNumber' => $returnParcel,
            'carrier' => 'colissimo',
            'direction' => 'return',
            'returnExternalId' => $return['externalId'],
        ], 1)['created']);

        $checkout = $this->assessor()->assess($this->quote((string) $orderRecord['customer']['email']));
        $this->assertTrue($checkout->assessed);
        $this->assertFalse($checkout->failOpen);
        $this->assertContains($checkout->action, ['allow', 'review', 'restrict_payment', 'block']);
        $this->assertFalse($checkout->returnTerms?->isPaid() ?? true);

        $returnDecision = $this->returnService($return)->assessOnce($return['externalId'], 1);
        $this->assertFalse($returnDecision->failOpen);
        $this->assertContains($returnDecision->action, self::RETURN_ACTIONS);

        $gate = $this->gate($return);
        $gate->authorize($this->creditmemo(189.0), $order);

        $this->client->post('/v1/inspections', [
            'externalId' => 'E2E-CTL-' . $this->run,
            'returnExternalId' => $return['externalId'],
            'source' => 'ecommerce',
            'inspectedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'items' => [['sku' => 'E2E-DRESS-M', 'quantity' => 1, 'outcome' => 'empty_parcel']],
        ], 1);
        try {
            $gate->authorize($this->creditmemo(189.0), $order);
            $this->fail('An empty parcel should hold the refund');
        } catch (LocalizedException $exception) {
            $this->assertStringContainsString('ReturnSentry holds this refund.', $exception->getMessage());
            $this->assertStringContainsString('/evidence/', $exception->getMessage());
        }
        $externalId = 'ra:' . $order->getIncrementId() . ':adjustment';
        $overrides = $this->createMock(AuthorizationStore::class);
        $overrides->method('override')->willReturnOnConsecutiveCalls(
            ['decidedBy' => 'e2e-admin', 'reason' => 'Parcel found by the carrier'],
            ['decidedBy' => 'e2e-admin', 'reason' => 'Parcel found by the carrier'],
            ['decidedBy' => 'e2e-admin', 'reason' => 'Another reason']
        );
        $this->sender($overrides)->send(Sender::OVERRIDE, $externalId, 1);
        $this->sender($overrides)->send(Sender::OVERRIDE, $externalId, 1);
        try {
            $this->sender($overrides)->send(Sender::OVERRIDE, $externalId, 1);
            $this->fail('A second, different decision on an approved refund should conflict');
        } catch (ApiException $exception) {
            $this->assertSame(409, $exception->status);
            $this->assertTrue($exception->transient);
        }
        $afterOverride = $this->client->post('/v1/refund-authorizations', [
            'externalId' => $externalId,
            'returnExternalId' => $return['externalId'],
            'amount' => 189.0,
        ], 1);
        $this->assertSame('approve', $afterOverride['decision']);
        $this->assertSame('operator_override', $afterOverride['reasons'][0]['code']);
        $this->gate($return, null, null, $this->storeConfig(Config::OBSERVE, $this->url, $this->key))
            ->authorize($this->creditmemo(189.0), $order);

        $refundMapper = new RefundMapper($this->config, $orderMapper, new RefundCategory());
        $refund = $refundMapper->toRecord($this->creditmemo(189.0), $order, $return);
        $this->assertSame('return', $refund['category']);
        $this->assertTrue($this->client->post('/v1/refunds', $refund, 1)['created']);

        $customerExternalId = $orderRecord['customer']['externalId'];
        $lookup = $this->client->get('/v1/customers/lookup', ['externalId' => $customerExternalId], 1);
        $this->assertCount(1, $lookup['customers']);
        $this->assertSame(1, $lookup['customers'][0]['ordersCount']);

        $this->sender()->send(Sender::ERASURE, substr($customerExternalId, 3), 1);
        $afterErasure = $this->client->get('/v1/customers/lookup', ['externalId' => $customerExternalId], 1);
        $this->assertSame([], $afterErasure['customers']);
    }

    public function testParcelClaimsAreDecidedOnCarrierFacts(): void
    {
        $config = $this->storeConfig(Config::ENFORCE, $this->url, $this->key, '5000', [
            'returnsentry/mapping/refund_code_field' => 'reason_code',
            'returnsentry/mapping/refund_codes' => '{"c1":{"shop":"LOST","standard":"item_not_received"},'
                . '"c2":{"shop":"RETURN-LOST","standard":"return_not_received_dispute"}}',
        ]);
        $orderMapper = $this->orderMapper();
        $saved = [];
        $authorizations = $this->createMock(AuthorizationStore::class);
        $authorizations->method('save')->willReturnCallback(static function (...$arguments) use (&$saved): void {
            $saved[] = ['category' => $arguments[4], 'claimedAt' => $arguments[5]];
        });
        $messages = [];
        foreach (['LOST', 'RETURN-LOST'] as $code) {
            $order = $this->order(
                [
                    'increment_id' => 'E2E-' . $code . '-' . $this->run,
                    'customer_id' => 900000 + random_int(1, 99999),
                    'customer_email' => 'e2e-claim-' . $this->run . '@example.com',
                    'created_at' => gmdate('Y-m-d H:i:s', time() - 10 * 86400),
                ],
                [$this->orderItem(['item_id' => 1, 'sku' => 'E2E-DRESS-M', 'qty_ordered' => 1, 'weight' => 0.35])],
                $this->orderAddress(self::ADDRESS),
                true
            );
            $this->client->post('/v1/orders', (array) $orderMapper->toRecord($order), 1);
            $parcel = (new ShipmentMapper($orderMapper, new Carrier(), $this->weight()))
                ->toRecords($this->shipment('6A' . $code . $this->run), $order)[0];
            $this->client->post('/v1/shipments', $parcel, 1);
            $return = null;
            if ($code === 'RETURN-LOST') {
                $returnMapper = new ReturnMapper($config, $orderMapper, new Carrier(), new ReturnReason());
                $return = $returnMapper->fromRequest(new ReturnRequest(
                    'E2E-RET-' . $code . '-' . $this->run,
                    7,
                    new \DateTimeImmutable('-6 days'),
                    [new ReturnItem(1, 1)],
                    'in_transit',
                    null,
                    null,
                    [new ReturnShipment('8R' . $code . $this->run, 'colissimo')]
                ), $order);
                $this->client->post('/v1/returns', $return, 1);
            }
            try {
                $this->gate($return, null, $authorizations, $config)
                    ->authorize($this->creditmemo(189.0, $code), $order);
            } catch (LocalizedException $exception) {
                $messages[] = $exception->getMessage();
            }
        }

        $this->assertSame(['item_not_received', 'return_not_received_dispute'], array_column($saved, 'category'));
        $this->assertCount(2, array_filter(array_column($saved, 'claimedAt')));
        foreach ($messages as $message) {
            $this->assertStringContainsString('ReturnSentry holds this refund. Carrier: ', $message);
        }
    }

    public function testBatchReportsRefusedRecordsAndErrorsAreClassified(): void
    {
        $batch = $this->client->post('/v1/batch', ['records' => [
            ['type' => 'customer', 'data' => ['externalId' => 'm2:e2e-' . $this->run]],
            ['type' => 'order', 'data' => ['externalId' => 'E2E-BAD-' . $this->run]],
        ]], 1, 30000);
        $this->assertSame(1, $batch['accepted']);
        $this->assertCount(1, $batch['errors']);
        $this->assertSame(1, $batch['errors'][0]['index']);
        $this->assertNotSame('', $batch['errors'][0]['message']);

        try {
            $this->client->post('/v1/orders', ['externalId' => 'E2E-BAD-' . $this->run], 1);
            $this->fail('An invalid order should be refused');
        } catch (ApiException $exception) {
            $this->assertFalse($exception->transient);
            $this->assertSame(400, $exception->status);
        }

        $unreachable = $this->storeConfig(Config::ENFORCE, 'http://127.0.0.1:9', 'rs_sk_none', '500');
        try {
            (new Client($unreachable, new CurlTransport(new CurlFactory()), new Json()))->post('/v1/orders', [], 1);
            $this->fail('An unreachable API should fail');
        } catch (ApiException $exception) {
            $this->assertTrue($exception->transient);
        }

        $authorizations = $this->createMock(AuthorizationStore::class);
        $authorizations->expects($this->once())
            ->method('record')
            ->with($this->anything(), 7, AuthorizationStore::RELEASED, 25.0, $this->anything());
        $order = $this->order(['increment_id' => 'E2E-OUT-' . $this->run]);
        $unreachableClient = new Client($unreachable, new CurlTransport(new CurlFactory()), new Json());
        $this->gate([], $unreachableClient, $authorizations, $unreachable)->authorize($this->creditmemo(25.0), $order);
    }

    private function sender(?AuthorizationStore $authorizations = null): Sender
    {
        return new Sender(
            $this->client,
            $this->createMock(CustomerRepositoryInterface::class),
            $this->createMock(OrderLoader::class),
            $this->createMock(CreditmemoRepositoryInterface::class),
            new Identity($this->config),
            $this->orderMapper(),
            $this->createMock(RefundMapper::class),
            $this->createMock(ReturnService::class),
            $this->createMock(ReturnStore::class),
            $this->createMock(ShipmentRepositoryInterface::class),
            $this->createMock(ShipmentMapper::class),
            $authorizations ?? $this->createMock(AuthorizationStore::class)
        );
    }

    private function orderMapper(): OrderMapper
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getCode')->willReturn('e2e_store');
        $stores = $this->createMock(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $skus = $this->createMock(ProductSkus::class);
        $skus->method('forIds')->willReturn([1 => 'E2E-DRESS']);
        return new OrderMapper(new Identity($this->config), $skus, $stores, $this->weight());
    }

    private function shipment(string $trackingNumber): Shipment
    {
        $track = $this->getMockBuilder(Track::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $track->setData(['track_number' => $trackingNumber, 'carrier_code' => 'custom', 'title' => 'Colissimo']);
        $item = $this->getMockBuilder(ShipmentItem::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $item->setData(['sku' => 'E2E-DRESS-M', 'qty' => 1, 'order_item_id' => 1]);
        $shipment = $this->getMockBuilder(Shipment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAllTracks', 'getAllItems'])
            ->getMock();
        $shipment->setData(['total_weight' => 0.42, 'created_at' => gmdate('Y-m-d H:i:s', time() - 18 * 86400)]);
        $shipment->method('getAllTracks')->willReturn([$track]);
        $shipment->method('getAllItems')->willReturn([$item]);
        return $shipment;
    }

    private function quote(string $email): Quote
    {
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getEmail', 'getTelephone'])
            ->getMock();
        $address->setData(self::ADDRESS);
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getShippingAddress', 'getBillingAddress', 'getAllVisibleItems', 'isVirtual', 'getStoreId'])
            ->getMock();
        $quote->setData([
            'reserved_order_id' => 'E2E-' . $this->run,
            'customer_email' => $email,
            'base_grand_total' => 59.0,
            'quote_currency_code' => 'EUR',
        ]);
        $quote->method('getShippingAddress')->willReturn($address);
        $quote->method('getBillingAddress')->willReturn($address);
        $quote->method('getAllVisibleItems')->willReturn([]);
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getStoreId')->willReturn(1);
        return $quote;
    }

    private function assessor(): Assessor
    {
        return new Assessor(
            $this->config,
            $this->client,
            new Identity($this->config),
            $this->createMock(VisitorId::class),
            $this->createMock(OrderNumber::class),
            $this->createMock(UserContextInterface::class),
            $this->createMock(CacheInterface::class),
            new Json(),
            $this->createMock(RemoteAddress::class),
            $this->createMock(Header::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * @param array<string, mixed> $return
     */
    private function returnService(array $return): ReturnService
    {
        $store = $this->createMock(ReturnStore::class);
        $store->method('find')->willReturn(['record' => $return, 'assessment' => null]);
        return new ReturnService(
            $this->config,
            $this->client,
            $store,
            $this->createMock(ReturnMapper::class),
            $this->orderMapper(),
            $this->createMock(RmaReader::class),
            $this->createMock(OrderLoader::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * @param array<string, mixed> $return
     */
    private function gate(
        ?array $return,
        ?Client $client = null,
        ?AuthorizationStore $authorizations = null,
        ?Config $config = null
    ): Gate {
        $returns = $this->createMock(ReturnStore::class);
        $returns->method('refundedBy')->willReturn($return);
        $refundMapper = new RefundMapper($config ?? $this->config, $this->orderMapper(), new RefundCategory());
        $authorizations ??= $this->createMock(AuthorizationStore::class);
        return new Gate(
            $config ?? $this->config,
            $client ?? $this->client,
            $refundMapper,
            $this->orderMapper(),
            $returns,
            $authorizations,
            $this->createMock(Override::class),
            new Claim($refundMapper, $authorizations),
            new CarrierSummary(),
            $this->createMock(Publisher::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    private function creditmemo(float $amount, ?string $reasonCode = null): Creditmemo
    {
        $creditmemo = $this->getMockBuilder(Creditmemo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAllItems'])
            ->getMock();
        $creditmemo->method('getAllItems')->willReturn([]);
        $creditmemo->setData([
            'increment_id' => 'E2E-CM-' . $this->run,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'base_grand_total' => $amount,
            'reason_code' => $reasonCode,
        ]);
        return $creditmemo;
    }
}
