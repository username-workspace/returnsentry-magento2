<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model\System;

use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Model\Sync\RetryStore;
use ReturnSentry\Connector\Model\Sync\Sender;
use ReturnSentry\Connector\Model\System\PendingErasures;

class PendingErasuresTest extends TestCase
{
    public function testPendingErasuresAreShownInTheAdmin(): void
    {
        $retries = $this->createMock(RetryStore::class);
        $retries->method('pending')->with(Sender::ERASURE)->willReturn(['count' => 2, 'lastError' => 'HTTP 401']);
        $message = new PendingErasures($retries);

        $this->assertTrue($message->isDisplayed());
        $this->assertStringContainsString('2 customer erasures are still waiting', $message->getText());
        $this->assertStringContainsString('HTTP 401', $message->getText());
    }

    public function testNothingIsShownOnceEverythingWasSent(): void
    {
        $retries = $this->createMock(RetryStore::class);
        $retries->method('pending')->willReturn(['count' => 0, 'lastError' => null]);

        $this->assertFalse((new PendingErasures($retries))->isDisplayed());
    }
}
