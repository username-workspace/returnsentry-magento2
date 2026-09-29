<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\ViewModel;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReturnSentry\Connector\Api\Data\Assessment;
use ReturnSentry\Connector\Model\Config;
use ReturnSentry\Connector\Model\Http\Client;
use ReturnSentry\Connector\Model\Mapper\Identity;
use ReturnSentry\Connector\Model\Mapper\OrderMapper;
use ReturnSentry\Connector\Model\OrderLoader;
use ReturnSentry\Connector\Model\Refund\AuthorizationStore;
use ReturnSentry\Connector\Model\Refund\CarrierSummary;
use ReturnSentry\Connector\Model\Returns\ReturnStore;
use ReturnSentry\Connector\Model\Source\RefundCategory;
use ReturnSentry\Connector\Model\Source\RiskLevel;
use ReturnSentry\Connector\Test\Unit\Fixtures;
use ReturnSentry\Connector\ViewModel\AnnouncedReturnTerms;
use ReturnSentry\Connector\ViewModel\RiskPanel;

class RiskPanelTest extends TestCase
{
    use Fixtures;

    public function testALegalRightOnlyReturnIsAtTheCustomersCostOnlyWhenPaidTermsWereAnnounced(): void
    {
        $announced = $this->panel(['returnsentry_return_paid' => 1])->forOrder();
        $free = $this->panel([])->forOrder();

        $this->assertStringContainsString('return at own cost', (string) $announced['returns'][0]['meaning']);
        $this->assertStringContainsString('free return', (string) $free['returns'][0]['meaning']);
    }

    public function testARefusedKeyPromisesAResendWhileAFinalRefusalAsksForTheConsole(): void
    {
        $row = fn (int $rejected) => [
            'external_id' => 'ra:7:' . $rejected,
            'decision' => 'hold',
            'observed' => 0,
            'requested_amount' => 50,
            'approved_amount' => 0,
            'override_rejected' => $rejected,
        ];
        $refunds = $this->panel([], [$row(403), $row(409), $row(422)])->forOrder()['refunds'];
        $details = array_map(fn (array $refund) => implode(' ', array_map('strval', $refund['details'])), $refunds);

        $this->assertStringContainsString('refused the secret key (HTTP 403)', $details[0]);
        $this->assertStringContainsString('decision already final', $details[1]);
        $this->assertStringContainsString('refused the override (HTTP 422). Record it in the console', $details[2]);
    }

    /**
     * @param array<string, mixed> $orderData
     * @param list<array<string, mixed>> $refunds
     */
    private function panel(array $orderData, array $refunds = []): RiskPanel
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('order_id')->willReturn('7');
        $orders = $this->createMock(OrderLoader::class);
        $orders->method('get')->with(7)->willReturn($this->order($orderData));
        $config = $this->createMock(Config::class);
        $config->method('isConnected')->willReturn(true);
        $returns = $this->createMock(ReturnStore::class);
        $returns->method('forOrder')->willReturn([
            ['record' => ['externalId' => 'rma:000000003'], 'assessment' => new Assessment('statutory_only')],
        ]);
        $authorizations = $this->createMock(AuthorizationStore::class);
        $authorizations->method('forOrder')->willReturn($refunds);
        return new RiskPanel(
            $request,
            $orders,
            $this->createMock(CustomerRepositoryInterface::class),
            $config,
            $this->createMock(Client::class),
            $this->createMock(OrderMapper::class),
            $this->createMock(Identity::class),
            $returns,
            $authorizations,
            new CarrierSummary(),
            new RefundCategory(),
            new Json(),
            $this->createMock(LoggerInterface::class),
            $this->createMock(AnnouncedReturnTerms::class),
            new RiskLevel()
        );
    }
}
