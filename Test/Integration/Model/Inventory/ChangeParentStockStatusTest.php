<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Test\Integration\Model\Inventory;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventory\Api\StockItemCriteriaInterfaceFactory;
use Magento\CatalogInventory\Api\StockItemRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorage;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Nfourteen\AggregateProduct\Model\Inventory\ChangeParentStockStatus;
use Nfourteen\AggregateProduct\Test\Fixture\AggregateProduct as AggregateProductFixture;
use PHPUnit\Framework\TestCase;

/**
 * ChangeParentStockStatus owns one responsibility: recompute and persist the aggregate parent's stock
 * status from its children. Cache/index invalidation is intentionally NOT done here — the stock item
 * save triggers reindexRow via ResourceModel\Stock\Item::_afterSave and the stock indexer's
 * CacheCleaner owns the flush.
 */
class ChangeParentStockStatusTest extends TestCase
{
    private ?ChangeParentStockStatus $changeParentStockStatus = null;
    private ?StockRegistryInterface $stockRegistry = null;
    private ?StockItemRepositoryInterface $stockItemRepository = null;
    private ?StockItemCriteriaInterfaceFactory $stockItemCriteriaFactory = null;
    private ?StockConfigurationInterface $stockConfiguration = null;
    private ?DataFixtureStorage $fixtures = null;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->changeParentStockStatus = $objectManager->get(ChangeParentStockStatus::class);
        $this->stockRegistry = $objectManager->get(StockRegistryInterface::class);
        $this->stockItemRepository = $objectManager->get(StockItemRepositoryInterface::class);
        $this->stockItemCriteriaFactory = $objectManager->get(StockItemCriteriaInterfaceFactory::class);
        $this->stockConfiguration = $objectManager->get(StockConfigurationInterface::class);
        $this->fixtures = DataFixtureStorageManager::getStorage();
    }

    #[
        DbIsolation(true),
        DataFixture(ProductFixture::class, ['sku' => 'csp-child'], as: 'child'),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'csp-parent',
            '_children' => [
                ['product_id' => '$child.id$', 'qty' => 1],
            ],
        ], as: 'aggregate'),
    ]
    public function testParentFlipsOutOfStockWhenItsOnlyChildGoesOutOfStock(): void
    {
        $parentId = (int)$this->fixtures->get('aggregate')->getId();
        $childId = (int)$this->fixtures->get('child')->getId();

        $this->assertTrue(
            (bool)$this->loadParentStockItem($parentId)->getIsInStock(),
            'parent starts in stock'
        );

        // Drive the child out of stock directly; this path does not invoke the parent observer, so the
        // explicit service call below is the sole flip.
        $childStockItem = $this->stockRegistry->getStockItem($childId);
        $childStockItem->setIsInStock(false);
        $this->stockItemRepository->save($childStockItem);

        $this->changeParentStockStatus->execute([$childId]);

        $this->assertFalse(
            (bool)$this->loadParentStockItem($parentId)->getIsInStock(),
            'parent flipped out of stock when its only child went out of stock'
        );
    }

    #[
        DbIsolation(true),
        DataFixture(ProductFixture::class, ['sku' => 'csp-shared-child'], as: 'child'),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'csp-parent-a',
            '_children' => [
                ['product_id' => '$child.id$', 'qty' => 1],
            ],
        ], as: 'aggregateA'),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'csp-parent-b',
            '_children' => [
                ['product_id' => '$child.id$', 'qty' => 1],
            ],
        ], as: 'aggregateB'),
    ]
    public function testEveryParentSharingAChildFlipsOutOfStockWhenThatChildGoesOutOfStock(): void
    {
        $parentAId = (int)$this->fixtures->get('aggregateA')->getId();
        $parentBId = (int)$this->fixtures->get('aggregateB')->getId();
        $childId = (int)$this->fixtures->get('child')->getId();

        $this->assertTrue(
            (bool)$this->loadParentStockItem($parentAId)->getIsInStock(),
            'first parent starts in stock'
        );
        $this->assertTrue(
            (bool)$this->loadParentStockItem($parentBId)->getIsInStock(),
            'second parent starts in stock'
        );

        // Drive the shared child out of stock directly; this path does not invoke the parent observer,
        // so the single explicit service call below must recompute both parents that relate to it.
        $childStockItem = $this->stockRegistry->getStockItem($childId);
        $childStockItem->setIsInStock(false);
        $this->stockItemRepository->save($childStockItem);

        $this->changeParentStockStatus->execute([$childId]);

        $this->assertFalse(
            (bool)$this->loadParentStockItem($parentAId)->getIsInStock(),
            'first parent flipped out of stock when the shared child went out of stock'
        );
        $this->assertFalse(
            (bool)$this->loadParentStockItem($parentBId)->getIsInStock(),
            'second parent flipped out of stock when the shared child went out of stock'
        );
    }

    private function loadParentStockItem(int $productId): StockItemInterface
    {
        $criteria = $this->stockItemCriteriaFactory->create();
        $criteria->setScopeFilter($this->stockConfiguration->getDefaultScopeId());
        $criteria->setProductsFilter($productId);
        $items = $this->stockItemRepository->getList($criteria)->getItems();
        $this->assertNotEmpty($items, 'parent stock item must exist');

        return reset($items);
    }
}
