<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Test\Integration\Model\Product\Type;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorage;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use Nfourteen\AggregateProduct\Test\Fixture\AggregateProduct as AggregateProductFixture;
use PHPUnit\Framework\TestCase;

class SalableTest extends TestCase
{
    private ?DataFixtureStorage $fixtures = null;
    private ?ProductRepositoryInterface $productRepository = null;

    protected function setUp(): void
    {
        $this->fixtures = DataFixtureStorageManager::getStorage();
        $this->productRepository = Bootstrap::getObjectManager()->get(ProductRepositoryInterface::class);
    }

    #[
        DataFixture(ProductFixture::class, ['sku' => 'simple_in_stock'], as: 'child1'),
        DataFixture(ProductFixture::class, ['sku' => 'simple_in_stock1'], as: 'child2'),
        DataFixture(
            AggregateProductFixture::class,
            [
                'sku' => 'aggregate_is_salable',
                '_children' => [
                    ['product_id' => '$child1.id$', 'qty' => 10],
                    ['product_id' => '$child2.id$', 'qty' => 10],
                ],
            ],
            as: 'aggregate'
        )
    ]
    public function testIsSalableWhenAllChildrenAreEnabledAndInStock(): void
    {
        $product = $this->productRepository->get($this->fixtures->get('aggregate')->getSku());

        $this->assertTrue($product->isSalable());
    }

    #[
        DataFixture(ProductFixture::class, ['sku' => 'simple_in_stock'], as: 'child1'),
        DataFixture(
            ProductFixture::class,
            ['sku' => 'simple_disabled', 'status' => Status::STATUS_DISABLED],
            as: 'child2'
        ),
        DataFixture(
            AggregateProductFixture::class,
            [
                'sku' => 'aggregate_child_disabled',
                '_children' => [
                    ['product_id' => '$child1.id$', 'qty' => 10],
                    ['product_id' => '$child2.id$', 'qty' => 10],
                ],
            ],
            as: 'aggregate'
        )
    ]
    public function testIsSalableWhenChildIsDisabled(): void
    {
        $product = $this->productRepository->get($this->fixtures->get('aggregate')->getSku());

        $this->assertFalse($product->isSalable());
    }

    #[
        DataFixture(ProductFixture::class, ['sku' => 'simple_in_stock'], as: 'child1'),
        DataFixture(
            ProductFixture::class,
            ['sku' => 'simple_out_of_stock', 'extension_attributes' => ['stock_item' => ['is_in_stock' => false]]],
            as: 'child2'
        ),
        DataFixture(
            AggregateProductFixture::class,
            [
                'sku' => 'aggregate_child_out_of_stock',
                '_children' => [
                    ['product_id' => '$child1.id$', 'qty' => 10],
                    ['product_id' => '$child2.id$', 'qty' => 10],
                ],
            ],
            as: 'aggregate'
        )
    ]
    public function testIsSalableWhenChildIsOutOfStock(): void
    {
        $product = $this->productRepository->get($this->fixtures->get('aggregate')->getSku());

        $this->assertFalse($product->isSalable());
    }
}
