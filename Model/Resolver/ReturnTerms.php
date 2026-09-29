<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Resolver;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Quote\Model\Quote;
use ReturnSentry\Connector\Model\Checkout\CartReturnTerms;

class ReturnTerms implements ResolverInterface
{
    public function __construct(private readonly CartReturnTerms $terms)
    {
    }

    /**
     * @param array<mixed>|null $value
     * @param array<mixed>|null $args
     * @return array{paid: bool, label_cost: float|null}
     * @throws LocalizedException
     */
    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null): array
    {
        $quote = $value['model'] ?? null;
        if (!$quote instanceof Quote) {
            throw new LocalizedException(__('"model" value should be specified'));
        }
        $terms = $this->terms->forQuote($quote);
        return ['paid' => $terms->isPaid(), 'label_cost' => $terms->getLabelCost()];
    }
}
