<?php

declare(strict_types=1);

namespace ReturnSentry\Connector\Model\Mapper;

use Magento\Framework\App\ResourceConnection;

class ProductSkus
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * @param int[] $productIds
     * @return array<int, string>
     */
    public function forIds(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter($productIds)));
        if ($productIds === []) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('catalog_product_entity'), ['entity_id', 'sku'])
            ->where('entity_id IN (?)', $productIds);
        return array_map('strval', $connection->fetchPairs($select));
    }
}
