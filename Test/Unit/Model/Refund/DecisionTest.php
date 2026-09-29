<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model\Refund;

use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Model\Refund\Decision;

class DecisionTest extends TestCase
{
    public function testFraudEvidenceIsWhatReturnSentrySaysWhateverTheReasonCodes(): void
    {
        $this->assertTrue($this->decision('order_fraud_evidence', true)->fraudEvidence);
        $this->assertFalse($this->decision('inspection_fraud', null)->fraudEvidence);
        $this->assertFalse($this->decision('inspection_abuse', false)->fraudEvidence);
        $this->assertFalse($this->decision('inspection_fraud', 'true')->fraudEvidence);
    }

    private function decision(string $code, mixed $fraudEvidence): Decision
    {
        return Decision::fromResponse([
            'decision' => 'hold',
            'requestedAmount' => 10,
            'approvedAmount' => 0,
            'reasons' => [['code' => $code, 'label' => $code]],
            'fraudEvidence' => $fraudEvidence,
        ]);
    }
}
