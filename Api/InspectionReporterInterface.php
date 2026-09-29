<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Api;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use ReturnSentry\Connector\Api\Data\InspectionRequest;

/**
 * @api
 */
interface InspectionReporterInterface
{
    /**
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function report(InspectionRequest $request): void;
}
