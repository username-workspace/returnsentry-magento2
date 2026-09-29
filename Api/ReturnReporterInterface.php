<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Api;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use ReturnSentry\Connector\Api\Data\Assessment;
use ReturnSentry\Connector\Api\Data\ReturnRequest;

/**
 * @api
 */
interface ReturnReporterInterface
{
    /**
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function report(ReturnRequest $request): Assessment;
}
