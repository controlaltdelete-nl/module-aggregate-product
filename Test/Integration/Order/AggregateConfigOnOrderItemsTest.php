<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProduct\Test\Integration\Order;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Checkout\Test\Fixture\PlaceOrder as PlaceOrderFixture;
use Magento\Checkout\Test\Fixture\SetBillingAddress as SetBillingAddressFixture;
use Magento\Checkout\Test\Fixture\SetDeliveryMethod as SetDeliveryMethodFixture;
use Magento\Checkout\Test\Fixture\SetGuestEmail as SetGuestEmailFixture;
use Magento\Checkout\Test\Fixture\SetPaymentMethod as SetPaymentMethodFixture;
use Magento\Checkout\Test\Fixture\SetShippingAddress as SetShippingAddressFixture;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Test\Fixture\AddProductToCart as AddProductToCartFixture;
use Magento\Quote\Test\Fixture\GuestCart as GuestCartFixture;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\TestFramework\Fixture\AppIsolation;
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
 * Quote -> order conversion must carry aggregate_config onto the persisted order items:
 * the parent's "Includes" option (Aggregate::getOrderOptions) and each child's JSON config
 * (SetQuoteItemOptionsOnOrderItem plugin). MSI shipment/invoice deduction reads the child
 * config downstream, so a silent loss here corrupts fulfillment.
 */
class AggregateConfigOnOrderItemsTest extends TestCase
{
    private ?OrderRepositoryInterface $orderRepository = null;
    private ?Json $serializer = null;
    private ?DataFixtureStorage $fixtures = null;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->orderRepository = $objectManager->get(OrderRepositoryInterface::class);
        $this->serializer = $objectManager->get(Json::class);
        $this->fixtures = DataFixtureStorageManager::getStorage();
    }

    #[
        DbIsolation(false),
        AppIsolation(true),
        Config('carriers/flatrate/active', 1, 'store', 'default'),
        Config('payment/checkmo/active', 1, 'store', 'default'),
        DataFixture(ProductFixture::class, [
            'sku' => 'agg-order-config-child-1',
            'name' => 'Agg Order Child One',
            'price' => 10.0,
        ], as: 'child1'),
        DataFixture(ProductFixture::class, [
            'sku' => 'agg-order-config-child-2',
            'name' => 'Agg Order Child Two',
            'price' => 7.5,
        ], as: 'child2'),
        DataFixture(AggregateProductFixture::class, ['sku' => 'agg-order-config-parent', 'price' => 50.0, '_children' => [
            ['product_id' => '$child1.id$', 'qty' => 2],
            ['product_id' => '$child2.id$', 'qty' => 3],
        ]], as: 'aggregate'),
        DataFixture(GuestCartFixture::class, as: 'cart'),
        DataFixture(AddProductToCartFixture::class, ['cart_id' => '$cart.id$', 'product_id' => '$aggregate.id$', 'qty' => 1]),
        DataFixture(SetBillingAddressFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetShippingAddressFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetGuestEmailFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetDeliveryMethodFixture::class, ['cart_id' => '$cart.id$', 'carrier_code' => 'flatrate', 'method_code' => 'flatrate']),
        DataFixture(SetPaymentMethodFixture::class, ['cart_id' => '$cart.id$', 'method' => 'checkmo']),
        DataFixture(PlaceOrderFixture::class, ['cart_id' => '$cart.id$'], as: 'order'),
    ]
    public function testOrderItemsCarryAggregateConfig(): void
    {
        // Reload from the repository so the assertions cover persisted state, not in-memory leftovers
        $order = $this->orderRepository->get((int)$this->fixtures->get('order')->getId());

        $parentItem = null;
        foreach ($order->getItems() as $item) {
            if ($item->getProductType() === Aggregate::TYPE_CODE) {
                $parentItem = $item;
                break;
            }
        }
        $this->assertNotNull($parentItem, 'Order should contain the aggregate parent item');
        $this->assertFalse($parentItem->isDummy(), 'Aggregate parent ships together with its children');

        $childItems = array_values(array_filter(
            $order->getItems(),
            static fn (OrderItemInterface $item): bool =>
                (int)$item->getParentItemId() === (int)$parentItem->getItemId()
        ));
        $this->assertCount(2, $childItems, 'Both configured children must convert to child order items');

        $this->assertParentIncludesOption($parentItem);
        $this->assertChildJsonConfigs($childItems);
    }

    private function assertParentIncludesOption(OrderItemInterface $parentItem): void
    {
        $productOptions = $parentItem->getProductOptions();
        $this->assertArrayHasKey(
            'aggregate_config',
            $productOptions,
            'Parent order item must carry the aggregate_config product option'
        );

        $aggregateConfig = $productOptions['aggregate_config'];
        $this->assertIsArray($aggregateConfig);
        $this->assertCount(1, $aggregateConfig, 'Parent aggregate_config holds a single Includes option');

        $option = $aggregateConfig[0];
        $this->assertSame(0, (int)$option['option_id']);
        $this->assertSame((string)__('Includes'), $option['label']);

        $qtyByName = [];
        foreach ($option['value'] as $entry) {
            $qtyByName[$entry['name']] = (float)$entry['qty'];
        }
        ksort($qtyByName);
        $this->assertSame(
            [
                'Agg Order Child One' => 2.0,
                'Agg Order Child Two' => 3.0,
            ],
            $qtyByName,
            'Includes option must list every child with its configured qty'
        );
    }

    /**
     * @param OrderItemInterface[] $childItems
     */
    private function assertChildJsonConfigs(array $childItems): void
    {
        $qtyBySku = [];
        foreach ($childItems as $childItem) {
            $productOptions = $childItem->getProductOptions();
            $this->assertArrayHasKey(
                'aggregate_config',
                $productOptions,
                sprintf('Child order item %s must carry aggregate_config', $childItem->getSku())
            );
            $this->assertIsString(
                $productOptions['aggregate_config'],
                'Child aggregate_config is the JSON string copied from the quote item custom option'
            );

            $decoded = $this->serializer->unserialize($productOptions['aggregate_config']);
            $this->assertSame(
                $childItem->getName(),
                $decoded['name'],
                'Child aggregate_config name must match the ordered child'
            );
            $qtyBySku[$childItem->getSku()] = (float)$decoded['qty'];
        }
        ksort($qtyBySku);

        $this->assertSame(
            [
                'agg-order-config-child-1' => 2.0,
                'agg-order-config-child-2' => 3.0,
            ],
            $qtyBySku,
            'Each child order item must carry its configured link qty in aggregate_config'
        );
    }
}
