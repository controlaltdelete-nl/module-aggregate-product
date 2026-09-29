<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Test\Integration\Model\Indexer\Stock;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\CatalogInventory\Api\Data\StockStatusInterface;
use Magento\CatalogInventory\Api\StockStatusCriteriaInterfaceFactory;
use Magento\CatalogInventory\Api\StockStatusRepositoryInterface;
use Magento\CatalogInventory\Model\Indexer\Stock\Processor as StockIndexProcessor;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Registry;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorage;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Nfourteen\AggregateProduct\Test\Fixture\AggregateProduct as AggregateProductFixture;
use PHPUnit\Framework\TestCase;

/**
 * Deleting a child product removes its link rows via FK cascade, which never fires the mview
 * triggers, so neither the changelog nor a scheduled-mode reindex would see the change. The
 * delete observers must capture the parents pre-delete and force-reindex them post-commit.
 * DbIsolation is off because delete-commit callbacks only run at transaction level zero.
 */
#[DbIsolation(false)]
class ChildDeleteReindexTest extends TestCase
{
    private ?DataFixtureStorage $fixtures = null;
    private ?ProductRepositoryInterface $productRepository = null;
    private ?StockStatusRepositoryInterface $stockStatusRepository = null;
    private ?StockStatusCriteriaInterfaceFactory $stockStatusCriteriaFactory = null;
    private ?IndexerInterface $indexer = null;

    protected function setUp(): void
    {
        $this->fixtures = DataFixtureStorageManager::getStorage();
        $objectManager = Bootstrap::getObjectManager();
        $this->productRepository = $objectManager->get(ProductRepositoryInterface::class);
        $this->stockStatusRepository = $objectManager->get(StockStatusRepositoryInterface::class);
        $this->stockStatusCriteriaFactory = $objectManager->get(StockStatusCriteriaInterfaceFactory::class);
        $this->indexer = $objectManager->get(IndexerRegistry::class)->get(StockIndexProcessor::INDEXER_ID);
    }

    protected function tearDown(): void
    {
        if ($this->indexer->isScheduled()) {
            $this->indexer->setScheduled(false);
        }
    }

    #[
        DataFixture(ProductFixture::class, ['sku' => 'delete_child_in_stock'], as: 'child1'),
        DataFixture(
            ProductFixture::class,
            ['sku' => 'delete_child_out_of_stock', 'extension_attributes' => ['stock_item' => ['is_in_stock' => false]]],
            as: 'child2'
        ),
        DataFixture(
            AggregateProductFixture::class,
            [
                'sku' => 'aggregate_child_delete_reindex',
                '_children' => [
                    ['product_id' => '$child1.id$', 'qty' => 1],
                    ['product_id' => '$child2.id$', 'qty' => 1],
                ],
            ],
            as: 'aggregate'
        )
    ]
    public function testChildDeleteForceReindexesParentInScheduledMode(): void
    {
        $parentId = (int)$this->fixtures->get('aggregate')->getId();

        $this->indexer->reindexRow($parentId);
        $this->assertEquals(
            StockStatusInterface::STATUS_OUT_OF_STOCK,
            $this->getStockStatus($parentId)?->getStockStatus(),
            'Precondition: aggregate should be OUT OF STOCK while it contains an out-of-stock child'
        );

        // Scheduled mode is where the gap lives: the cascade bypasses the changelog triggers and
        // an unforced reindexList() no-ops, so only the forced post-commit reindex can recover.
        $this->indexer->setScheduled(true);

        $this->deleteProduct((string)$this->fixtures->get('child2')->getSku());

        $this->assertEquals(
            StockStatusInterface::STATUS_IN_STOCK,
            $this->getStockStatus($parentId)?->getStockStatus(),
            'Parent must be recomputed from the remaining relations immediately after the child delete commits'
        );
    }

    private function deleteProduct(string $sku): void
    {
        $registry = Bootstrap::getObjectManager()->get(Registry::class);
        $registry->unregister('isSecureArea');
        $registry->register('isSecureArea', true);
        try {
            $this->productRepository->deleteById($sku);
        } finally {
            $registry->unregister('isSecureArea');
            $registry->register('isSecureArea', false);
        }
    }

    private function getStockStatus(int $productId): ?StockStatusInterface
    {
        $criteria = $this->stockStatusCriteriaFactory->create();
        $criteria->setProductsFilter($productId);
        $items = $this->stockStatusRepository->getList($criteria)->getItems();

        return !empty($items) ? reset($items) : null;
    }
}
