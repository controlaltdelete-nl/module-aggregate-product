<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Model\Product\Child;

use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;

class ChildStatusResolver
{
    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory
    ) {
    }

    /**
     * Subset of $childIds whose status is enabled at default scope (store 0), mirroring the
     * stock indexer's store_id = 0 status check.
     *
     * @param int[] $childIds
     * @return int[]
     */
    public function getEnabledChildIds(array $childIds): array
    {
        if (empty($childIds)) {
            return [];
        }

        $collection = $this->productCollectionFactory->create();
        $collection->addIdFilter($childIds);
        $collection->addAttributeToFilter('status', ProductStatus::STATUS_ENABLED);

        return array_map('intval', $collection->getAllIds());
    }

    /**
     * Empty input counts as not enabled.
     *
     * @param int[] $childIds
     */
    public function allEnabled(array $childIds): bool
    {
        return !empty($childIds)
            && count($this->getEnabledChildIds($childIds)) === count(array_unique($childIds));
    }
}
