<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Test\Integration\Model\Indexer\Stock;

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\CatalogInventory\Api\Data\StockStatusInterface;
use Magento\CatalogInventory\Api\StockStatusCriteriaInterfaceFactory;
use Magento\CatalogInventory\Api\StockStatusRepositoryInterface;
use Magento\Indexer\Model\Indexer;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorage;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use Nfourteen\AggregateProduct\Test\Fixture\AggregateProduct as AggregateProductFixture;
use PHPUnit\Framework\TestCase;

class AggregateStockIndexerTest extends TestCase
{
    private ?DataFixtureStorage $fixtures = null;
    private ?StockStatusRepositoryInterface $stockStatusRepository = null;
    private ?StockStatusCriteriaInterfaceFactory $stockStatusCriteriaFactory = null;
    private ?Indexer $indexer = null;

    protected function setUp(): void
    {
        $this->fixtures = DataFixtureStorageManager::getStorage();
        $objectManager = Bootstrap::getObjectManager();
        $this->stockStatusRepository = $objectManager->get(StockStatusRepositoryInterface::class);
        $this->stockStatusCriteriaFactory = $objectManager->get(StockStatusCriteriaInterfaceFactory::class);
        $this->indexer = $objectManager->create(Indexer::class);
        $this->indexer->load('cataloginventory_stock');
    }

    #[
        DataFixture(ProductFixture::class, ['sku' => 'child_in_stock_1'], as: 'child1'),
        DataFixture(ProductFixture::class, ['sku' => 'child_in_stock_2'], as: 'child2'),
        DataFixture(
            AggregateProductFixture::class,
            [
                'sku' => 'aggregate_all_in_stock',
                '_children' => [
                    ['product_id' => '$child1.id$', 'qty' => 1],
                    ['product_id' => '$child2.id$', 'qty' => 1],
                ],
            ],
            as: 'aggregate'
        )
    ]
    public function testParentInStockWhenAllChildrenInStock(): void
    {
        $this->indexer->reindexRow((int)$this->fixtures->get('aggregate')->getId());

        $stockStatus = $this->getStockStatus((int)$this->fixtures->get('aggregate')->getId());

        $this->assertEquals(
            StockStatusInterface::STATUS_IN_STOCK,
            $stockStatus->getStockStatus(),
            'Aggregate should be IN STOCK when all children are in stock'
        );
    }

    #[
        DataFixture(ProductFixture::class, ['sku' => 'child_in_stock_3'], as: 'child1'),
        DataFixture(
            ProductFixture::class,
            ['sku' => 'child_out_of_stock_1', 'extension_attributes' => ['stock_item' => ['is_in_stock' => false]]],
            as: 'child2'
        ),
        DataFixture(
            AggregateProductFixture::class,
            [
                'sku' => 'aggregate_one_out_of_stock',
                '_children' => [
                    ['product_id' => '$child1.id$', 'qty' => 1],
                    ['product_id' => '$child2.id$', 'qty' => 1],
                ],
            ],
            as: 'aggregate'
        )
    ]
    public function testParentOutOfStockWhenAnyChildOutOfStock(): void
    {
        $this->indexer->reindexRow((int)$this->fixtures->get('aggregate')->getId());

        $stockStatus = $this->getStockStatus((int)$this->fixtures->get('aggregate')->getId());

        $this->assertEquals(
            StockStatusInterface::STATUS_OUT_OF_STOCK,
            $stockStatus->getStockStatus(),
            'Aggregate should be OUT OF STOCK when any child is out of stock'
        );
    }

    #[
        DataFixture(ProductFixture::class, ['sku' => 'child_in_stock_4'], as: 'child1'),
        DataFixture(
            ProductFixture::class,
            ['sku' => 'child_no_stock_data', 'extension_attributes' => ['stock_item' => ['qty' => 0]]],
            as: 'child2'
        ),
        DataFixture(
            AggregateProductFixture::class,
            [
                'sku' => 'aggregate_missing_stock',
                '_children' => [
                    ['product_id' => '$child1.id$', 'qty' => 1],
                    ['product_id' => '$child2.id$', 'qty' => 1],
                ],
            ],
            as: 'aggregate'
        )
    ]
    public function testParentOutOfStockWhenChildQtyInsufficient(): void
    {
        $this->indexer->reindexRow((int)$this->fixtures->get('aggregate')->getId());

        $stockStatus = $this->getStockStatus((int)$this->fixtures->get('aggregate')->getId());

        $this->assertEquals(
            StockStatusInterface::STATUS_OUT_OF_STOCK,
            $stockStatus->getStockStatus(),
            'Aggregate should be OUT OF STOCK when child qty (0) cannot cover the configured link qty'
        );
    }

    #[
        DataFixture(ProductFixture::class, ['sku' => 'child_enabled_in_stock'], as: 'child1'),
        DataFixture(
            ProductFixture::class,
            ['sku' => 'child_disabled', 'status' => Status::STATUS_DISABLED],
            as: 'child2'
        ),
        DataFixture(
            AggregateProductFixture::class,
            [
                'sku' => 'aggregate_child_disabled',
                '_children' => [
                    ['product_id' => '$child1.id$', 'qty' => 1],
                    ['product_id' => '$child2.id$', 'qty' => 1],
                ],
            ],
            as: 'aggregate'
        )
    ]
    public function testParentOutOfStockWhenChildDisabled(): void
    {
        $this->indexer->reindexRow((int)$this->fixtures->get('aggregate')->getId());

        $stockStatus = $this->getStockStatus((int)$this->fixtures->get('aggregate')->getId());

        // Disabled children should be considered not salable, so aggregate should be out of stock
        $this->assertEquals(
            StockStatusInterface::STATUS_OUT_OF_STOCK,
            $stockStatus->getStockStatus(),
            'Aggregate should be OUT OF STOCK when any child is disabled'
        );
    }

    #[
        DataFixture(ProductFixture::class, ['sku' => 'child_managed'], as: 'child1'),
        DataFixture(
            ProductFixture::class,
            ['sku' => 'child_unmanaged', 'extension_attributes' => ['stock_item' => [
                'use_config_manage_stock' => 0,
                'manage_stock' => 0,
                'qty' => 0,
            ]]],
            as: 'child2'
        ),
        DataFixture(
            AggregateProductFixture::class,
            [
                'sku' => 'aggregate_unmanaged_child',
                '_children' => [
                    ['product_id' => '$child1.id$', 'qty' => 1],
                    ['product_id' => '$child2.id$', 'qty' => 1],
                ],
            ],
            as: 'aggregate'
        )
    ]
    public function testParentInStockWhenChildDoesNotManageStock(): void
    {
        $this->indexer->reindexRow((int)$this->fixtures->get('aggregate')->getId());

        $stockStatus = $this->getStockStatus((int)$this->fixtures->get('aggregate')->getId());

        $this->assertEquals(
            StockStatusInterface::STATUS_IN_STOCK,
            $stockStatus->getStockStatus(),
            'Aggregate should stay IN STOCK when a child does not manage stock, even at qty 0'
        );
    }

    #[
        DataFixture(ProductFixture::class, ['sku' => 'child_normal'], as: 'child1'),
        DataFixture(
            ProductFixture::class,
            ['sku' => 'child_backorder', 'extension_attributes' => ['stock_item' => [
                'use_config_backorders' => 0,
                'backorders' => 1,
                'qty' => 0,
                'is_in_stock' => true,
            ]]],
            as: 'child2'
        ),
        DataFixture(
            AggregateProductFixture::class,
            [
                'sku' => 'aggregate_backorder_child',
                '_children' => [
                    ['product_id' => '$child1.id$', 'qty' => 1],
                    ['product_id' => '$child2.id$', 'qty' => 2],
                ],
            ],
            as: 'aggregate'
        )
    ]
    public function testParentInStockWhenChildAllowsBackorders(): void
    {
        $this->indexer->reindexRow((int)$this->fixtures->get('aggregate')->getId());

        $stockStatus = $this->getStockStatus((int)$this->fixtures->get('aggregate')->getId());

        $this->assertEquals(
            StockStatusInterface::STATUS_IN_STOCK,
            $stockStatus->getStockStatus(),
            'Aggregate should stay IN STOCK when a child allows backorders, even at qty 0'
        );
    }

    private function getStockStatus(int $productId): StockStatusInterface
    {
        $criteria = $this->stockStatusCriteriaFactory->create();
        $criteria->setProductsFilter($productId);
        $result = $this->stockStatusRepository->getList($criteria);
        $items = $result->getItems();

        $this->assertNotEmpty($items, "stock status index row missing for product {$productId}");

        return reset($items);
    }
}
