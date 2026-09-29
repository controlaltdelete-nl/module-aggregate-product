<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Test\Integration\Model\Indexer\Stock;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\CatalogInventory\Api\Data\StockStatusInterface;
use Magento\CatalogInventory\Api\StockStatusCriteriaInterfaceFactory;
use Magento\CatalogInventory\Api\StockStatusRepositoryInterface;
use Magento\CatalogInventory\Model\Indexer\Stock\Processor as StockIndexProcessor;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorage;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Nfourteen\AggregateProduct\Api\RelationMetadataRepositoryInterface;
use Nfourteen\AggregateProduct\Test\Fixture\AggregateProduct as AggregateProductFixture;
use PHPUnit\Framework\TestCase;

/**
 * Proves the mview subscription on catalog_product_aggregate_link recomputes the parent in
 * scheduled mode, where RelationReindexer's reindex calls no-op. DbIsolation is off
 * because subscribing the view issues DDL, which would commit the test transaction.
 */
#[DbIsolation(false)]
class AggregateLinkMviewTest extends TestCase
{
    private ?DataFixtureStorage $fixtures = null;
    private ?RelationMetadataRepositoryInterface $relationMetadataRepository = null;
    private ?StockStatusRepositoryInterface $stockStatusRepository = null;
    private ?StockStatusCriteriaInterfaceFactory $stockStatusCriteriaFactory = null;
    private ?IndexerInterface $indexer = null;

    protected function setUp(): void
    {
        $this->fixtures = DataFixtureStorageManager::getStorage();
        $objectManager = Bootstrap::getObjectManager();
        $this->relationMetadataRepository = $objectManager->get(RelationMetadataRepositoryInterface::class);
        $this->stockStatusRepository = $objectManager->get(StockStatusRepositoryInterface::class);
        $this->stockStatusCriteriaFactory = $objectManager->get(StockStatusCriteriaInterfaceFactory::class);
        // Must be the registry's shared instance: the invalidator's processor consults it for
        // isScheduled(), and a separately created Indexer would leave the processor in realtime
        // mode, reindexing synchronously and masking whether the changelog path works.
        $this->indexer = $objectManager->get(IndexerRegistry::class)->get(StockIndexProcessor::INDEXER_ID);
    }

    protected function tearDown(): void
    {
        if ($this->indexer->isScheduled()) {
            $this->indexer->setScheduled(false);
        }
    }

    #[
        DataFixture(ProductFixture::class, ['sku' => 'mview_child_in_stock'], as: 'child1'),
        DataFixture(
            ProductFixture::class,
            ['sku' => 'mview_child_out_of_stock', 'extension_attributes' => ['stock_item' => ['is_in_stock' => false]]],
            as: 'child2'
        ),
        DataFixture(
            AggregateProductFixture::class,
            [
                'sku' => 'aggregate_mview_relation_change',
                '_children' => [
                    ['product_id' => '$child1.id$', 'qty' => 1],
                    ['product_id' => '$child2.id$', 'qty' => 1],
                ],
            ],
            as: 'aggregate'
        )
    ]
    public function testRelationWriteReindexesParentThroughChangelog(): void
    {
        $parentId = (int)$this->fixtures->get('aggregate')->getId();
        $outOfStockChildId = (int)$this->fixtures->get('child2')->getId();

        $this->indexer->reindexRow($parentId);
        $this->assertEquals(
            StockStatusInterface::STATUS_OUT_OF_STOCK,
            $this->getStockStatus($parentId)?->getStockStatus(),
            'Precondition: aggregate should be OUT OF STOCK while it contains an out-of-stock child'
        );

        // Installs the changelog triggers, including the one on catalog_product_aggregate_link.
        $this->indexer->setScheduled(true);

        $toDelete = array_filter(
            $this->relationMetadataRepository->getByParentId($parentId),
            fn ($relation) => (int)$relation->getProductId() === $outOfStockChildId
        );
        $this->assertNotEmpty($toDelete, 'Precondition: out-of-stock child relation should exist');
        $this->relationMetadataRepository->delete(array_values($toDelete));

        $this->assertEquals(
            StockStatusInterface::STATUS_OUT_OF_STOCK,
            $this->getStockStatus($parentId)?->getStockStatus(),
            'Index must stay stale before the changelog is processed: the invalidator no-ops in scheduled mode'
        );

        $this->indexer->getView()->update();

        $this->assertEquals(
            StockStatusInterface::STATUS_IN_STOCK,
            $this->getStockStatus($parentId)?->getStockStatus(),
            'Processing the changelog should recompute the parent from the new relations'
        );
    }

    private function getStockStatus(int $productId): ?StockStatusInterface
    {
        $criteria = $this->stockStatusCriteriaFactory->create();
        $criteria->setProductsFilter($productId);
        $items = $this->stockStatusRepository->getList($criteria)->getItems();

        return !empty($items) ? reset($items) : null;
    }
}
