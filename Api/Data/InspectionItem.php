<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Api\Data;

class InspectionItem
{
    public const OUTCOMES = [
        'conforming',
        'empty_parcel',
        'substituted_object',
        'wrong_item',
        'counterfeit_item',
        'older_item',
        'missing_items',
        'used_or_worn',
        'damaged_by_customer',
        'missing_tags',
        'tags_reattached',
        'missing_parts',
        'altered_item',
        'hygiene_seal_broken',
        'packaging_missing',
        'manufacturing_defect',
        'transport_damage',
        'other',
    ];

    public function __construct(
        public readonly string $sku,
        public readonly int $quantity,
        public readonly string $outcome
    ) {
    }
}
