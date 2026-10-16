import { expect, test } from '@playwright/test';
import { ADMIN_SESSION_PATH } from '../global-setup';
import { AdminAggregateProductForm } from '../support/AdminAggregateProductForm';
import { CatalogFixture, type ChildProduct } from '../support/CatalogFixture';

const CHILD_STOCK = 100;
const CHEAP_PRICE = 5;
const EXPENSIVE_PRICE = 50;

test.use({ storageState: ADMIN_SESSION_PATH });

/**
 * The "Add Products to Aggregate" modal is a paged grid with a keyword search and filters, so a
 * merchant with a large catalog finds a child without the modal loading every product at once.
 */
test.describe('Picking the children of an aggregate product', () => {
    const catalog = new CatalogFixture();
    const cheapChild: ChildProduct = { sku: catalog.sku('cheap'), name: `E2E Cheap Child ${catalog.prefix}`, linkQty: 1 };
    const expensiveChild: ChildProduct = { sku: catalog.sku('expensive'), name: `E2E Expensive Child ${catalog.prefix}`, linkQty: 1 };

    test.beforeAll(() => {
        catalog.createSimpleProduct(cheapChild, CHEAP_PRICE, CHILD_STOCK);
        catalog.createSimpleProduct(expensiveChild, EXPENSIVE_PRICE, CHILD_STOCK);
    });

    test.afterAll(() => {
        catalog.removeAll();
    });

    test('the merchant finds children through the search and the filters and adds them to the pack', async ({ page }) => {
        const form = new AdminAggregateProductForm(page);
        await form.openNew();
        const picker = form.productPicker();

        await test.step('the picker shows one page of the catalog', async () => {
            await picker.open();

            await expect(picker.recordsFound()).toHaveText(/\d+ records found/);
            expect(await picker.rows().count()).toBeLessThanOrEqual(20);
        });

        await test.step('the keyword search shows every product that matches it', async () => {
            await picker.search(catalog.prefix);

            await expect(picker.recordsFound()).toHaveText(/\b2 records found/);
            await expect(picker.row(cheapChild.sku)).toBeVisible();
            await expect(picker.row(expensiveChild.sku)).toBeVisible();
        });

        await test.step('a filter narrows the search further', async () => {
            await picker.filterByPrice({ from: 40, to: 60 });

            await expect(picker.activeFilters()).toHaveCount(2);
            await expect(picker.rows()).toHaveCount(1);
            await picker.select(expensiveChild.sku);
        });

        await test.step('a new search keeps what was ticked before', async () => {
            await picker.clearFilters();
            await picker.search(cheapChild.sku);

            await expect(picker.rows()).toHaveCount(1);
            await expect(picker.row(cheapChild.sku)).toBeVisible();
            await picker.select(cheapChild.sku);
        });

        await test.step('every product ticked along the way is added to the pack', async () => {
            await picker.addSelected();

            await expect(form.childRow(cheapChild.sku)).toBeVisible();
            await expect(form.childRow(expensiveChild.sku)).toBeVisible();
        });
    });
});
