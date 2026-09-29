<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Test\Integration\Model\Inventory;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventory\Api\StockItemCriteriaInterfaceFactory;
use Magento\CatalogInventory\Api\StockItemRepositoryInterface;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorage;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Nfourteen\AggregateProduct\Test\Fixture\AggregateProduct as AggregateProductFixture;
use PHPUnit\Framework\TestCase;

/**
 * ChangeParentStockStatusTest proves the recompute logic by calling the service directly;
 * this test proves the di.xml wiring: a child product save through the repository
 * dispatches catalog_product_save_after, whose SaveInventoryDataObserver must run the
 * module's ParentItemProcessor from its pool — no direct service call here.
 */
class ParentStockStatusObserverPathTest extends TestCase
{
    private ?ProductRepositoryInterface $productRepository = null;
    private ?StockItemRepositoryInterface $stockItemRepository = null;
    private ?StockItemCriteriaInterfaceFactory $stockItemCriteriaFactory = null;
    private ?StockConfigurationInterface $stockConfiguration = null;
    private ?DataFixtureStorage $fixtures = null;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->productRepository = $objectManager->get(ProductRepositoryInterface::class);
        $this->stockItemRepository = $objectManager->get(StockItemRepositoryInterface::class);
        $this->stockItemCriteriaFactory = $objectManager->get(StockItemCriteriaInterfaceFactory::class);
        $this->stockConfiguration = $objectManager->get(StockConfigurationInterface::class);
        $this->fixtures = DataFixtureStorageManager::getStorage();
    }

    #[
        DbIsolation(true),
        DataFixture(ProductFixture::class, ['sku' => 'observer-path-child'], as: 'child'),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'observer-path-parent',
            '_children' => [
                ['product_id' => '$child.id$', 'qty' => 1],
            ],
        ], as: 'aggregate'),
    ]
    public function testChildStockSaveViaRepositoryFlipsParent(): void
    {
        $parentId = (int)$this->fixtures->get('aggregate')->getId();
        $childId = (int)$this->fixtures->get('child')->getId();

        $this->assertTrue(
            (bool)$this->loadParentStockItem($parentId)->getIsInStock(),
            'parent starts in stock'
        );

        $child = $this->productRepository->getById($childId, true);
        $child->setStockData(['qty' => 0, 'is_in_stock' => 0]);
        $this->productRepository->save($child);

        $this->assertFalse(
            (bool)$this->loadParentStockItem($parentId)->getIsInStock(),
            'saving the child through the repository must flip the parent via the observer pool'
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
