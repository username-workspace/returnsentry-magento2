<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Api;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * @api
 */
interface CustomerErasureInterface
{
    /**
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function eraseByEmail(string $email, int $websiteId): void;
}
