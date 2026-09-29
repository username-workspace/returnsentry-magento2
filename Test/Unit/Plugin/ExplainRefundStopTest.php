<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Plugin;

use Magento\Framework\Phrase;
use Magento\Sales\Api\RefundOrderInterface;
use Magento\Sales\Exception\CouldNotRefundException;
use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Exception\RefundStoppedException;
use ReturnSentry\Connector\Model\Refund\Gate;
use ReturnSentry\Connector\Plugin\ExplainRefundStop;

class ExplainRefundStopTest extends TestCase
{
    public function testARefundServiceThrowsTheStopItselfForItsCallerToRecognise(): void
    {
        $stop = new RefundStoppedException(new Phrase('ReturnSentry holds this refund.'), 'hold');
        $gate = $this->createMock(Gate::class);
        $gate->method('takeStop')->willReturnOnConsecutiveCalls(null, $stop);

        try {
            $this->execute($gate);
        } catch (RefundStoppedException $exception) {
            $this->assertSame($stop, $exception);
            $this->assertSame('ReturnSentry holds this refund.', $exception->getMessage());
            return;
        }
        $this->fail('The refund should have been stopped');
    }

    public function testOtherRefundFailuresKeepMagentoMessage(): void
    {
        $gate = $this->createMock(Gate::class);
        $gate->method('takeStop')->willReturn(null);

        $this->expectException(CouldNotRefundException::class);
        $this->expectExceptionMessage('Could not save a Creditmemo, see error log for details');

        $this->execute($gate);
    }

    public function testArgumentsAndResultPassThrough(): void
    {
        $proceed = static fn (int $orderId, array $items, bool $notify): int
            => $orderId + count($items) + (int) $notify;

        $this->assertSame(14, (new ExplainRefundStop($this->createMock(Gate::class)))
            ->aroundExecute($this->createMock(RefundOrderInterface::class), $proceed, 12, [['qty' => 1]], true));
    }

    private function execute(Gate $gate): void
    {
        (new ExplainRefundStop($gate))->aroundExecute(
            $this->createMock(RefundOrderInterface::class),
            static fn () => throw new CouldNotRefundException(
                new Phrase('Could not save a Creditmemo, see error log for details')
            ),
            12
        );
    }
}
