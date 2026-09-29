<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Test\Integration\Quote;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Checkout\Test\Fixture\PlaceOrder as PlaceOrderFixture;
use Magento\Checkout\Test\Fixture\SetBillingAddress as SetBillingAddressFixture;
use Magento\Checkout\Test\Fixture\SetDeliveryMethod as SetDeliveryMethodFixture;
use Magento\Checkout\Test\Fixture\SetGuestEmail as SetGuestEmailFixture;
use Magento\Checkout\Test\Fixture\SetPaymentMethod as SetPaymentMethodFixture;
use Magento\Checkout\Test\Fixture\SetShippingAddress as SetShippingAddressFixture;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Quote\Test\Fixture\AddProductToCart as AddProductToCartFixture;
use Magento\Quote\Test\Fixture\GuestCart as GuestCartFixture;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Model\Order;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorage;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Nfourteen\AggregateProduct\Model\Product\Type\Aggregate;
use Nfourteen\AggregateProduct\Test\Fixture\AggregateProduct as AggregateProductFixture;
use PHPUnit\Framework\TestCase;

/**
 * The aggregate parent is the only shippable line — core skips child rows when it sums address
 * weight — so the parent must carry the whole derived weight through the quote into the order.
 * A parent weighing nothing zeroed the shipment weight and starved weight-based carriers.
 */
class AggregateWeightTest extends TestCase
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
        Config('carriers/flatrate/active', 1, 'store', 'default'),
        DataFixture(ProductFixture::class, ['sku' => 'agg-weight-cart-child', 'weight' => 0.1], as: 'child'),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'agg-weight-cart-parent',
            '_children' => [
                ['product_id' => '$child.id$', 'qty' => 10],
            ],
        ], as: 'aggregate'),
        DataFixture(GuestCartFixture::class, as: 'cart'),
        DataFixture(
            AddProductToCartFixture::class,
            ['cart_id' => '$cart.id$', 'product_id' => '$aggregate.id$', 'qty' => 2]
        ),
        DataFixture(SetBillingAddressFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetShippingAddressFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetGuestEmailFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(
            SetDeliveryMethodFixture::class,
            ['cart_id' => '$cart.id$', 'carrier_code' => 'flatrate', 'method_code' => 'flatrate']
        ),
    ]
    public function testQuoteCarriesDerivedWeightOnTheParentLine(): void
    {
        /** @var Quote $quote */
        $quote = $this->cartRepository->get((int)$this->fixtures->get('cart')->getId());
        $quote->setIsActive(true);
        $quote->collectTotals();

        $parentItem = $this->getAggregateParentItem($quote);

        $this->assertEqualsWithDelta(1.0, (float)$parentItem->getWeight(), 0.0001, '10 cards at 0.1 each');
        $this->assertEqualsWithDelta(2.0, (float)$parentItem->getRowWeight(), 0.0001, 'unit weight times a qty of 2');
        $this->assertEqualsWithDelta(
            2.0,
            (float)$quote->getShippingAddress()->getWeight(),
            0.0001,
            'address weight comes from the parent line alone'
        );

        foreach ($quote->getAllItems() as $item) {
            if ($item->getParentItemId()) {
                $this->assertSame(
                    0.0,
                    (float)$item->getRowWeight(),
                    'child rows ship with the parent and must not double-count'
                );
            }
        }
    }

    #[
        DbIsolation(true),
        Config('carriers/flatrate/active', 1, 'store', 'default'),
        Config('payment/checkmo/active', 1, 'store', 'default'),
        DataFixture(ProductFixture::class, ['sku' => 'agg-weight-order-child', 'weight' => 0.1], as: 'child'),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'agg-weight-order-parent',
            '_children' => [
                ['product_id' => '$child.id$', 'qty' => 10],
            ],
        ], as: 'aggregate'),
        DataFixture(GuestCartFixture::class, as: 'cart'),
        DataFixture(
            AddProductToCartFixture::class,
            ['cart_id' => '$cart.id$', 'product_id' => '$aggregate.id$', 'qty' => 2]
        ),
        DataFixture(SetBillingAddressFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetShippingAddressFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetGuestEmailFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(
            SetDeliveryMethodFixture::class,
            ['cart_id' => '$cart.id$', 'carrier_code' => 'flatrate', 'method_code' => 'flatrate']
        ),
        DataFixture(SetPaymentMethodFixture::class, ['cart_id' => '$cart.id$', 'method' => 'checkmo']),
        DataFixture(PlaceOrderFixture::class, ['cart_id' => '$cart.id$'], as: 'order'),
    ]
    public function testPlacedOrderCarriesDerivedWeight(): void
    {
        /** @var Order $order */
        $order = $this->fixtures->get('order');

        $this->assertEqualsWithDelta(2.0, (float)$order->getWeight(), 0.0001, 'order weight is the address weight');

        $parentItem = $this->getAggregateParentOrderItem($order);
        $this->assertEqualsWithDelta(1.0, (float)$parentItem->getWeight(), 0.0001);
        $this->assertEqualsWithDelta(2.0, (float)$parentItem->getRowWeight(), 0.0001);
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

    private function getAggregateParentOrderItem(Order $order): OrderItemInterface
    {
        foreach ($order->getAllItems() as $item) {
            if ($item->getProductType() === Aggregate::TYPE_CODE && !$item->getParentItemId()) {
                return $item;
            }
        }
        $this->fail('Aggregate parent order item not found');
    }
}
