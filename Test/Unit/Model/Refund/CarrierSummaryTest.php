<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model\Refund;

use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Model\Refund\CarrierSummary;

class CarrierSummaryTest extends TestCase
{
    public function testDescribesTheParcelAsTheCarrierReportsIt(): void
    {
        $summary = new CarrierSummary();

        $this->assertSame(
            'Carrier: delivered on 2026-09-04, left in the mailbox, weak proof.',
            (string) $summary->describe([
                'state' => 'delivered',
                'proofStrength' => 'weak',
                'deliveredAt' => '2026-09-04T10:15:00.000Z',
                'handover' => 'mailbox',
            ])
        );
        $this->assertSame(
            'Carrier: waiting at the pickup point.',
            (string) $summary->describe(['state' => 'awaiting_pickup', 'proofStrength' => 'none'])
        );
        $this->assertSame('Carrier: no carrier data.', (string) $summary->describe([]));
    }
}
