<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Test\Integration\Quote;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Test\Fixture\AddProductToCart as AddProductToCartFixture;
use Magento\Quote\Test\Fixture\GuestCart as GuestCartFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorage;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Nfourteen\AggregateProduct\Model\Product\Type\Aggregate;
use Nfourteen\AggregateProduct\Test\Fixture\AggregateProduct as AggregateProductFixture;
use PHPUnit\Framework\TestCase;

/**
 * When a guest cart is merged into a customer cart that already holds the same aggregate, the two
 * parent lines must collapse into one (qty summed) and the child dummy rows must not be duplicated.
 * Quote\Item\Compare short-circuits on matching SKU, so a stable identity is enough — but the snapshot
 * options the aggregate persists could have made compare() diverge on option-JSON drift, so this guards
 * the real merge path end to end.
 */
class CartMergeDedupeTest extends TestCase
{
    private ?CartRepositoryInterface $cartRepository = null;
    private ?DataFixtureStorage $fixtures = null;

    protected function setUp(): void
    {
        $this->cartRepository = Bootstrap::getObjectManager()->get(CartRepositoryInterface::class);
        $this->fixtures = DataFixtureStorageManager::getStorage();
    }

    #[
        DbIsolation(true),
        DataFixture(ProductFixture::class, as: 'child1'),
        DataFixture(ProductFixture::class, as: 'child2'),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'agg-merge-parent',
            '_children' => [
                ['product_id' => '$child1.id$', 'qty' => 2],
                ['product_id' => '$child2.id$', 'qty' => 3],
            ],
        ], as: 'aggregate'),
        DataFixture(GuestCartFixture::class, as: 'target'),
        DataFixture(AddProductToCartFixture::class, ['cart_id' => '$target.id$', 'product_id' => '$aggregate.id$', 'qty' => 1]),
        DataFixture(GuestCartFixture::class, as: 'source'),
        DataFixture(AddProductToCartFixture::class, ['cart_id' => '$source.id$', 'product_id' => '$aggregate.id$', 'qty' => 1]),
    ]
    public function testMergingSameAggregateDoesNotDuplicateChildRows(): void
    {
        /** @var Quote $target */
        $target = $this->cartRepository->get((int)$this->fixtures->get('target')->getId());
        /** @var Quote $source */
        $source = $this->cartRepository->get((int)$this->fixtures->get('source')->getId());

        // Sanity: each cart starts with one parent + two child rows.
        $this->assertCount(1, $target->getAllVisibleItems());
        $this->assertCount(3, $target->getAllItems());

        $target->merge($source);
        $target->setIsActive(true);
        $target->collectTotals();

        $this->assertSingleMergedAggregate($target, 'in-memory merge result');

        // The customer keeps the SAVED cart, so the dedupe must survive persistence:
        // child rows re-parented on save, no duplicates rehydrated from quote_item.
        $this->cartRepository->save($target);
        /** @var Quote $reloaded */
        $reloaded = $this->cartRepository->get((int)$target->getId());

        $this->assertSingleMergedAggregate($reloaded, 'persisted merge result');
        $this->assertSame(1, (int)$reloaded->getItemsCount(), 'persisted items_count reflects the single visible line');
        $this->assertSame(2.0, (float)$reloaded->getItemsQty(), 'persisted items_qty reflects the summed parent qty');
    }

    private function assertSingleMergedAggregate(Quote $quote, string $context): void
    {
        $visible = $quote->getAllVisibleItems();
        $this->assertCount(1, $visible, "{$context}: the two aggregate parents must collapse into a single line");

        $parent = array_values($visible)[0];
        $this->assertSame(Aggregate::TYPE_CODE, $parent->getProductType());
        $this->assertSame(2.0, (float)$parent->getQty(), "{$context}: merged parent qty is the sum of both carts");

        $childRows = array_filter(
            $quote->getAllItems(),
            static fn ($item) => (bool)$item->getParentItemId() || $item->getParentItem() !== null
        );
        $this->assertCount(2, $childRows, "{$context}: child dummy rows must not be duplicated by the merge");

        $childParentIds = array_map(
            static fn ($item) => (int)($item->getParentItem() ? $item->getParentItem()->getId() : $item->getParentItemId()),
            $childRows
        );
        $this->assertSame(
            [(int)$parent->getId(), (int)$parent->getId()],
            array_values($childParentIds),
            "{$context}: every surviving child row stays attached to the single merged parent"
        );
    }
}
