import { expect, test } from '@playwright/test';
import { ADMIN_SESSION_PATH } from '../global-setup';
import { AdminOrderView } from '../support/AdminOrderView';
import { CatalogFixture, type ChildProduct } from '../support/CatalogFixture';
import { LumaStorefront } from '../support/LumaStorefront';

const CHILD_PRICE = 5;
const CHILD_STOCK = 100;
const AGGREGATE_PRICE = 25;
const ORDERED_QTY = 2;

/**
 * A merchant refunds an aggregate with more than one child through the admin credit memo form.
 *
 * The admin's credit memo save adds up the quantities of an order item's children and writes the
 * sum onto the parent, which only works when a parent has a single child. Two packs of an
 * aggregate with two children must refund as two packs, not four.
 */
test.describe('Refunding an aggregate product', () => {
    const catalog = new CatalogFixture();
    const aggregateName = `E2E Refund Aggregate ${catalog.prefix}`;
    const aggregateSku = catalog.sku('refund-pack');
    const children: ChildProduct[] = [
        { sku: catalog.sku('refund-child-a'), name: `E2E Refund Child A ${catalog.prefix}`, linkQty: 1 },
        { sku: catalog.sku('refund-child-b'), name: `E2E Refund Child B ${catalog.prefix}`, linkQty: 2 },
    ];

    test.beforeAll(() => {
        children.forEach((child) => catalog.createSimpleProduct(child, CHILD_PRICE, CHILD_STOCK));
        catalog.createAggregateProduct(aggregateSku, aggregateName, AGGREGATE_PRICE, children);
        catalog.processScheduledIndexes();
    });

    test.afterAll(() => {
        catalog.removeAll();
    });

    test('the merchant refunds an invoiced order for an aggregate with two children', async ({ browser }) => {
        const admin = await browser.newContext({ storageState: ADMIN_SESSION_PATH });
        const shopper = await browser.newContext();
        const storefront = new LumaStorefront(await shopper.newPage());

        const incrementId = await test.step('a guest buys two packs', async () => {
            await storefront.openProduct(catalog.productUrl(aggregateSku), aggregateName);
            await storefront.addToCart(ORDERED_QTY);
            await storefront.openCart();
            await storefront.proceedToCheckout();
            await storefront.fillShippingAddress({
                email: `${catalog.prefix}@example.com`,
                firstname: 'Erin',
                lastname: 'Example',
                street: '1 Test Street',
                city: 'Austin',
                postcode: '78701',
                telephone: '5125550100',
                country: 'US',
                region: 'Texas',
            });
            await storefront.chooseShippingMethod('Flat Rate');

            return storefront.placeOrderWithCheckMoneyOrder();
        });

        const orderView = new AdminOrderView(await admin.newPage());

        await test.step('the merchant invoices the order', async () => {
            await orderView.open(incrementId);
            await orderView.invoiceEverything();
        });

        await test.step('the merchant refunds both packs offline', async () => {
            await orderView.refundEverythingOffline();
        });

        await test.step('the order shows both packs as refunded', async () => {
            await orderView.open(incrementId);
            await expect(orderView.productRow(aggregateSku).locator('.col-ordered-qty')).toContainText(`Refunded ${ORDERED_QTY}`);
        });

        await admin.close();
        await shopper.close();
    });
});
