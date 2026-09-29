<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Api;

use Nfourteen\AggregateProduct\Api\Data\RelationMetadataInterface;

/**
 * @api
 */
interface RelationMetadataRepositoryInterface
{
    /**
     * @param int $parentProductId
     * @return \Nfourteen\AggregateProduct\Api\Data\RelationMetadataInterface[]
     */
    public function getByParentId(int $parentProductId): array;

    /**
     * @param \Nfourteen\AggregateProduct\Api\Data\RelationMetadataInterface[] $relationMetadata
     * @return \Nfourteen\AggregateProduct\Api\Data\RelationMetadataInterface[]
     */
    public function save(array $relationMetadata): array;

    /**
     * @param \Nfourteen\AggregateProduct\Api\Data\RelationMetadataInterface[] $links
     * @return void
     */
    public function delete(array $links): void;

    /**
     * @param int[] $parentProductIds
     * @return array<int, \Nfourteen\AggregateProduct\Api\Data\RelationMetadataInterface[]>
     */
    public function getList(array $parentProductIds): array;
}