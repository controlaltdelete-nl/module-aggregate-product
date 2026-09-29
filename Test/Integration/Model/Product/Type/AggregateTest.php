<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Test\Integration\Model\Product\Type;

use Magento\Catalog\Api\Data\ProductExtensionInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type\AbstractType;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Framework\DataObject;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorage;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use Nfourteen\AggregateProduct\Api\Data\RelationMetadataInterface;
use Nfourteen\AggregateProduct\Api\RelationMetadataRepositoryInterface;
use Nfourteen\AggregateProduct\Test\Fixture\AggregateProduct as AggregateProductFixture;
use PHPUnit\Framework\TestCase;

class AggregateTest extends TestCase
{
    private ?ObjectManagerInterface $objectManager = null;
    private ?ProductRepositoryInterface $productRepository = null;
    private ?RelationMetadataRepositoryInterface $relationRepository = null;
    private ?DataFixtureStorage $fixtures = null;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->productRepository = $this->objectManager->create(ProductRepositoryInterface::class);
        $this->relationRepository = $this->objectManager->get(RelationMetadataRepositoryInterface::class);
        $this->fixtures = DataFixtureStorageManager::getStorage();
    }

    #[DataFixture(ProductFixture::class, ['sku' => 'simple1'], as: 'child1')]
    #[DataFixture(ProductFixture::class, ['sku' => 'simple2'], as: 'child2')]
    #[DataFixture(AggregateProductFixture::class, [
        'sku' => 'aggregate-parent',
        '_children' => [
            ['product_id' => '$child1.id$', 'qty' => 1],
            ['product_id' => '$child2.id$', 'qty' => 2],
        ],
    ], as: 'aggregate')]
    public function testGetAggregateProducts(): void
    {
        /** @var Product $product */
        $product = $this->productRepository->get('aggregate-parent', false, null, true);

        $aggregateChildren = $product->getTypeInstance()->getAggregateProducts($product);

        $expectedQtyBySku = [
            'simple1' => 1.0,
            'simple2' => 2.0,
        ];

        // The parent/child relationship is carried by the aggregate_relations extension
        // attribute, not by a parent_id column on the child row: the child collection
        // deliberately omits parent_id and groups by entity_id so a child shared across
        // multiple parents appears once.
        /** @var ProductExtensionInterface $extensionAttributes */
        $extensionAttributes = $product->getExtensionAttributes();
        $linkedData = $extensionAttributes->getAggregateRelations();

        $actualQtyBySku = [];
        /** @var Product $aggregateChild */
        foreach ($aggregateChildren as $aggregateChild) {
            $sku = $aggregateChild->getSku();

            $matchedLink = null;
            foreach ($linkedData as $link) {
                if ((int)$link->getProductId() === (int)$aggregateChild->getId()) {
                    $matchedLink = $link;
                    break;
                }
            }
            $this->assertNotNull($matchedLink, "child {$sku} must have a matching aggregate relation");
            $this->assertEquals((int)$product->getId(), (int)$matchedLink->getParentId());
            $actualQtyBySku[$sku] = (float)$matchedLink->getQty();
        }
        $this->assertEquals($expectedQtyBySku, $actualQtyBySku);
    }

    #[DataFixture(ProductFixture::class, as: 'child1')]
    #[DataFixture(ProductFixture::class, as: 'child2')]
    #[DataFixture(AggregateProductFixture::class, [
        'sku' => 'aggregate-cart',
        '_children' => [
            ['product_id' => '$child1.id$', 'qty' => 1],
            ['product_id' => '$child2.id$', 'qty' => 5],
        ],
    ], as: 'aggregate')]
    public function testPrepareForCart(): void
    {
        $buyRequest = new DataObject(['qty' => 1]);

        /** @var Product $aggregateProduct */
        $aggregateProduct = $this->productRepository->get('aggregate-cart', false, null, true);

        $result = $aggregateProduct->getTypeInstance()->prepareForCart($buyRequest, $aggregateProduct);
        // prepareForCart returns the error message string on failure
        $this->assertIsArray($result, is_string($result) ? "prepareForCart failed: {$result}" : '');
        $this->assertCount(3, $result); // parent + 2 children

        $configuredAggregateQuantities = [];
        $links = $aggregateProduct->getExtensionAttributes()->getAggregateRelations();
        /** @var RelationMetadataInterface $link */
        foreach ($links as $link) {
            $configuredAggregateQuantities[(int)$link->getProductId()] = (float)$link->getQty();
        }

        $actualQtyByChildId = [];
        /** @var Product $product */
        foreach ($result as $product) {
            if ($product->getSku() !== 'aggregate-cart') {
                $childProductId = (int)$product->getId();
                $this->assertEquals($aggregateProduct->getId(), $product->getCustomOption('parent_product_id')->getValue());
                $actualQtyByChildId[$childProductId] = (float)$aggregateProduct->getCustomOption('product_qty_' . $childProductId)->getValue();
            }
        }
        $this->assertEquals($configuredAggregateQuantities, $actualQtyByChildId, 'prepared child qtys must match the configured relations');
    }

    /**
     * A child's cart qty is the structurally configured link qty, not a user choice, so lite-mode
     * preparation (wishlist/reorder representation) must scale it just like full mode. Gating the
     * setCartQty on strict mode left lite-mode child rows stuck at qty 1 regardless of the link qty.
     */
    #[DataFixture(ProductFixture::class, as: 'child1')]
    #[DataFixture(ProductFixture::class, as: 'child2')]
    #[DataFixture(AggregateProductFixture::class, [
        'sku' => 'aggregate-lite',
        '_children' => [
            ['product_id' => '$child1.id$', 'qty' => 4],
            ['product_id' => '$child2.id$', 'qty' => 7],
        ],
    ], as: 'aggregate')]
    public function testLiteModePreparesChildQtyFromLinkNotOne(): void
    {
        /** @var Product $aggregateProduct */
        $aggregateProduct = $this->productRepository->get('aggregate-lite', false, null, true);

        $configuredQtyByChildId = [];
        foreach ($aggregateProduct->getExtensionAttributes()->getAggregateRelations() as $link) {
            $configuredQtyByChildId[(int)$link->getProductId()] = (float)$link->getQty();
        }
        $this->assertNotContains(1.0, $configuredQtyByChildId, 'fixture must use non-1 qty to make the assertion meaningful');

        $result = $aggregateProduct->getTypeInstance()->prepareForCartAdvanced(
            new DataObject(['qty' => 1]),
            $aggregateProduct,
            AbstractType::PROCESS_MODE_LITE
        );
        // prepareForCartAdvanced returns the error message string on failure
        $this->assertIsArray($result, is_string($result) ? "prepareForCartAdvanced failed: {$result}" : '');
        $this->assertCount(3, $result, 'lite mode still expands parent + 2 children');

        $actualCartQtyByChildId = [];
        /** @var Product $product */
        foreach ($result as $product) {
            if ($product->getSku() === 'aggregate-lite') {
                continue;
            }
            $actualCartQtyByChildId[(int)$product->getId()] = (float)$product->getCartQty();
        }
        $this->assertEquals(
            $configuredQtyByChildId,
            $actualCartQtyByChildId,
            'lite-mode child qty must equal the configured link qty, not default 1'
        );
    }

    /**
     * getChildrenIds must return core's grouped shape [groupId => [childId, ...]]. Core
     * consumers such as CatalogInventory\Model\StockIndex::rebuild array_merge(...) the outer groups,
     * which TypeErrors on a flat int[]. Every aggregate child is required, so $required does not
     * change membership.
     */
    #[DataFixture(ProductFixture::class, as: 'child1')]
    #[DataFixture(ProductFixture::class, as: 'child2')]
    #[DataFixture(AggregateProductFixture::class, [
        'sku' => 'aggregate-children-ids',
        '_children' => [
            ['product_id' => '$child1.id$', 'qty' => 1],
            ['product_id' => '$child2.id$', 'qty' => 2],
        ],
    ], as: 'aggregate')]
    public function testGetChildrenIdsReturnsCoreGroupedShape(): void
    {
        /** @var Product $aggregateProduct */
        $aggregateProduct = $this->productRepository->get('aggregate-children-ids', false, null, true);
        $parentId = (int)$aggregateProduct->getId();
        $expectedChildIds = [
            (int)$this->fixtures->get('child1')->getId(),
            (int)$this->fixtures->get('child2')->getId(),
        ];

        $type = $aggregateProduct->getTypeInstance();
        $grouped = $type->getChildrenIds($parentId);

        $this->assertCount(1, $grouped, 'a single required group is returned');
        $this->assertContainsOnly('array', $grouped, null, 'outer array holds groups, not flat ids');

        // Core consumes the grouped shape via array_merge(...$grouped); this must not TypeError.
        $flattened = array_map('intval', array_merge(...$grouped));
        $this->assertEqualsCanonicalizing($expectedChildIds, $flattened);

        // $required=false must not change membership: every aggregate child is required.
        $this->assertEqualsCanonicalizing(
            $expectedChildIds,
            array_map('intval', array_merge(...$type->getChildrenIds($parentId, false)))
        );
    }

    /**
     * Once an item is in the cart, getOrderOptions must read the purchase-time snapshot persisted
     * on the parent item, never live relations. Deleting every relation afterwards must not change the
     * order option and must not throw uncaught NoSuchEntityException.
     */
    #[DataFixture(ProductFixture::class, as: 'child1')]
    #[DataFixture(ProductFixture::class, as: 'child2')]
    #[DataFixture(AggregateProductFixture::class, [
        'sku' => 'aggregate-order-snapshot',
        '_children' => [
            ['product_id' => '$child1.id$', 'qty' => 2],
            ['product_id' => '$child2.id$', 'qty' => 3],
        ],
    ], as: 'aggregate')]
    public function testGetOrderOptionsReadsSnapshotAfterRelationDeletion(): void
    {
        /** @var Product $aggregateProduct */
        $aggregateProduct = $this->productRepository->get('aggregate-order-snapshot', false, null, true);
        $type = $aggregateProduct->getTypeInstance();

        // Add-to-cart preparation persists the children snapshot onto the parent product.
        $prepared = $type->prepareForCart(new DataObject(['qty' => 1]), $aggregateProduct);
        $this->assertIsArray($prepared);

        $before = $type->getOrderOptions($aggregateProduct);
        $this->assertArrayHasKey('aggregate_config', $before, 'snapshot drives the order option');
        $this->assertEqualsCanonicalizing(
            [2.0, 3.0],
            array_map(static fn ($child) => (float)$child['qty'], $before['aggregate_config'][0]['value'])
        );

        // Delete every relation through the real repository path (clears the linked-product cache).
        $links = $this->relationRepository->getByParentId((int)$aggregateProduct->getId());
        $this->assertNotEmpty($links);
        $this->relationRepository->delete($links);
        $this->assertEmpty($this->relationRepository->getByParentId((int)$aggregateProduct->getId()));

        // Snapshot is authoritative: same option, no throw, no null-deref.
        $after = $type->getOrderOptions($aggregateProduct);
        $this->assertArrayHasKey('aggregate_config', $after, 'snapshot survives relation deletion');
        $value = $after['aggregate_config'][0]['value'];
        $this->assertCount(2, $value);
        $this->assertEqualsCanonicalizing(
            [2.0, 3.0],
            array_map(static fn ($child) => (float)$child['qty'], $value)
        );
        foreach ($value as $child) {
            $this->assertNotSame('', (string)$child['name'], 'child name preserved in snapshot');
        }
    }

    /**
     * An aggregate ships its children, so its weight is the sum of each child's weight times the
     * configured link qty — the parent itself stores none.
     */
    #[DataFixture(ProductFixture::class, ['weight' => 0.1], as: 'child1')]
    #[DataFixture(ProductFixture::class, ['weight' => 0.25], as: 'child2')]
    #[DataFixture(AggregateProductFixture::class, [
        'sku' => 'aggregate-weight',
        '_children' => [
            ['product_id' => '$child1.id$', 'qty' => 10],
            ['product_id' => '$child2.id$', 'qty' => 2],
        ],
    ], as: 'aggregate')]
    public function testGetWeightSumsChildWeightTimesConfiguredQty(): void
    {
        /** @var Product $aggregateProduct */
        $aggregateProduct = $this->productRepository->get('aggregate-weight', false, null, true);

        $this->assertEqualsWithDelta(1.5, (float)$aggregateProduct->getWeight(), 0.0001);
    }

    /**
     * A weight typed onto the aggregate itself is discarded on save, so no stored number can drift
     * away from the derived total or win over it on read.
     */
    #[DataFixture(ProductFixture::class, ['weight' => 0.1], as: 'child')]
    #[DataFixture(AggregateProductFixture::class, [
        'sku' => 'aggregate-weight-stored',
        'weight' => 99,
        '_children' => [
            ['product_id' => '$child.id$', 'qty' => 10],
        ],
    ], as: 'aggregate')]
    public function testWeightTypedOnTheAggregateIsNeverStored(): void
    {
        /** @var Product $aggregateProduct */
        $aggregateProduct = $this->productRepository->get('aggregate-weight-stored', false, null, true);

        $this->assertEmpty($aggregateProduct->getData('weight'), 'the typed 99 must not reach the attribute');
        $this->assertEqualsWithDelta(1.0, (float)$aggregateProduct->getWeight(), 0.0001);
    }

    /**
     * Deriving weight must not throw for an aggregate whose relations have all been removed.
     */
    #[DataFixture(AggregateProductFixture::class, ['sku' => 'aggregate-weight-childless'], as: 'aggregate')]
    public function testGetWeightIsZeroWithoutChildren(): void
    {
        /** @var Product $aggregateProduct */
        $aggregateProduct = $this->productRepository->get('aggregate-weight-childless', false, null, true);

        $this->assertSame(0.0, (float)$aggregateProduct->getWeight());
    }
}
