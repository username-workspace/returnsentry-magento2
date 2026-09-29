<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Plugin;

use Magento\Sales\Exception\CouldNotRefundException;
use ReturnSentry\Connector\Model\Refund\Gate;

class ExplainRefundStop
{
    public function __construct(private readonly Gate $gate)
    {
    }

    /**
     * @throws CouldNotRefundException
     */
    public function aroundExecute(object $subject, callable $proceed, mixed ...$arguments): mixed
    {
        $this->gate->takeStop();
        try {
            return $proceed(...$arguments);
        } catch (CouldNotRefundException $exception) {
            throw $this->gate->takeStop() ?? $exception;
        }
    }
}
