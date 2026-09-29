<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Model\Inventory;

use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventory\Api\StockItemCriteriaInterfaceFactory;
use Magento\CatalogInventory\Api\StockItemRepositoryInterface;
use Nfourteen\AggregateProduct\Model\ResourceModel\RelationMetadata;

/**
 * Change stock status of aggregate product by child product id
 */
class ChangeParentStockStatus
{
    public function __construct(
        private readonly RelationMetadata $relationMetadata,
        private readonly StockItemCriteriaInterfaceFactory $criteriaInterfaceFactory,
        private readonly StockItemRepositoryInterface $stockItemRepository,
        private readonly StockConfigurationInterface $stockConfiguration
    ) {
    }

    /**
     * Recompute stock status for every aggregate parent bundling any of the given children.
     *
     * Reads are batched so a shared-child flip across many parents costs a handful of queries.
     *
     * @param int[] $childProductIds
     */
    public function execute(array $childProductIds): void
    {
        $childProductIds = array_values(array_unique(array_map('intval', $childProductIds)));
        if (empty($childProductIds)) {
            return;
        }

        $parentIds = $this->relationMetadata->getParentIdsByChildren($childProductIds);
        if (empty($parentIds)) {
            return;
        }

        $childrenByParent = $this->relationMetadata->getChildrenIdsWithQtyForParents($parentIds);
        $parentStockItems = $this->loadStockItemsByProductId($parentIds);
        if (empty($parentStockItems)) {
            return;
        }

        $allChildIds = [];
        foreach ($childrenByParent as $children) {
            foreach (array_keys($children) as $childId) {
                $allChildIds[$childId] = true;
            }
        }
        $childStockItems = $this->loadStockItemsByProductId(array_keys($allChildIds));

        foreach ($parentIds as $parentId) {
            $parentStockItem = $parentStockItems[(int)$parentId] ?? null;
            if ($parentStockItem === null) {
                continue;
            }

            $childrenIdsWithQty = $childrenByParent[(int)$parentId] ?? [];
            if (empty($childrenIdsWithQty)) {
                continue;
            }

            $childrenIsInStock = $this->areChildrenInStock($childrenIdsWithQty, $childStockItems);

            if ($this->shouldUpdateParent($parentStockItem, $childrenIsInStock)) {
                $parentStockItem->setIsInStock($childrenIsInStock);
                $parentStockItem->setStockStatusChangedAuto(1);
                $this->stockItemRepository->save($parentStockItem);
            }
        }

        // The stock item save above triggers reindexRow via ResourceModel\Stock\Item::_afterSave, and
        // the stock indexer's CacheCleaner owns the flush.
    }

    /**
     * @param int[] $productIds
     * @return array<int, StockItemInterface> productId => stock item
     */
    private function loadStockItemsByProductId(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        $criteria = $this->criteriaInterfaceFactory->create();
        $criteria->setScopeFilter($this->stockConfiguration->getDefaultScopeId());
        $criteria->setProductsFilter($productIds);
        $collection = $this->stockItemRepository->getList($criteria);

        $byProductId = [];
        foreach ($collection->getItems() as $item) {
            $byProductId[(int)$item->getProductId()] = $item;
        }

        return $byProductId;
    }

    /**
     * Mirrors DefaultStock indexer semantics so the runtime decision matches the stock index.
     *
     * @param array<int, float> $childrenIdsWithQty childId => requiredQty
     * @param array<int, StockItemInterface> $childStockItems productId => stock item
     */
    private function areChildrenInStock(array $childrenIdsWithQty, array $childStockItems): bool
    {
        foreach ($childrenIdsWithQty as $childId => $requiredQty) {
            $childItem = $childStockItems[$childId] ?? null;
            if ($childItem === null) {
                return false;
            }

            if ((int)$childItem->getManageStock() !== 1) {
                continue;
            }

            if ($childItem->getIsInStock() !== true) {
                return false;
            }

            if ((int)$childItem->getBackorders() === 0 && $childItem->getQty() < $requiredQty) {
                return false;
            }
        }

        return true;
    }

    /**
     * Guard automatic status flips against manual admin overrides.
     *
     * A parent is only flipped back in stock automatically when its current status was set
     * automatically — a manual admin override (stock_status_changed_auto = 0) stays out of stock.
     *
     * @param StockItemInterface $parentStockItem
     * @param bool $childrenIsInStock
     * @return bool
     */
    private function shouldUpdateParent(StockItemInterface $parentStockItem, bool $childrenIsInStock): bool
    {
        return $parentStockItem->getIsInStock() !== $childrenIsInStock &&
            ($childrenIsInStock === false || $parentStockItem->getStockStatusChangedAuto());
    }
}
