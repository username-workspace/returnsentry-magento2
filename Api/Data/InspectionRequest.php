<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Api\Data;

class InspectionRequest
{
    public const MAX_ITEMS = 500;
    public const MAX_PHOTOS = 30;

    /**
     * @param InspectionItem[] $items
     * @param string[] $photoUrls
     */
    public function __construct(
        public readonly string $externalId,
        public readonly string $returnExternalId,
        public readonly \DateTimeInterface $inspectedAt,
        public readonly array $items,
        public readonly ?string $note = null,
        public readonly array $photoUrls = []
    ) {
    }
}
