<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Test\Integration\Plugin\Catalog\Product;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorage;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use Nfourteen\AggregateProduct\Plugin\Catalog\Product\ProductIdentitiesExtender;
use Nfourteen\AggregateProduct\Test\Fixture\AggregateProduct as AggregateProductFixture;
use PHPUnit\Framework\TestCase;

/**
 * A child's identities must carry its aggregate parents' cache tags so a child save
 * invalidates the parent's FPC immediately.
 */
class ProductIdentitiesExtenderTest extends TestCase
{
    private ?DataFixtureStorage $fixtures = null;
    private ?ProductRepositoryInterface $productRepository = null;

    protected function setUp(): void
    {
        $this->fixtures = DataFixtureStorageManager::getStorage();
        $this->productRepository = Bootstrap::getObjectManager()->get(ProductRepositoryInterface::class);
    }

    #[
        DataFixture(ProductFixture::class, ['sku' => 'identities-child'], as: 'child'),
        DataFixture(ProductFixture::class, ['sku' => 'identities-loner'], as: 'loner'),
        DataFixture(
            AggregateProductFixture::class,
            [
                'sku' => 'identities-aggregate',
                '_children' => [
                    ['product_id' => '$child.id$', 'qty' => 2],
                ],
            ],
            as: 'aggregate'
        )
    ]
    public function testChildIdentitiesIncludeAggregateParentTag(): void
    {
        // Fixture saves warm the extender's per-request memo before the links exist; reset it to
        // read identities as a fresh request would.
        Bootstrap::getObjectManager()->get(ProductIdentitiesExtender::class)->_resetState();

        $aggregate = $this->fixtures->get('aggregate');
        $parentTag = Product::CACHE_TAG . '_' . $aggregate->getId();

        /** @var Product $child */
        $child = $this->productRepository->get('identities-child');
        $this->assertContains(
            $parentTag,
            $child->getIdentities(),
            'Linked child identities must include the aggregate parent cache tag'
        );

        /** @var Product $loner */
        $loner = $this->productRepository->get('identities-loner');
        $this->assertNotContains(
            $parentTag,
            $loner->getIdentities(),
            'An unlinked product must not gain aggregate parent cache tags'
        );

        /** @var Product $parent */
        $parent = $this->productRepository->get('identities-aggregate');
        $this->assertContains(
            Product::CACHE_TAG . '_' . $aggregate->getId(),
            $parent->getIdentities(),
            'The aggregate itself keeps its own cache tag'
        );
    }
}
