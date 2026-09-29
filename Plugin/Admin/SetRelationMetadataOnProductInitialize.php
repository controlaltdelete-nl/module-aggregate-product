<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Plugin\Admin;

use Magento\Catalog\Controller\Adminhtml\Product\Initialization\Helper;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\RequestInterface;
use Nfourteen\AggregateProduct\Api\Data\RelationMetadataInterface;
use Nfourteen\AggregateProduct\Api\Data\RelationMetadataInterfaceFactory;
use Nfourteen\AggregateProduct\Model\Product\Type\Aggregate;

/**
 * Maps the admin aggregate-grid POST rows onto the aggregate_relations extension attribute
 * before the save, so the SaveHandler persists them inside the product save.
 */
class SetRelationMetadataOnProductInitialize
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RelationMetadataInterfaceFactory $relationMetadataFactory
    ) {
    }

    /**
     * @param \Magento\Catalog\Controller\Adminhtml\Product\Initialization\Helper $subject
     * @param \Magento\Catalog\Model\Product $product
     * @return \Magento\Catalog\Model\Product
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterInitialize(Helper $subject, Product $product): Product
    {
        if ($product->getTypeId() !== Aggregate::TYPE_CODE) {
            return $product;
        }

        $postData = $this->request->getParam(RelationMetadataInterface::LINK_TYPE);

        // An absent param means the aggregate grid was not part of this submission (e.g. a save
        // from a controller that doesn't render it, or a mass/attribute update). Treat it as a
        // no-op so existing relations are preserved. Only a *present* value is authoritative — an
        // explicit empty array still means "remove every child".
        if (!is_array($postData)) {
            return $product;
        }

        $relations = [];
        foreach ($postData as $relationData) {
            // A row with no child id is unidentifiable grid noise; skip it. A row missing qty
            // keeps qty 0.0 so repository validation rejects the whole save with a clear message
            // rather than silently dropping (and then deleting) the child.
            if (!isset($relationData['id'])) {
                continue;
            }

            $relation = $this->relationMetadataFactory->create();
            // id mapping imposed by js/dynamic-rows/dynamic-rows-grid.js::identificationProperty
            $relation->setProductId((int)$relationData['id']);
            $relation->setQty((float)($relationData[RelationMetadataInterface::QTY] ?? 0.0));
            $relations[] = $relation;
        }

        $extensionAttributes = $product->getExtensionAttributes();
        $extensionAttributes->setAggregateRelations($relations);
        $product->setExtensionAttributes($extensionAttributes);

        return $product;
    }
}
