<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Observer;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Nfourteen\AggregateProduct\Api\RelationMetadataRepositoryInterface;
use Nfourteen\AggregateProduct\Model\Product\Type\Aggregate;

/**
 * Fires for both direct collection loads and ProductRepository::getList(), so relation
 * metadata is attached on every read path.
 */
class HandleRelationMetadataForProductCollection implements ObserverInterface
{
    public function __construct(
        private readonly RelationMetadataRepositoryInterface $relationMetadataRepository
    ) {
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        /** @var Collection $collection */
        $collection = $observer->getEvent()->getCollection();
        $aggregateProductIds = array_reduce($collection->getItems(), function (array $carry, $product) {
            if ($product->getTypeId() === Aggregate::TYPE_CODE) {
                $carry[] = $product->getId();
            }
            return $carry;
        }, []);

        if (empty($aggregateProductIds)) {
            return;
        }

        $relationsByParentId = $this->relationMetadataRepository->getList($aggregateProductIds);
        foreach ($relationsByParentId as $aggregateProductId => $relations) {
            $aggregateProduct = $collection->getItemById($aggregateProductId);
            if ($aggregateProduct === null) {
                continue;
            }

            $extensionAttributes = $aggregateProduct->getExtensionAttributes();
            $extensionAttributes->setAggregateRelations($relations);
            $aggregateProduct->setExtensionAttributes($extensionAttributes);
        }
    }
}
