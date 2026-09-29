<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Model;

use Nfourteen\AggregateProduct\Api\Data\RelationMetadataInterface;
use Nfourteen\AggregateProduct\Api\RelationMetadataRepositoryInterface;

class RelationMetadataReconciler
{
    public function __construct(
        private readonly RelationMetadataRepositoryInterface $relationMetadataRepository
    ) {
    }

    /**
     * Persist $desiredRelations for $parentProductId, then prune any existing child no longer present.
     * An empty $desiredRelations removes every child.
     *
     * @param int $parentProductId
     * @param RelationMetadataInterface[] $desiredRelations
     * @return RelationMetadataInterface[] the persisted set
     */
    public function reconcile(int $parentProductId, array $desiredRelations): array
    {
        foreach ($desiredRelations as $relation) {
            $relation->setParentId($parentProductId);
        }

        $existingRelations = $this->relationMetadataRepository->getByParentId($parentProductId);

        // Save first so validation runs before any removal: a rejected save leaves the existing link
        // set intact. Only once the desired set persists do we prune what's no longer present.
        $saved = $desiredRelations;
        if (!empty($desiredRelations)) {
            $saved = $this->relationMetadataRepository->save($desiredRelations);
        }

        $desiredChildIds = [];
        foreach ($desiredRelations as $relation) {
            $desiredChildIds[(int)$relation->getProductId()] = true;
        }

        $toDelete = [];
        foreach ($existingRelations as $relation) {
            if (!isset($desiredChildIds[(int)$relation->getProductId()])) {
                $toDelete[] = $relation;
            }
        }

        if (!empty($toDelete)) {
            $this->relationMetadataRepository->delete($toDelete);
        }

        return $saved;
    }
}
