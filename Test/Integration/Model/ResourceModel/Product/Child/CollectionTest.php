<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Test\Integration\Model\ResourceModel\Product\Child;

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorage;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use Nfourteen\AggregateProduct\Api\LinkedProductProviderInterface;
use Nfourteen\AggregateProduct\Model\ResourceModel\Product\Child\Collection;
use Nfourteen\AggregateProduct\Test\Fixture\AggregateProduct as AggregateProductFixture;
use PHPUnit\Framework\TestCase;

class CollectionTest extends TestCase
{
    private ?DataFixtureStorage $fixtures = null;

    protected function setUp(): void
    {
        $this->fixtures = DataFixtureStorageManager::getStorage();
    }

    #[
        DataFixture(ProductFixture::class, ['sku' => 'child_in_stock'], as: 'child_in_stock'),
        DataFixture(
            ProductFixture::class,
            ['sku' => 'child_out_of_stock', 'extension_attributes' => ['stock_item' => ['is_in_stock' => false]]],
            as: 'child_out_of_stock'
        ),
        DataFixture(
            AggregateProductFixture::class,
            [
                'sku' => 'aggregate_with_oos_child',
                '_children' => [
                    ['product_id' => '$child_in_stock.id$', 'qty' => 1],
                    ['product_id' => '$child_out_of_stock.id$', 'qty' => 1],
                ],
            ],
            as: 'aggregate'
        )
    ]
    public function testCollectionReturnsOutOfStockProducts(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        /** @var LinkedProductProviderInterface $linkedProductProvider */
        $linkedProductProvider = $objectManager->get(LinkedProductProviderInterface::class);

        $aggregate = $this->fixtures->get('aggregate');

        $linkedProducts = $linkedProductProvider->getForProduct(
            (int)$aggregate->getId()
        );

        $this->assertCount(
            2,
            $linkedProducts,
            'Collection should return both in-stock and out-of-stock children'
        );

        $skus = array_map(fn($p) => $p->getProductSku(), $linkedProducts);
        $this->assertContains('child_out_of_stock', $skus, 'Out-of-stock child should be included');
        $this->assertContains('child_in_stock', $skus, 'In-stock child should be included');
    }

    #[
        DataFixture(ProductFixture::class, ['sku' => 'salable_child'], as: 'salable'),
        DataFixture(
            ProductFixture::class,
            ['sku' => 'disabled_child', 'status' => Status::STATUS_DISABLED],
            as: 'disabled'
        ),
        DataFixture(
            ProductFixture::class,
            ['sku' => 'oos_child', 'extension_attributes' => ['stock_item' => ['is_in_stock' => false]]],
            as: 'oos'
        ),
        DataFixture(
            AggregateProductFixture::class,
            [
                'sku' => 'aggregate_mixed_children',
                '_children' => [
                    ['product_id' => '$salable.id$', 'qty' => 1],
                    ['product_id' => '$disabled.id$', 'qty' => 1],
                    ['product_id' => '$oos.id$', 'qty' => 1],
                ],
            ],
            as: 'aggregate'
        )
    ]
    public function testGetEnabledItemsExcludesDisabledButKeepsOutOfStock(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        /** @var LinkedProductProviderInterface $linkedProductProvider */
        $linkedProductProvider = $objectManager->get(LinkedProductProviderInterface::class);

        $aggregate = $this->fixtures->get('aggregate');

        /** @var Collection $collection */
        $collection = $linkedProductProvider->getChildCollection([(int)$aggregate->getId()]);

        $this->assertTrue(
            $collection->getFlag('has_stock_status_filter'),
            'has_stock_status_filter bypasses AddStockStatusToCollection — it is why OOS children appear below'
        );
        $this->assertCount(
            3,
            $collection->getItems(),
            'getItems() should show every linked child for the UI, including disabled/out-of-stock'
        );

        $enabledSkus = array_map(
            static fn ($child) => $child->getSku(),
            $collection->getEnabledItems()
        );
        sort($enabledSkus);

        // Stock is gated separately (aggregate stock index / MSI); getEnabledItems() only filters
        // out the disabled child, so the enabled-but-out-of-stock child is still returned.
        $this->assertSame(
            ['oos_child', 'salable_child'],
            $enabledSkus,
            'getEnabledItems() should exclude only the disabled child'
        );
    }
}
