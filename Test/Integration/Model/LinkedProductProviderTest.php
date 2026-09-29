<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Test\Integration\Model;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorage;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use Nfourteen\AggregateProduct\Api\LinkedProductProviderInterface;
use Nfourteen\AggregateProduct\Test\Fixture\AggregateProduct as AggregateProductFixture;
use PHPUnit\Framework\TestCase;

class LinkedProductProviderTest extends TestCase
{
    private ?DataFixtureStorage $fixtures = null;
    private ?LinkedProductProviderInterface $linkedProductProvider = null;

    protected function setUp(): void
    {
        $this->fixtures = DataFixtureStorageManager::getStorage();
        $this->linkedProductProvider = Bootstrap::getObjectManager()->get(LinkedProductProviderInterface::class);
    }

    #[
        DataFixture(ProductFixture::class, ['sku' => 'full-child1', 'price' => 19.99], as: 'child1'),
        DataFixture(ProductFixture::class, ['sku' => 'full-child2', 'price' => 29.99], as: 'child2'),
        DataFixture(
            AggregateProductFixture::class,
            [
                'sku' => 'aggregate-full-test',
                '_children' => [
                    ['product_id' => '$child1.id$', 'qty' => 3],
                    ['product_id' => '$child2.id$', 'qty' => 5],
                ],
            ],
            as: 'aggregate'
        )
    ]
    public function testGetForProductLoadsEavAttributes(): void
    {
        $aggregate = $this->fixtures->get('aggregate');

        $linkedProducts = $this->linkedProductProvider->getForProduct(
            (int)$aggregate->getId()
        );

        $this->assertCount(2, $linkedProducts);

        $expectedQtyBySku = ['full-child1' => 3.0, 'full-child2' => 5.0];
        $assertedSkus = [];
        foreach ($linkedProducts as $linkedProduct) {
            $product = $linkedProduct->getProduct();
            $sku = $product->getSku();

            $this->assertNotNull($product->getName(), 'Name should be loaded');
            $this->assertNotNull($product->getPrice(), 'Price should be loaded');
            // $product->getStatus() coalesces null to STATUS_ENABLED, so only the raw loaded value
            // proves the status attribute was actually selected by the provider.
            $this->assertNotNull($product->getData('status'), 'Status should be loaded');

            $this->assertSame(
                (int)$product->getId(),
                (int)$linkedProduct->getRelationMetadata()->getProductId(),
                "Relation metadata for {$sku} must reference the child product"
            );
            $this->assertSame(
                (int)$aggregate->getId(),
                (int)$linkedProduct->getRelationMetadata()->getParentId(),
                "Relation metadata for {$sku} must reference the aggregate parent"
            );
            $this->assertArrayHasKey($sku, $expectedQtyBySku, 'Provider returned an unexpected child');
            $this->assertSame(
                $expectedQtyBySku[$sku],
                (float)$linkedProduct->getQty(),
                "Link qty for {$sku} must match the fixture"
            );
            $assertedSkus[] = $sku;
        }

        $this->assertEqualsCanonicalizing(array_keys($expectedQtyBySku), $assertedSkus);
    }
}
