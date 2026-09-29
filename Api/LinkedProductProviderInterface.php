<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Api;

use Magento\Framework\Exception\NoSuchEntityException;
use Nfourteen\AggregateProduct\Api\Data\LinkedProductInterface;
use Nfourteen\AggregateProduct\Model\ResourceModel\Product\Child\Collection as ChildCollection;

/**
 * Read-only service for retrieving linked products of aggregate products.
 *
 * This is NOT a repository - it does not persist data. It assembles
 * LinkedProduct objects from RelationMetadataRepository and product collections.
 *
 * @api
 */
interface LinkedProductProviderInterface
{
    /**
     * Get linked products for a single aggregate product
     *
     * @param int $aggregateProductId
     * @return \Nfourteen\AggregateProduct\Api\Data\LinkedProductInterface[]
     * @throws \Magento\Framework\Exception\NoSuchEntityException If no linked products found
     */
    public function getForProduct(int $aggregateProductId): array;

    /**
     * Get linked products for multiple aggregate products
     *
     * @param int[] $aggregateProductIds
     * @return array<int, \Nfourteen\AggregateProduct\Api\Data\LinkedProductInterface[]> Keyed by parent product ID
     */
    public function getForProducts(array $aggregateProductIds): array;

    /**
     * Raw child collection for callers needing custom attribute selection or filters.
     *
     * @param int[] $aggregateProductIds
     * @return \Nfourteen\AggregateProduct\Model\ResourceModel\Product\Child\Collection
     */
    public function getChildCollection(array $aggregateProductIds): ChildCollection;
}
