import { expect, test } from '@playwright/test';
import { ADMIN_SESSION_PATH } from '../global-setup';
import { AdminProductForm } from '../support/AdminProductForm';
import { CatalogFixture, type ChildProduct } from '../support/CatalogFixture';
import { LumaStorefront } from '../support/LumaStorefront';

const CHILD_PRICE = 5;
const CHILD_STOCK = 100;
const CHANGED_CHILD_STOCK = 50;
const AGGREGATE_PRICE = 25;

/**
 * A merchant changes the stock of one child of an aggregate in the admin, and the aggregate stays
 * on sale as long as every child still has enough stock for a pack.
 */
test.describe('Changing the stock of an aggregate child', () => {
    const catalog = new CatalogFixture();
    const aggregateName = `E2E Stock Aggregate ${catalog.prefix}`;
    const aggregateSku = catalog.sku('stock-pack');
    const children: ChildProduct[] = [
        { sku: catalog.sku('stock-child-a'), name: `E2E Stock Child A ${catalog.prefix}`, linkQty: 1 },
        { sku: catalog.sku('stock-child-b'), name: `E2E Stock Child B ${catalog.prefix}`, linkQty: 2 },
    ];

    test.beforeAll(() => {
        children.forEach((child) => catalog.createSimpleProduct(child, CHILD_PRICE, CHILD_STOCK));
        catalog.createAggregateProduct(aggregateSku, aggregateName, AGGREGATE_PRICE, children);
        catalog.processScheduledIndexes();
    });

    test.afterAll(() => {
        catalog.removeAll();
    });

    test('the aggregate stays on sale after the merchant lowers the stock of one child', async ({ browser }) => {
        const admin = await browser.newContext({ storageState: ADMIN_SESSION_PATH });
        const shopper = await browser.newContext();

        await test.step('the merchant lowers the stock of the first child', async () => {
            const form = new AdminProductForm(await admin.newPage());
            await form.open(children[0].sku);
            await form.changeQuantity(CHANGED_CHILD_STOCK);
            await form.save();
        });

        catalog.processScheduledIndexes();

        await test.step('a shopper can still buy the aggregate', async () => {
            const storefront = new LumaStorefront(await shopper.newPage());
            await storefront.openProduct(catalog.productUrl(aggregateSku), aggregateName);
            await expect(storefront.stockAvailability()).toHaveText(/^\s*In stock\s*$/);
            await storefront.addToCart(1);
        });

        await admin.close();
        await shopper.close();
    });
});
