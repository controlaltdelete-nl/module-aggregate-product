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
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Quote\Test\Fixture\AddProductToCart as AddProductToCartFixture;
use Magento\Quote\Test\Fixture\GuestCart as GuestCartFixture;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Test\Fixture\ProductCondition as ProductConditionFixture;
use Magento\SalesRule\Test\Fixture\Rule as RuleFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorage;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Nfourteen\AggregateProduct\Model\Product\Type\Aggregate;
use Nfourteen\AggregateProduct\Test\Fixture\AggregateProduct as AggregateProductFixture;
use PHPUnit\Framework\TestCase;

/**
 * Hidden aggregate child rows must be invisible to cart price rules. A merchant rule conditioned
 * on a child SKU must not discount the priced parent line. The aggregate architecture relies on this
 * isolation — the parent is the only priced line, children are zero-priced dummy rows that exist only
 * to drive the MSI stock lifecycle.
 */
class CartPriceRuleIsolationTest extends TestCase
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
        DataFixture(ProductFixture::class, ['sku' => 'agg-iso-child', 'price' => 5.0], as: 'child'),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'agg-iso-parent',
            'price' => 10.0,
            '_children' => [
                ['product_id' => '$child.id$', 'qty' => 2],
            ],
        ], as: 'aggregate'),
        DataFixture(
            ProductConditionFixture::class,
            ['attribute' => 'sku', 'operator' => '==', 'value' => 'agg-iso-child'],
            'cond'
        ),
        DataFixture(
            RuleFixture::class,
            ['simple_action' => Rule::TO_PERCENT_ACTION, 'discount_amount' => 50, 'actions' => ['$cond$']],
            'rule'
        ),
        DataFixture(GuestCartFixture::class, as: 'cart'),
        DataFixture(AddProductToCartFixture::class, ['cart_id' => '$cart.id$', 'product_id' => '$aggregate.id$', 'qty' => 1]),
    ]
    public function testChildSkuRuleDoesNotDiscountParent(): void
    {
        $quote = $this->collectTotals();
        $parentItem = $this->getAggregateParentItem($quote);

        $this->assertSame(0.0, (float)$parentItem->getDiscountAmount(), 'child-SKU rule must not discount the parent');
        $this->assertSame(0.0, (float)$quote->getShippingAddress()->getDiscountAmount(), 'no cart discount expected');
        $this->assertEmpty(
            array_filter(explode(',', (string)$parentItem->getAppliedRuleIds())),
            'no rule should be applied to the parent line'
        );
    }

    #[
        DbIsolation(true),
        DataFixture(ProductFixture::class, ['sku' => 'agg-pos-child', 'price' => 5.0], as: 'child'),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'agg-pos-parent',
            'price' => 10.0,
            '_children' => [
                ['product_id' => '$child.id$', 'qty' => 2],
            ],
        ], as: 'aggregate'),
        DataFixture(
            ProductConditionFixture::class,
            ['attribute' => 'sku', 'operator' => '==', 'value' => 'agg-pos-parent'],
            'cond'
        ),
        DataFixture(
            RuleFixture::class,
            ['simple_action' => Rule::TO_PERCENT_ACTION, 'discount_amount' => 50, 'actions' => ['$cond$']],
            'rule'
        ),
        DataFixture(GuestCartFixture::class, as: 'cart'),
        DataFixture(AddProductToCartFixture::class, ['cart_id' => '$cart.id$', 'product_id' => '$aggregate.id$', 'qty' => 1]),
    ]
    public function testParentSkuRuleDiscountsParent(): void
    {
        $quote = $this->collectTotals();
        $parentItem = $this->getAggregateParentItem($quote);

        $this->assertSame(5.0, (float)$parentItem->getDiscountAmount(), '50% of the $10 parent should be discounted');
        $this->assertContains(
            (int)$this->fixtures->get('rule')->getId(),
            array_map('intval', array_filter(explode(',', (string)$parentItem->getAppliedRuleIds()))),
            'the parent-SKU rule should be applied to the parent line'
        );
    }

    private function collectTotals(): Quote
    {
        /** @var Quote $quote */
        $quote = $this->cartRepository->get((int)$this->fixtures->get('cart')->getId());
        $quote->setIsActive(true);
        $quote->collectTotals();

        return $quote;
    }

    private function getAggregateParentItem(Quote $quote): QuoteItem
    {
        foreach ($quote->getAllItems() as $item) {
            if ($item->getProductType() === Aggregate::TYPE_CODE && !$item->getParentItemId()) {
                return $item;
            }
        }
        $this->fail('Aggregate parent quote item not found');
    }
}
