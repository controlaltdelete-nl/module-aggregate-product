<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Test\Integration\Quote;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Test\Fixture\Customer as CustomerFixture;
use Magento\Multishipping\Model\Checkout\Type\Multishipping;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Item as AddressItem;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Quote\Test\Fixture\AddProductToCart as AddProductToCartFixture;
use Magento\Quote\Test\Fixture\CustomerCart as CustomerCartFixture;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorage;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Nfourteen\AggregateProduct\Model\Product\Type\Aggregate;
use Nfourteen\AggregateProduct\Test\Fixture\AggregateProduct as AggregateProductFixture;
use PHPUnit\Framework\TestCase;

/**
 * Multishipping splits a quote item across shipping addresses. The aggregate's hidden child
 * dummy rows must follow the parent to each address (attached to that address's parent item),
 * never be detached, duplicated, or left behind. This drives the real production seam —
 * Multishipping::setShippingItemsInformation — with a logged-in customer, then reloads the quote
 * from the DB so the split is proven as persisted state, not an in-memory graph.
 */
class MultishippingSplitTest extends TestCase
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
        AppIsolation(true),
        AppArea('frontend'),
        DataFixture(ProductFixture::class, as: 'child1'),
        DataFixture(ProductFixture::class, as: 'child2'),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'agg-ms-parent',
            '_children' => [
                ['product_id' => '$child1.id$', 'qty' => 2],
                ['product_id' => '$child2.id$', 'qty' => 3],
            ],
        ], as: 'aggregate'),
        DataFixture(CustomerFixture::class, ['addresses' => [[], []]], as: 'customer'),
        DataFixture(CustomerCartFixture::class, ['customer_id' => '$customer.id$'], as: 'cart'),
        DataFixture(AddProductToCartFixture::class, ['cart_id' => '$cart.id$', 'product_id' => '$aggregate.id$', 'qty' => 2]),
    ]
    public function testChildRowsFollowParentToEachShippingAddress(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $customer = $this->fixtures->get('customer');

        /** @var Quote $quote */
        $quote = $this->cartRepository->get((int)$this->fixtures->get('cart')->getId());
        $parentItem = $this->getAggregateParentItem($quote);
        $this->assertTrue((bool)$parentItem->getHasChildren(), 'aggregate parent must report children to the split path');

        $addressIds = array_values(array_map(
            static fn ($address) => (int)$address->getId(),
            $customer->getAddresses()
        ));
        $this->assertCount(2, $addressIds, 'fixture customer must carry two addresses to split across');

        /** @var CustomerSession $customerSession */
        $customerSession = $objectManager->get(CustomerSession::class);
        $customerSession->loginById((int)$customer->getId());
        /** @var CheckoutSession $checkoutSession */
        $checkoutSession = $objectManager->get(CheckoutSession::class);
        $checkoutSession->replaceQuote($quote);

        $quote->setIsMultiShipping(1);

        /** @var Multishipping $multishipping */
        $multishipping = $objectManager->create(Multishipping::class);
        $parentItemId = (int)$parentItem->getId();
        $multishipping->setShippingItemsInformation([
            [$parentItemId => ['qty' => 1, 'address' => $addressIds[0]]],
            [$parentItemId => ['qty' => 1, 'address' => $addressIds[1]]],
        ]);

        // Assert against the persisted quote, not the in-memory graph Multishipping mutated.
        /** @var Quote $reloaded */
        $reloaded = $this->cartRepository->get((int)$quote->getId());
        $this->assertTrue((bool)$reloaded->getIsMultiShipping(), 'multishipping flag must persist');

        $shippingAddresses = $reloaded->getAllShippingAddresses();
        $this->assertCount(2, $shippingAddresses, 'one persisted shipping address per customer address');

        $seenCustomerAddressIds = [];
        foreach ($shippingAddresses as $address) {
            $label = (string)$address->getCustomerAddressId();
            $seenCustomerAddressIds[] = (int)$address->getCustomerAddressId();

            $items = $address->getAllItems();
            $parents = array_values(array_filter($items, static fn (AddressItem $i) => !$i->getParentItemId()));
            $children = array_values(array_filter($items, static fn (AddressItem $i) => (bool)$i->getParentItemId()));

            $this->assertCount(1, $parents, "address {$label} has exactly one parent line");
            $this->assertSame('agg-ms-parent', $parents[0]->getSku());
            $this->assertSame(Aggregate::TYPE_CODE, $parents[0]->getProduct()->getTypeId());
            $this->assertEquals(1.0, (float)$parents[0]->getQty(), "address {$label} receives one unit of the qty-2 split");
            $this->assertCount(2, $children, "address {$label} carries both child dummy rows, no duplication or loss");

            foreach ($children as $child) {
                $this->assertSame(
                    (int)$parents[0]->getId(),
                    (int)$child->getParentItemId(),
                    "address {$label} child stays attached to that address's parent, not detached or cross-linked"
                );
            }
        }

        $this->assertEqualsCanonicalizing(
            $addressIds,
            $seenCustomerAddressIds,
            'each customer address must receive its own persisted shipping address'
        );
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
