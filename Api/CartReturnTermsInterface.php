<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Api;

use Magento\Framework\Exception\NoSuchEntityException;
use ReturnSentry\Connector\Api\Data\ReturnTerms;

/**
 * Webapi service: Magento reads each method's return type from its @return annotation.
 *
 * @api
 */
interface CartReturnTermsInterface
{
    /**
     * @return \ReturnSentry\Connector\Api\Data\ReturnTerms
     * @throws NoSuchEntityException
     */
    public function get(int $cartId): ReturnTerms;

    /**
     * @return \ReturnSentry\Connector\Api\Data\ReturnTerms
     * @throws NoSuchEntityException
     */
    public function getForGuestCart(string $cartId): ReturnTerms;
}
