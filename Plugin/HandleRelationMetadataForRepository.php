<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Plugin;

use Magento\Catalog\Api\Data\ProductExtensionInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Nfourteen\AggregateProduct\Api\RelationMetadataRepositoryInterface;
use Nfourteen\AggregateProduct\Model\Product\Type\Aggregate;

/**
 * Enriches repository reads with the aggregate_relations extension attribute. Writes are
 * persisted inside the product save by \Nfourteen\AggregateProduct\Model\Product\SaveHandler.
 *
 * @see \Nfourteen\AggregateProduct\Observer\HandleRelationMetadataForProductCollection for getList() implementation
 */
class HandleRelationMetadataForRepository
{
    public function __construct(
        private readonly RelationMetadataRepositoryInterface $relationMetadataRepository
    ) {
    }

    public function afterGetById(
        ProductRepositoryInterface $subject,
        ProductInterface $result,
        int $productId,
        bool $editMode = false,
        ?int $storeId = null,
        bool $forceReload = false
    ): ProductInterface {
        return $this->getAggregateRelations($result);
    }

    public function afterGet(
        ProductRepositoryInterface $subject,
        ProductInterface $result,
        string $sku,
        bool $editMode = false,
        ?int $storeId = null,
        bool $forceReload = false
    ): ProductInterface {
        return $this->getAggregateRelations($result);
    }

    private function getAggregateRelations(ProductInterface $product): ProductInterface
    {
        if ($product->getTypeId() !== Aggregate::TYPE_CODE) {
            return $product;
        }

        if ($product->getExtensionAttributes()->getAggregateRelations() !== null) {
            return $product;
        }

        $relations = $this->relationMetadataRepository->getByParentId((int)$product->getId());

        $extensionAttributes = $this->getExtensionAttributes($product);
        $extensionAttributes->setAggregateRelations($relations);
        $product->setExtensionAttributes($extensionAttributes);

        return $product;
    }

    private function getExtensionAttributes(ProductInterface $product): ProductExtensionInterface
    {
        return $product->getExtensionAttributes();
    }
}
