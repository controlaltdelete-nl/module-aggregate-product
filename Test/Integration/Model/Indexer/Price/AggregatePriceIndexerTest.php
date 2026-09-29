<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Test\Integration\Model\Indexer\Price;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Framework\App\ResourceConnection;
use Magento\Indexer\Model\Indexer;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorage;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Nfourteen\AggregateProduct\Test\Fixture\AggregateProduct as AggregateProductFixture;
use PHPUnit\Framework\TestCase;

/**
 * The aggregate type registers AggregateProductPrice as its price indexerModel in
 * product_types.xml; without it the parent has no catalog_product_index_price row
 * and disappears from price-indexed frontend queries.
 */
class AggregatePriceIndexerTest extends TestCase
{
    private ?DataFixtureStorage $fixtures = null;
    private ?ResourceConnection $resource = null;
    private ?Indexer $indexer = null;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->fixtures = DataFixtureStorageManager::getStorage();
        $this->resource = $objectManager->get(ResourceConnection::class);
        $this->indexer = $objectManager->create(Indexer::class);
        $this->indexer->load('catalog_product_price');
    }

    #[
        DbIsolation(true),
        DataFixture(ProductFixture::class, ['sku' => 'price-idx-child-1'], as: 'child1'),
        DataFixture(ProductFixture::class, ['sku' => 'price-idx-child-2'], as: 'child2'),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'price-idx-parent',
            'price' => 10.00,
            '_children' => [
                ['product_id' => '$child1.id$', 'qty' => 1],
                ['product_id' => '$child2.id$', 'qty' => 2],
            ],
        ], as: 'aggregate'),
    ]
    public function testAggregateHasPriceIndexRow(): void
    {
        $parentId = (int)$this->fixtures->get('aggregate')->getId();

        $this->indexer->reindexRow($parentId);

        $row = $this->getPriceIndexRow($parentId);
        $this->assertNotEmpty($row, 'aggregate parent must have a price index row after reindex');
        $this->assertSame(10.0, (float)$row['price'], 'indexed price must be the parent\'s own price');
        $this->assertSame(10.0, (float)$row['final_price'], 'indexed final_price must be the parent\'s own price');
    }

    private function getPriceIndexRow(int $productId): array|false
    {
        $connection = $this->resource->getConnection();

        return $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName('catalog_product_index_price'))
                ->where('entity_id = ?', $productId)
                ->where('customer_group_id = ?', 0)
                ->where('website_id = ?', 1)
        );
    }
}
