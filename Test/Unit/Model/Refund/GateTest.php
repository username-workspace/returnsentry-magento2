<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model\Refund;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Exception\CouldNotRefundException;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\Item as ShipmentItem;
use Magento\Sales\Model\Order\Shipment\Track;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReturnSentry\Connector\Exception\RefundStoppedException;
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
use ReturnSentry\Connector\Model\Sync\Sender;
use ReturnSentry\Connector\Model\System\RefundControlAlert;
use ReturnSentry\Connector\Test\Unit\Fixtures;

class GateTest extends TestCase
{
    use Fixtures;

    private Client&MockObject $client;
    private AuthorizationStore&MockObject $authorizations;
    private RefundMapper&MockObject $refundMapper;
    private string $mode = Config::ENFORCE;
    private string $category = 'return';
    private Publisher&MockObject $publisher;
    private RefundControlAlert&MockObject $refundControl;

    /**
     * @var array{decidedBy: string, reason: string}|null
     */
    private ?array $override = null;

    /**
     * @var array<string, string>|null
     */
    private ?array $latest = null;

    /**
     * @var array<string, string>|null
     */
    private ?array $own = null;

    protected function setUp(): void
    {
        $this->client = $this->createMock(Client::class);
        $this->publisher = $this->createMock(Publisher::class);
        $this->refundControl = $this->createMock(RefundControlAlert::class);
        $this->authorizations = $this->createMock(AuthorizationStore::class);
        $this->authorizations->method('latestForOrder')->willReturnCallback(fn () => $this->latest);
        $this->authorizations->method('find')->willReturnCallback(fn () => $this->own);
        $this->refundMapper = $this->createMock(RefundMapper::class);
        $this->refundMapper->method('category')->willReturnCallback(fn () => $this->category);
        $this->refundMapper->method('refundedItems')->willReturn([['sku' => 'DRESS-M', 'quantity' => 1]]);
        $this->refundMapper->method('refundedLines')
            ->willReturnCallback(static fn (Creditmemo $creditmemo) => (string) $creditmemo->getData('lines'));
    }

    public function testApproveLetsTheCreditMemoThrough(): void
    {
        $this->client->expects($this->once())
            ->method('post')
            ->with('/v1/refund-authorizations', $this->callback(static fn (array $body) => $body === [
                'externalId' => 'ra:100000007:12x1',
                'returnExternalId' => 'rma:000000003',
                'orderExternalId' => '100000007',
                'customer' => ['email' => 'jane@example.com'],
                'amount' => 189.0,
                'withdrawnAt' => '2026-09-02T09:12:00Z',
                'goodsBackAt' => '2026-09-05T14:30:00Z',
                'mode' => 'enforce',
            ]))
            ->willReturn(['decision' => 'approve', 'requestedAmount' => 189, 'approvedAmount' => 189]);
        $this->authorizations->expects($this->once())->method('save');

        $this->gate()->authorize($this->creditmemo(189.0), $this->order([]));
    }

    public function testEachRefundIsNamedAfterItsLinesSoAStoppedOneNeverLendsItsDecision(): void
    {
        $posted = [];
        $this->client->method('post')->willReturnCallback(function (string $path, array $body) use (&$posted) {
            $posted[] = $body['externalId'];
            return ['decision' => 'hold', 'requestedAmount' => 95, 'approvedAmount' => 0];
        });
        $refundedDress = $this->creditmemo(95.0, ['lines' => '12x1']);

        $this->refusal(95.0, ['lines' => '12x1']);
        $this->refusal(95.0, ['lines' => '12x1']);
        $this->refusal(95.0, ['lines' => '13x1']);
        $this->refusal(95.0, ['lines' => '13x1'], [$refundedDress]);
        $this->refusal(95.0, ['lines' => '12x1'], [$refundedDress]);
        $this->refusal(95.0, ['lines' => ''], [$refundedDress]);

        $this->assertSame([
            'ra:100000007:12x1',
            'ra:100000007:12x1',
            'ra:100000007:13x1',
            'ra:100000007:13x1',
            'ra:100000007:12x1:2',
            'ra:100000007:adjustment',
        ], $posted);
    }

    public function testPartialApprovalStopsTheFullAmountWithTheAdjustmentToMake(): void
    {
        $this->client->method('post')->willReturn([
            'decision' => 'approve_partial',
            'requestedAmount' => 189,
            'approvedAmount' => 132.3,
            'reasons' => [['code' => 'inspection_abuse', 'label' => 'Worn item']],
            'evidenceUrl' => 'https://rs.example.com/evidence/abc',
        ]);

        $message = $this->refusal(189.0);

        $this->assertStringContainsString('ReturnSentry approves 132.30 EUR of the 189.00 EUR requested.', $message);
        $this->assertStringContainsString('add an adjustment fee of 56.70 EUR', $message);
        $this->assertStringContainsString('Reasons: Worn item.', $message);
        $this->assertStringContainsString('https://rs.example.com/evidence/abc', $message);
        $this->assertStringContainsString('https://rs.example.com/refunds', $message);
    }

    public function testPartialApprovalPassesOnceTheAdminAdjustedTheAmount(): void
    {
        $this->client->expects($this->once())
            ->method('post')
            ->with('/v1/refund-authorizations', $this->callback(static fn (array $body) => $body['amount'] === 189.0))
            ->willReturn(['decision' => 'approve_partial', 'requestedAmount' => 189, 'approvedAmount' => 132.3]);

        $this->gate()->authorize($this->creditmemo(132.3, ['base_adjustment_negative' => 56.7]), $this->order([]));
    }

    public function testRefundsStartedAtThePaymentProviderAreNotAsked(): void
    {
        $this->client->expects($this->never())->method('post');

        $this->gate()->authorize($this->creditmemo(50.0, ['automatically_created' => true]), $this->order([]));
        $this->gate()->authorize($this->creditmemo(50.0, ['payment_refund_disallowed' => true]), $this->order([]));
    }

    public function testHoldStopsTheCreditMemoWithItsDeadline(): void
    {
        $this->client->method('post')->willReturn([
            'id' => '0192f3a0-7c1e-7b2a-9d41-5e6f7a8b9c0d',
            'decision' => 'hold',
            'requestedAmount' => 189,
            'approvedAmount' => 0,
            'holdUntil' => '2026-09-14T00:00:00.000Z',
        ]);

        $message = $this->refusal(189.0);

        $this->assertStringContainsString('ReturnSentry holds this refund.', $message);
        $this->assertStringContainsString('a credit memo submitted from 2026-09-14 will be approved', $message);
        $this->assertStringContainsString(
            'https://rs.example.com/refunds?refund=0192f3a0-7c1e-7b2a-9d41-5e6f7a8b9c0d',
            $message
        );
    }

    public function testAParcelClaimIsDecidedOnTheClaimedParcelWithoutReturnDates(): void
    {
        $this->category = 'item_not_received';
        $this->authorizations->method('claimedAt')->willReturn('2026-09-20 08:00:00');
        $this->client->expects($this->once())
            ->method('post')
            ->with('/v1/refund-authorizations', $this->callback(static fn (array $body) => $body === [
                'externalId' => 'ra:100000007:12x1',
                'orderExternalId' => '100000007',
                'customer' => ['email' => 'jane@example.com'],
                'amount' => 189.0,
                'category' => 'item_not_received',
                'claimedAt' => '2026-09-20T08:00:00Z',
                'trackingNumber' => '6A2',
                'mode' => 'enforce',
            ]))
            ->willReturn([
                'decision' => 'hold',
                'requestedAmount' => 189,
                'approvedAmount' => 0,
                'reasons' => [['code' => 'carrier_in_transit', 'label' => 'Colis en transit']],
                'holdUntil' => '2026-09-27T08:00:00.000Z',
                'carrier' => [
                    'state' => 'in_transit',
                    'proofStrength' => 'none',
                    'deliveredAt' => null,
                    'handover' => null,
                ],
            ]);
        $this->authorizations->expects($this->once())
            ->method('save')
            ->with('ra:100000007:12x1', 7, $this->anything(), false, 'item_not_received', '2026-09-20T08:00:00Z');
        $order = $this->order([], [], null, true, [
            $this->shipment(['BAG'], ['6A1']),
            $this->shipment(['DRESS-M'], ['6A2']),
        ]);

        try {
            $this->gate()->authorize($this->creditmemo(189.0), $order);
            $this->fail('A parcel in transit should hold the claim');
        } catch (LocalizedException $exception) {
            $this->assertStringContainsString('Carrier: in transit.', $exception->getMessage());
            $this->assertStringContainsString('submitted from 2026-09-27 will be approved', $exception->getMessage());
        }
    }

    public function testMissingItemsAreListedAndAContestedReturnKeepsItsReturn(): void
    {
        $bodies = [];
        $this->client->method('post')->willReturnCallback(
            static function (string $path, array $body) use (&$bodies): array {
                $bodies[] = $body;
                return ['decision' => 'approve', 'requestedAmount' => 189, 'approvedAmount' => 189];
            }
        );
        $order = $this->order([], [], null, true, [$this->shipment(['DRESS-M'], ['6A1', '6A2'])]);

        $this->category = 'missing_items_claim';
        $this->gate()->authorize($this->creditmemo(189.0), $order);
        $this->category = 'return_not_received_dispute';
        $this->gate()->authorize($this->creditmemo(189.0), $order);

        $this->assertSame([['sku' => 'DRESS-M', 'quantity' => 1]], $bodies[0]['items']);
        $this->assertArrayNotHasKey('trackingNumber', $bodies[0]);
        $this->assertArrayNotHasKey('returnExternalId', $bodies[0]);
        $this->assertSame('return_not_received_dispute', $bodies[1]['category']);
        $this->assertSame('rma:000000003', $bodies[1]['returnExternalId']);
        $this->assertArrayHasKey('claimedAt', $bodies[1]);
        $this->assertArrayNotHasKey('items', $bodies[1]);
        $this->assertArrayNotHasKey('trackingNumber', $bodies[1]);
    }

    public function testDenyStopsTheCreditMemo(): void
    {
        $this->client->method('post')
            ->willReturn(['decision' => 'deny', 'requestedAmount' => 189, 'approvedAmount' => 0]);

        $this->assertStringContainsString('ReturnSentry denies this refund.', $this->refusal(189.0));
    }

    public function testAStoppedCreditMemoLeavesItsReasonToTakeOnce(): void
    {
        $this->client->method('post')
            ->willReturn(['decision' => 'deny', 'requestedAmount' => 189, 'approvedAmount' => 0]);
        $gate = $this->gate();

        try {
            $gate->authorize($this->creditmemo(189.0), $this->order([]));
        } catch (LocalizedException) {
        }

        $this->assertStringContainsString('ReturnSentry denies this refund.', $gate->takeStop()?->getMessage() ?? '');
        $this->assertNull($gate->takeStop());
    }

    public function testAnAuthorizationThatPassesForgetsAnEarlierStop(): void
    {
        $this->client->method('post')->willReturnOnConsecutiveCalls(
            ['decision' => 'deny', 'requestedAmount' => 189, 'approvedAmount' => 0],
            ['decision' => 'approve', 'requestedAmount' => 189, 'approvedAmount' => 189]
        );
        $gate = $this->gate();

        try {
            $gate->authorize($this->creditmemo(189.0), $this->order([]));
        } catch (LocalizedException) {
        }
        $gate->authorize($this->creditmemo(189.0), $this->order([]));

        $this->assertNull($gate->takeStop());
    }

    public function testAStopTellsAnAutomatedFlowTheDecisionItsReasonsDeadlineAndEvidence(): void
    {
        $this->client->method('post')->willReturn([
            'decision' => 'hold',
            'requestedAmount' => 189,
            'approvedAmount' => 0,
            'reasons' => [['code' => 'inspection_fraud', 'label' => 'Empty parcel']],
            'holdUntil' => '2026-09-14T00:00:00.000Z',
            'evidenceUrl' => 'https://rs.example.com/evidence/abc',
        ]);

        $stop = $this->stop(189.0);

        $this->assertInstanceOf(CouldNotRefundException::class, $stop);
        $this->assertStringStartsWith('ReturnSentry holds this refund.', $stop->getRawMessage());
        $this->assertSame([], $stop->getParameters());
        $this->assertSame('hold', $stop->getDecision());
        $this->assertSame(['Empty parcel'], $stop->getReasons());
        $this->assertSame('2026-09-14T00:00:00Z', $stop->getHoldUntil());
        $this->assertSame('https://rs.example.com/evidence/abc', $stop->getEvidenceUrl());
    }

    public function testAStopDuringAnOutageCarriesTheStoredDecision(): void
    {
        $this->own = ['decision' => 'hold', 'fraud_evidence' => '1', 'hold_until' => '2026-09-14 00:00:00'];
        $this->client->method('post')->willThrowException(new ApiException('timeout', true));

        $stop = $this->stop(189.0);

        $this->assertSame('hold', $stop->getDecision());
        $this->assertSame([], $stop->getReasons());
        $this->assertSame('2026-09-14T00:00:00Z', $stop->getHoldUntil());
        $this->assertNull($stop->getEvidenceUrl());
    }

    public function testWithoutAnAnswerTheRefundGoesThroughAndIsRecorded(): void
    {
        $this->client->method('post')->willReturnOnConsecutiveCalls(
            ['decision' => 'hold', 'requestedAmount' => 189, 'approvedAmount' => 0, 'degraded' => true],
            $this->throwException(new ApiException('timeout', true)),
            $this->throwException(new ApiException('HTTP 503', true, 503))
        );
        $this->authorizations->expects($this->never())->method('save');
        $this->authorizations->expects($this->exactly(3))
            ->method('record')
            ->with('ra:100000007:12x1', 7, AuthorizationStore::RELEASED, 189.0, $this->anything());
        $order = $this->order([]);
        $order->expects($this->exactly(3))
            ->method('addCommentToStatusHistory')
            ->with('ReturnSentry did not answer: this refund went through unchecked.');

        foreach ([1, 2, 3] as $attempt) {
            $this->gate()->authorize($this->creditmemo(189.0), $order);
        }
    }

    public function testARefusedKeyOrUrlLetsTheRefundThroughAndSaysWhy(): void
    {
        $this->client->method('post')->willThrowException(new ApiException('HTTP 401', false, 401));
        $this->refundControl->expects($this->once())->method('raise')->with(1, 401);
        $this->refundControl->expects($this->never())->method('clear');
        $this->authorizations->expects($this->once())
            ->method('record')
            ->with('ra:100000007:12x1', 7, AuthorizationStore::MISCONFIGURED, 189.0, 'HTTP 401');
        $order = $this->order([]);
        $order->expects($this->once())
            ->method('addCommentToStatusHistory')
            ->with('ReturnSentry refused the secret key or the URL: this refund went through unchecked.');

        $this->gate()->authorize($this->creditmemo(189.0), $order);
    }

    public function testAnOutageIsNotTakenForARefusedKey(): void
    {
        $this->client->method('post')->willThrowException(new ApiException('HTTP 503', true, 503));
        $this->refundControl->expects($this->never())->method('raise');

        $this->gate()->authorize($this->creditmemo(189.0), $this->order([]));
    }

    public function testAnAnswerClearsTheRefusedKeyAlert(): void
    {
        $this->client->method('post')
            ->willReturn(['decision' => 'approve', 'requestedAmount' => 189, 'approvedAmount' => 189]);
        $this->refundControl->expects($this->once())->method('clear')->with(1);

        $this->gate()->authorize($this->creditmemo(189.0), $this->order([]));
    }

    public function testWithoutAnAnswerAHoldOnFraudEvidenceStaysClosed(): void
    {
        $this->own = ['decision' => 'hold', 'fraud_evidence' => '1'];
        $this->client->method('post')->willThrowException(new ApiException('timeout', true));
        $this->authorizations->expects($this->never())->method('record');

        $message = $this->refusal(189.0);

        $this->assertStringContainsString('its last decision holds this refund on fraud evidence', $message);
        $this->assertStringContainsString('allowed to override can refund anyway', $message);
    }

    public function testAnOutageLetsAReturnRefundThroughWhateverAnotherRefundOfTheOrderIsHeldFor(): void
    {
        $this->latest = ['decision' => 'hold', 'fraud_evidence' => '1'];
        $this->client->method('post')->willThrowException(new ApiException('timeout', true));
        $this->authorizations->expects($this->once())
            ->method('record')
            ->with('ra:100000007:13x1', 7, AuthorizationStore::RELEASED, 95.0, $this->anything());

        $this->gate()->authorize($this->creditmemo(95.0, ['lines' => '13x1']), $this->order([]));
    }

    public function testAnOutageKeepsAClaimStoppedWhileItsOrderIsHeldOnFraudEvidence(): void
    {
        $this->category = 'item_not_received';
        $this->latest = ['decision' => 'hold', 'fraud_evidence' => '1'];
        $this->client->method('post')->willThrowException(new ApiException('timeout', true));
        $this->authorizations->expects($this->never())->method('record');

        $message = $this->refusal(189.0);

        $this->assertStringContainsString('its last decision holds this order on fraud evidence', $message);
    }

    public function testAnOutageKeepsADenialStopped(): void
    {
        $this->own = ['decision' => 'deny', 'fraud_evidence' => '0'];
        $this->client->method('post')->willThrowException(new ApiException('timeout', true));
        $this->authorizations->expects($this->never())->method('record');

        $this->assertStringContainsString('its last decision denies this refund', $this->refusal(189.0));
    }

    public function testAnOutageKeepsAClaimStoppedWhileItsOrderWasDenied(): void
    {
        $this->category = 'item_not_received';
        $this->latest = ['decision' => 'deny', 'fraud_evidence' => '0'];
        $this->client->method('post')->willThrowException(new ApiException('timeout', true));

        $this->assertStringContainsString('its last decision on this order is a denial', $this->refusal(189.0));
    }

    public function testAnOutageHoldsAPartialApprovalToItsAmount(): void
    {
        $this->own = ['decision' => 'approve_partial', 'approved_amount' => '120.0000', 'fraud_evidence' => '0'];
        $this->client->method('post')->willThrowException(new ApiException('timeout', true));
        $this->authorizations->expects($this->once())
            ->method('record')
            ->with('ra:100000007:12x1', 7, AuthorizationStore::RELEASED, 120.0, $this->anything());

        $message = $this->refusal(189.0);
        $this->gate()->authorize($this->creditmemo(120.0), $this->order([]));

        $this->assertStringContainsString('its last decision approves 120.00 EUR of this refund', $message);
        $this->assertStringContainsString('add an adjustment fee of 69.00 EUR', $message);
    }

    public function testAHoldWithoutFraudEvidenceDoesNotKeepAnOutageClosed(): void
    {
        $this->own = ['decision' => 'hold', 'fraud_evidence' => '0'];
        $this->client->method('post')->willThrowException(new ApiException('timeout', true));
        $this->authorizations->expects($this->once())->method('record');

        $this->gate()->authorize($this->creditmemo(189.0), $this->order([]));
    }

    public function testAnAdministratorOverrideLetsAStoppedRefundThrough(): void
    {
        $this->override = ['decidedBy' => 'jane', 'reason' => 'parcel found by the carrier'];
        $this->client->method('post')
            ->willReturn(['decision' => 'deny', 'requestedAmount' => 189, 'approvedAmount' => 0]);
        $this->authorizations->expects($this->once())
            ->method('record')
            ->with(
                'ra:100000007:12x1',
                7,
                AuthorizationStore::OVERRIDDEN,
                189.0,
                'parcel found by the carrier',
                'jane'
            );
        $this->publisher->expects($this->once())->method('publish')->with(Sender::OVERRIDE, 'ra:100000007:12x1', 1);
        $order = $this->order([]);
        $order->expects($this->once())
            ->method('addCommentToStatusHistory')
            ->with('Refunded despite ReturnSentry, by jane: parcel found by the carrier');

        $this->gate()->authorize($this->creditmemo(189.0), $order);
    }

    public function testNeutralRefundsAndDisabledAuthorizationAreNotAsked(): void
    {
        $this->client->expects($this->never())->method('post');
        $neutral = $this->createMock(RefundMapper::class);
        $neutral->method('category')->willReturn('commercial_gesture');
        $this->refundMapper = $neutral;

        $this->gate()->authorize($this->creditmemo(20.0), $this->order([]));
        $this->mode = Config::OFF;
        $this->refundMapper = $this->createMock(RefundMapper::class);
        $this->gate()->authorize($this->creditmemo(20.0), $this->order([]));
    }

    public function testARefundOfAnOrderPaidWithAnExcludedMethodIsNotAskedNorRecorded(): void
    {
        $this->client->expects($this->never())->method('post');
        $this->authorizations->expects($this->never())->method('record');
        $this->authorizations->expects($this->never())->method('save');

        $this->gate()->authorize($this->creditmemo(189.0), $this->order(['payment_method' => 'globale']));
    }

    public function testObserveRecordsTheDecisionAndNeverStopsTheCreditMemo(): void
    {
        $this->mode = Config::OBSERVE;
        $this->authorizations->expects($this->never())->method('record');
        $this->client->method('post')->willReturnOnConsecutiveCalls(
            ['decision' => 'hold', 'requestedAmount' => 189, 'approvedAmount' => 0],
            $this->throwException(new ApiException('timeout', true))
        );
        $this->authorizations->expects($this->once())
            ->method('save')
            ->with(
                'ra:100000007:12x1',
                7,
                $this->callback(static fn ($decision) => $decision->decision === 'hold'),
                true
            );

        $this->gate()->authorize($this->creditmemo(189.0), $this->order([]));
        $this->gate()->authorize($this->creditmemo(189.0), $this->order([]));
    }

    /**
     * @param array<string, mixed> $data
     * @param Creditmemo[] $created
     */
    private function refusal(float $amount, array $data = [], array $created = []): string
    {
        try {
            $order = $this->order([], [], null, false, [], $created);
            $this->gate()->authorize($this->creditmemo($amount, $data), $order);
        } catch (LocalizedException $exception) {
            return $exception->getMessage();
        }
        $this->fail('The credit memo should have been stopped');
    }

    private function stop(float $amount): RefundStoppedException
    {
        try {
            $this->gate()->authorize($this->creditmemo($amount), $this->order([]));
        } catch (RefundStoppedException $exception) {
            return $exception;
        }
        $this->fail('The credit memo should have been stopped');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function creditmemo(float $amount, array $data = []): Creditmemo
    {
        $creditmemo = $this->getMockBuilder(Creditmemo::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $creditmemo->setData(['base_grand_total' => $amount] + $data + ['lines' => '12x1']);
        return $creditmemo;
    }

    /**
     * @param string[] $skus
     * @param string[] $trackingNumbers
     */
    private function shipment(array $skus, array $trackingNumbers): Shipment
    {
        $shipment = $this->getMockBuilder(Shipment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAllItems', 'getAllTracks'])
            ->getMock();
        $items = [];
        foreach ($skus as $sku) {
            $item = $this->getMockBuilder(ShipmentItem::class)
                ->disableOriginalConstructor()
                ->onlyMethods([])
                ->getMock();
            $item->setData('sku', $sku);
            $items[] = $item;
        }
        $tracks = [];
        foreach ($trackingNumbers as $number) {
            $track = $this->getMockBuilder(Track::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
            $track->setData('track_number', $number);
            $tracks[] = $track;
        }
        $shipment->method('getAllItems')->willReturn($items);
        $shipment->method('getAllTracks')->willReturn($tracks);
        return $shipment;
    }

    private function gate(): Gate
    {
        $config = $this->createMock(Config::class);
        $config->method('mode')->willReturn($this->mode);
        $config->method('apiUrl')->willReturn('https://rs.example.com');
        $config->method('isExcludedPaymentMethod')
            ->willReturnCallback(static fn (string $method, int $storeId) => $method === 'globale' && $storeId === 1);
        $orderMapper = $this->createMock(OrderMapper::class);
        $orderMapper->method('customerRef')->willReturn(['email' => 'jane@example.com']);
        $override = $this->createMock(Override::class);
        $override->method('requested')->willReturnCallback(fn () => $this->override);
        $returns = $this->createMock(ReturnStore::class);
        $returns->method('refundedBy')->willReturn([
            'externalId' => 'rma:000000003',
            'requestedAt' => '2026-09-02T09:12:00Z',
            'receivedAt' => '2026-09-05T14:30:00Z',
        ]);
        return new Gate(
            $config,
            $this->client,
            $this->refundMapper,
            $orderMapper,
            $returns,
            $this->authorizations,
            $override,
            new Claim($this->refundMapper, $this->authorizations),
            new CarrierSummary(),
            $this->publisher,
            $this->refundControl,
            $this->createMock(LoggerInterface::class)
        );
    }
}
