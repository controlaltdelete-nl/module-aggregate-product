<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Model\Product;

use Magento\Framework\EntityManager\Operation\ExtensionInterface;
use Nfourteen\AggregateProduct\Model\Product\Type\Aggregate;
use Nfourteen\AggregateProduct\Model\RelationMetadataReconciler;

/**
 * Persists the aggregate_relations extension attribute inside the product save , so the
 * save-time reindex sees the new relation. A null attribute means the caller didn't
 * address relations (no-op); a present value — including an explicit empty array — is
 * authoritative and reconciles the link set.
 */
class SaveHandler implements ExtensionInterface
{
    public function __construct(
        private readonly RelationMetadataReconciler $reconciler
    ) {
    }

    /**
     * @param object $entity
     * @param array $arguments
     * @return object
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function execute($entity, $arguments = [])
    {
        if ($entity->getTypeId() !== Aggregate::TYPE_CODE) {
            return $entity;
        }

        $extensionAttributes = $entity->getExtensionAttributes();
        if ($extensionAttributes === null) {
            return $entity;
        }

        $relations = $extensionAttributes->getAggregateRelations();
        if ($relations === null) {
            return $entity;
        }

        $saved = $this->reconciler->reconcile((int)$entity->getId(), $relations);

        $extensionAttributes->setAggregateRelations($saved);
        $entity->setExtensionAttributes($extensionAttributes);

        // Make the post-commit priceReindexCallback reindex the parent
        // even though a relations change does not touch a tracked entity column.
        $entity->setData('aggregate_relations_changed', true);

        return $entity;
    }
}
