<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Test\Unit\Model\Resolver;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\TestCase;
use ReturnSentry\Connector\Api\Data\ReturnTerms as Terms;
use ReturnSentry\Connector\Model\Checkout\CartReturnTerms;
use ReturnSentry\Connector\Model\Resolver\ReturnTerms;

class ReturnTermsTest extends TestCase
{
    public function testResolvesTheTermsOfTheCart(): void
    {
        $quote = $this->createMock(Quote::class);
        $terms = $this->createMock(CartReturnTerms::class);
        $terms->method('forQuote')->with($quote)->willReturn(new Terms(true, 6.5));

        $resolved = $this->resolve(new ReturnTerms($terms), ['model' => $quote]);

        $this->assertSame(['paid' => true, 'label_cost' => 6.5], $resolved);
    }

    public function testNeedsTheCartModel(): void
    {
        $this->expectException(LocalizedException::class);

        $this->resolve(new ReturnTerms($this->createMock(CartReturnTerms::class)), []);
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private function resolve(ReturnTerms $resolver, array $value): array
    {
        return $resolver->resolve(
            $this->createMock(Field::class),
            null,
            $this->createMock(ResolveInfo::class),
            $value
        );
    }
}
