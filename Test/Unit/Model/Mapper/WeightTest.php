<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model\Mapper;

use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Test\Unit\Fixtures;

class WeightTest extends TestCase
{
    use Fixtures;

    public function testConvertsTheStoreWeightUnitToGrams(): void
    {
        $this->assertSame(450, $this->weight('kgs')->grams('0.4504', 1));
        $this->assertSame(454, $this->weight('lbs')->grams(1.0, 1));
    }

    public function testLeavesOutMissingZeroOrImplausibleWeights(): void
    {
        $this->assertNull($this->weight('kgs')->grams(null, 1));
        $this->assertNull($this->weight('kgs')->grams('0.0000', 1));
        $this->assertNull($this->weight('kgs')->grams(1500.0, 1));
        $this->assertNull($this->weight('stones')->grams(1.0, 1));
    }
}
