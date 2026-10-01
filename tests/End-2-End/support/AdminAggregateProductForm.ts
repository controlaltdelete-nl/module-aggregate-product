import { expect, type Locator, type Page } from '@playwright/test';
import { openAdminDashboard } from './AdminDashboard';
import type { ChildProduct } from './CatalogFixture';

/**
 * The admin product form for a new aggregate, reached the way a merchant reaches it: Catalog >
 * Products > Add Product > Aggregate Product. Admin URLs carry a per-route secret key, so a
 * hand-built URL lands on the dashboard instead.
 */
export class AdminAggregateProductForm {
    /**
     * The product picker loads every product in the catalog at once (it has no paging, search or
     * filters), which takes a while on a catalog with sample data.
     */
    private static readonly PICKER_TIMEOUT = 90_000;

    constructor(private readonly page: Page) {}

    async openNew(): Promise<void> {
        await openAdminDashboard(this.page);
        await this.page.locator('#menu-magento-catalog-catalog > a').click();
        await this.page.locator('[data-ui-id="menu-magento-catalog-catalog-products"] a').click();
        await this.page.waitForLoadState('networkidle');
        await this.page.locator('#add_new_product .action-toggle').click();
        await this.page.locator('#add_new_product .dropdown-menu').getByText('Aggregate Product', { exact: true }).click();
        await this.page.waitForLoadState('networkidle');
        await expect(this.aggregateSection()).toBeVisible();
    }

    /**
     * The form copies the product name into the SKU until the SKU itself has been changed by the
     * user, which it only registers on blur. So the SKU goes first and is blurred, and only then
     * the name, or the two end up merged.
     */
    async fillGeneral(name: string, sku: string, price: number): Promise<void> {
        await this.field('sku').fill(sku);
        await this.field('sku').blur();
        await this.field('name').fill(name);
        await this.field('name').blur();
        await expect(this.field('sku')).toHaveValue(sku);
        await this.field('price').fill(price.toString());
    }

    async addChildren(children: ChildProduct[]): Promise<void> {
        await this.page.getByRole('button', { name: 'Add Products to Aggregate' }).click();
        const picker = this.page.locator('.product_form_product_form_aggregate_aggregate_products_modal');

        for (const child of children) {
            await picker
                .locator('tbody tr', { hasText: child.sku })
                .locator('input[type=checkbox]')
                .check({ timeout: AdminAggregateProductForm.PICKER_TIMEOUT });
        }

        await picker.getByRole('button', { name: 'Add Selected Products' }).click();
        await expect(picker).toBeHidden();

        for (const child of children) {
            await this.childRow(child.sku).locator('td[data-index="qty"] input').fill(child.linkQty.toString());
        }
    }

    async save(): Promise<void> {
        await this.page.locator('#save-button').click();
        await expect(this.page.locator('.message-success')).toContainText('You saved the product.', { timeout: 60_000 });
    }

    childRow(sku: string): Locator {
        return this.aggregateSection().locator('tbody tr', { hasText: sku });
    }

    private aggregateSection(): Locator {
        return this.page.locator('[data-index="aggregate"]');
    }

    private field(index: string): Locator {
        return this.page.locator(`.admin__field[data-index="${index}"] input`);
    }
}
