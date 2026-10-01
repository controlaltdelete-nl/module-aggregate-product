import { expect, test } from '@playwright/test';
import { ADMIN_SESSION_PATH } from '../global-setup';
import { AdminAggregateProductForm } from '../support/AdminAggregateProductForm';
import { AdminOrderView } from '../support/AdminOrderView';
import { CatalogFixture, type ChildProduct } from '../support/CatalogFixture';
import { LumaStorefront } from '../support/LumaStorefront';

const CHILD_PRICE = 5;
const CHILD_STOCK = 100;
const AGGREGATE_PRICE = 25;
const ORDERED_QTY = 2;

/**
 * The whole life of an aggregate product, through the same screens a merchant and a shopper use:
 * the merchant builds a pack in the admin, a guest buys two of them on the storefront, and the
 * merchant opens the resulting order.
 *
 * The children are created through Magento, because they are ordinary simple products and not
 * what is under test.
 */
test.describe('Ordering an aggregate product', () => {
    const catalog = new CatalogFixture();
    const aggregateName = `E2E Aggregate ${catalog.prefix}`;
    const aggregateSku = catalog.sku('pack');
    const children: ChildProduct[] = [
        { sku: catalog.sku('child-a'), name: `E2E Child A ${catalog.prefix}`, linkQty: 2 },
        { sku: catalog.sku('child-b'), name: `E2E Child B ${catalog.prefix}`, linkQty: 3 },
    ];

    test.beforeAll(() => {
        children.forEach((child) => catalog.createSimpleProduct(child, CHILD_PRICE, CHILD_STOCK));
    });

    test.afterAll(() => {
        catalog.removeAll();
    });

    test('a guest orders an aggregate the merchant created and the merchant sees what it contains', async ({ browser }) => {
        const admin = await browser.newContext({ storageState: ADMIN_SESSION_PATH });
        const shopper = await browser.newContext();

        await test.step('the merchant creates the aggregate in the admin', async () => {
            const form = new AdminAggregateProductForm(await admin.newPage());
            await form.openNew();
            await form.fillGeneral(aggregateName, aggregateSku, AGGREGATE_PRICE);
            await form.addChildren(children);
            await form.save();

            await expect(form.childRow(children[0].sku).locator('td[data-index="qty"] input')).toHaveValue(/^2(\.0+)?$/);
            await expect(form.childRow(children[1].sku).locator('td[data-index="qty"] input')).toHaveValue(/^3(\.0+)?$/);
        });

        catalog.processScheduledIndexes();

        const storefront = new LumaStorefront(await shopper.newPage());

        await test.step('the shopper adds two packs to the cart', async () => {
            await storefront.openProduct(catalog.productUrl(aggregateSku), aggregateName);
            await storefront.addToCart(ORDERED_QTY);
            await storefront.openCart();
        });

        const incrementId = await test.step('the shopper checks out as a guest', async () => {
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

        await test.step('the merchant sees the aggregate and what it includes on the order', async () => {
            const orderView = new AdminOrderView(await admin.newPage());
            await orderView.open(incrementId);

            const aggregateRow = orderView.productRow(aggregateSku);
            await expect(aggregateRow).toContainText(aggregateName);
            await expect(aggregateRow.locator('.col-ordered-qty')).toContainText(`Ordered ${ORDERED_QTY}`);

            const items = orderView.itemsOrdered();
            await expect(items.locator('tr.options-label')).toContainText('Includes');
            await expect(items.locator('.option-value', { hasText: children[0].name })).toHaveText(`2 x ${children[0].name}`);
            await expect(items.locator('.option-value', { hasText: children[1].name })).toHaveText(`3 x ${children[1].name}`);
        });

        await test.step('the order holds stock for every child at its pack quantity', async () => {
            expect(catalog.salableQty(children[0].sku)).toBe(CHILD_STOCK - ORDERED_QTY * children[0].linkQty);
            expect(catalog.salableQty(children[1].sku)).toBe(CHILD_STOCK - ORDERED_QTY * children[1].linkQty);
        });

        await admin.close();
        await shopper.close();
    });
});
