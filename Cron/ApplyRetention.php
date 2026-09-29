<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Cron;

use ReturnSentry\Connector\Model\Retention;

class ApplyRetention
{
    public function __construct(private readonly Retention $retention)
    {
    }

    public function execute(): void
    {
        $this->retention->purgeVisitorIds();
        $this->retention->deleteOrphanAuthorizations();
        $this->retention->deleteAbandonedInspections();
    }
}
