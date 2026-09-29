<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model\Checkout;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\HTTP\Header;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReturnSentry\Connector\Model\Checkout\Assessor;
use ReturnSentry\Connector\Model\Checkout\OrderNumber;
use ReturnSentry\Connector\Model\Checkout\VisitorId;
use ReturnSentry\Connector\Model\Config;
use ReturnSentry\Connector\Model\Http\ApiException;
use ReturnSentry\Connector\Model\Http\Client;
use ReturnSentry\Connector\Model\Mapper\Identity;

class AssessorTest extends TestCase
{
    private Client&MockObject $client;
    private CacheInterface&MockObject $cache;
    private VisitorId&MockObject $visitorId;
    private OrderNumber&MockObject $orderNumber;
    private ?int $userType = UserContextInterface::USER_TYPE_GUEST;

    protected function setUp(): void
    {
        $this->client = $this->createMock(Client::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->visitorId = $this->createMock(VisitorId::class);
        $this->orderNumber = $this->createMock(OrderNumber::class);
    }

    public function testSendsTheCartWithinTheCheckoutBudgetAndCachesTheDecision(): void
    {
        $this->client->expects($this->once())
            ->method('post')
            ->with(
                '/v1/assessments',
                $this->callback(static fn (array $body) => $body['context'] === 'checkout'
                    && $body['mode'] === 'observe'
                    && $body['returnTermsDisplayed'] === false
                    && $body['customer'] === ['externalId' => 'm2:12', 'email' => 'jane@example.com']
                    && $body['device']['visitorId'] === 'Qm9vbGVhbi1zdHJpbmc'
                    && $body['order']['externalId'] === '000000042'
                    && $body['order']['amount'] === 99.9
                    && $body['order']['shippingAddress']['country'] === 'FR'),
                1,
                800
            )
            ->willReturn([
                'action' => 'review',
                'level' => 'high',
                'score' => 72,
                'reasons' => [['label' => 'Many returns']],
            ]);
        $this->visitorId->method('current')->willReturn('Qm9vbGVhbi1zdHJpbmc');
        $this->cache->expects($this->once())
            ->method('save')
            ->with($this->anything(), $this->anything(), [], 600);

        $assessment = $this->assessor()->assess($this->quote());

        $this->assertTrue($assessment->assessed);
        $this->assertSame('review', $assessment->action);
        $this->assertSame(['Many returns'], $assessment->reasons);
    }

    public function testDeclaresTheReturnTermsDisplayedOnlyWhenPlacementSaysSo(): void
    {
        $this->client->expects($this->once())
            ->method('post')
            ->with(
                '/v1/assessments',
                $this->callback(static fn (array $body) => $body['returnTermsDisplayed'] === true)
            )
            ->willReturn(['action' => 'allow']);

        $this->assessor()->assess($this->quote(), true);
    }

    public function testACartWithoutAShippingAddressIsNotAssessedYet(): void
    {
        $this->client->expects($this->never())->method('post');
        $this->orderNumber->expects($this->never())->method('reserve');

        $this->assertFalse($this->assessor()->assess($this->quote([], ['country_id' => null]))->assessed);
    }

    public function testKeepsTheReturnTermsThroughTheCache(): void
    {
        $saved = null;
        $this->client->method('post')->willReturn([
            'action' => 'allow',
            'returnTerms' => ['paid' => true, 'labelCost' => 6.5],
        ]);
        $this->cache->method('save')->willReturnCallback(static function (string $data) use (&$saved) {
            $saved = $data;
            return true;
        });

        $fresh = $this->assessor()->assess($this->quote());
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn($saved);
        $this->cache = $cache;
        $cached = $this->assessor()->assess($this->quote());

        $this->assertTrue($fresh->returnTerms?->isPaid());
        $this->assertSame(6.5, $cached->returnTerms?->getLabelCost());
    }

    public function testTheFirstCallReservesTheOrderNumberSoPlacementReusesItsAnswer(): void
    {
        $cached = [];
        $this->cache->method('save')->willReturnCallback(static function (string $data, string $key) use (&$cached) {
            $cached[$key] = $data;
            return true;
        });
        $this->cache->method('load')->willReturnCallback(static function (string $key) use (&$cached) {
            return $cached[$key] ?? false;
        });
        $this->orderNumber->method('reserve')->willReturnCallback(static function (Quote $quote) {
            $quote->setData('reserved_order_id', $quote->getData('reserved_order_id') ?: '000000077');
        });
        $this->client->expects($this->once())
            ->method('post')
            ->with(
                '/v1/assessments',
                $this->callback(static fn (array $body) => $body['order']['externalId'] === '000000077')
            )
            ->willReturn(['action' => 'allow']);
        $quote = $this->quote(['reserved_order_id' => null]);

        $listing = $this->assessor()->assess($quote);
        $quote->setData('reserved_order_id', $quote->getData('reserved_order_id') ?: '000000078');
        $placement = $this->assessor()->assess($quote);

        $this->assertTrue($listing->assessed);
        $this->assertTrue($placement->assessed);
    }

    public function testNoOrderNumberIsReservedForACartNobodyCanBeRecognizedIn(): void
    {
        $this->orderNumber->expects($this->never())->method('reserve');

        $this->assessor()->assess($this->quote(['customer_id' => null, 'customer_email' => null]));
    }

    public function testOrdersPlacedWithAnAdminOrIntegrationTokenAreNotAssessed(): void
    {
        $this->client->expects($this->never())->method('post');
        $this->orderNumber->expects($this->never())->method('reserve');

        foreach ([UserContextInterface::USER_TYPE_ADMIN, UserContextInterface::USER_TYPE_INTEGRATION] as $userType) {
            $this->userType = $userType;
            $this->assertFalse($this->assessor()->assess($this->quote())->assessed);
        }
    }

    public function testReusesTheCachedDecisionForTheSameCart(): void
    {
        $this->cache->method('load')
            ->willReturn('{"action":"restrict_payment","level":"critical","score":85,"reasons":[],"failOpen":false}');
        $this->client->expects($this->never())->method('post');

        $this->assertSame('restrict_payment', $this->assessor()->assess($this->quote())->action);
    }

    public function testFailsOpenAndRetriesSoonWhenReturnSentryDoesNotAnswer(): void
    {
        $this->client->method('post')->willThrowException(new ApiException('timeout', true));
        $this->cache->expects($this->once())
            ->method('save')
            ->with($this->anything(), $this->anything(), $this->anything(), 60);

        $assessment = $this->assessor()->assess($this->quote());

        $this->assertSame('allow', $assessment->action);
        $this->assertTrue($assessment->failOpen);
    }

    public function testDoesNotCallWithoutAnyWayToIdentifyTheCustomer(): void
    {
        $this->client->expects($this->never())->method('post');

        $assessment = $this->assessor()->assess($this->quote(['customer_id' => null, 'customer_email' => null]));

        $this->assertFalse($assessment->assessed);
        $this->assertSame('allow', $assessment->action);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $addressData
     */
    private function quote(array $data = [], array $addressData = []): Quote
    {
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getEmail', 'getTelephone'])
            ->getMock();
        $address->setData($addressData + [
            'country_id' => 'FR',
            'street' => '1 rue A',
            'postcode' => '75001',
            'city' => 'Paris',
        ]);
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getShippingAddress', 'getBillingAddress', 'getAllVisibleItems', 'isVirtual', 'getStoreId'])
            ->getMock();
        $quote->setData($data + [
            'entity_id' => 5,
            'reserved_order_id' => '000000042',
            'customer_id' => 12,
            'customer_email' => 'jane@example.com',
            'base_grand_total' => 99.9,
            'quote_currency_code' => 'EUR',
        ]);
        $quote->method('getShippingAddress')->willReturn($address);
        $quote->method('getBillingAddress')->willReturn($address);
        $quote->method('getAllVisibleItems')->willReturn([]);
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getStoreId')->willReturn(1);
        return $quote;
    }

    private function userContext(): UserContextInterface
    {
        $userContext = $this->createMock(UserContextInterface::class);
        $userContext->method('getUserType')->willReturn($this->userType);
        return $userContext;
    }

    private function assessor(): Assessor
    {
        $config = $this->createMock(Config::class);
        $config->method('checkoutTimeoutMs')->willReturn(800);
        $config->method('mode')->willReturn(Config::OBSERVE);
        return new Assessor(
            $config,
            $this->client,
            new Identity($config),
            $this->visitorId,
            $this->orderNumber,
            $this->userContext(),
            $this->cache,
            new Json(),
            $this->createMock(RemoteAddress::class),
            $this->createMock(Header::class),
            $this->createMock(LoggerInterface::class)
        );
    }
}
