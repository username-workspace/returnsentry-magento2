<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model\Mapper;

use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Model\Mapper\Fields;

class FieldsTest extends TestCase
{
    public function testCompactDropsEmptyValuesSoThatNoEmptyObjectIsSentAsAList(): void
    {
        $this->assertSame(
            '{"context":"checkout","order":{"amount":0}}',
            json_encode(Fields::compact([
                'context' => 'checkout',
                'device' => ['visitorId' => null, 'ip' => ''],
                'order' => ['amount' => 0, 'items' => []],
            ]))
        );
    }

    public function testDatesAreSentInUtc(): void
    {
        $this->assertSame('2026-09-01T10:00:00Z', Fields::iso('2026-09-01 10:00:00'));
        $this->assertSame('2026-09-10T06:00:00Z', Fields::isoFrom(new \DateTimeImmutable('2026-09-10T08:00:00+02:00')));
        $this->assertNull(Fields::iso(null));
    }
}
