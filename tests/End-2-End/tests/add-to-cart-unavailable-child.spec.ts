import { expect, test } from '@playwright/test';
import { CatalogFixture, type ChildProduct } from '../support/CatalogFixture';
import { LumaStorefront } from '../support/LumaStorefront';

const HTTP_SERVER_ERROR = 500;
const INVENTORY_MODULE = 'Nfourteen_InventoryAggregateProduct';

/**
 * A pack can only go into the cart when every child in it can. When one child cannot, the shopper
 * should be told so and the cart should stay as it was.
 *
 * The child here is one the merchant turned into a configurable product ("Create Configurations")
 * after it was already in the pack. The pack only checks child types when the link is saved, so
 * it keeps the child, and a configurable cannot be added without choosing a variant.
 *
 * With indexers on "Update by Schedule", the recommended production setting, the pack keeps the
 * stock status it had until cron reindexes it. That window is what this spec reproduces: the pack
 * is indexed while it is fine, and the child changes after.
 *
 * What the shopper sees in that window depends on what is installed. The inventory module checks
 * the children live, so the pack is not offered for sale at all. Without it, the page still offers
 * Add to Cart from the stale index, and adding has to fail cleanly instead.
 */
test.describe('An aggregate whose child cannot be added', () => {
    const catalog = new CatalogFixture();
    const aggregateName = `E2E Aggregate ${catalog.prefix}`;
    const aggregateSku = catalog.sku('pack');
    const children: ChildProduct[] = [
        { sku: catalog.sku('child-a'), name: `E2E Child A ${catalog.prefix}`, linkQty: 2 },
        { sku: catalog.sku('child-b'), name: `E2E Child B ${catalog.prefix}`, linkQty: 3 },
    ];

    test.beforeAll(() => {
        if (!catalog.isStockIndexScheduled()) {
            throw new Error(
                'The stock indexer must be on "Update by Schedule" for this spec: on "Update on Save" the pack is '
                + 'reindexed the moment its child changes, so the window it reproduces does not exist. Run '
                + '`bin/magento indexer:set-mode schedule` on the target store.'
            );
        }

        children.forEach((child) => catalog.createSimpleProduct(child, 5, 100));
        catalog.createAggregateProduct(aggregateSku, aggregateName, 25, children);
        catalog.processScheduledIndexes();
        catalog.convertToConfigurable(children[1].sku, catalog.sku('child-b-one-size'), `E2E Child B One Size ${catalog.prefix}`, 100);
    });

    test.afterAll(() => {
        catalog.removeAll();
    });

    test('the product page does not offer the pack for sale', async ({ page }) => {
        test.skip(!catalog.isModuleEnabled(INVENTORY_MODULE), `only applies with ${INVENTORY_MODULE}`);
        const storefront = new LumaStorefront(page);

        await storefront.openProduct(catalog.productUrl(aggregateSku), aggregateName);

        await expect(storefront.addToCartButton()).toHaveCount(0);
    });

    test('adding the pack tells the shopper it cannot be added and the cart stays empty', async ({ page }) => {
        test.skip(catalog.isModuleEnabled(INVENTORY_MODULE), `only applies without ${INVENTORY_MODULE}`);
        const storefront = new LumaStorefront(page);
        await storefront.openProduct(catalog.productUrl(aggregateSku), aggregateName);

        const response = await storefront.submitAddToCart(1);

        expect(response.status()).toBeLessThan(HTTP_SERVER_ERROR);
        await expect(storefront.pageMessages()).toContainText('The item cannot be added to the shopping cart.', { timeout: 30_000 });
        await storefront.openCartPage(catalog.cartUrl());
        await expect(storefront.cartIsEmpty()).toBeVisible();
    });
});
