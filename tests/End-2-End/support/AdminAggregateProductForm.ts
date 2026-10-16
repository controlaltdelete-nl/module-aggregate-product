import { expect, type Locator, type Page } from '@playwright/test';
import { openAdminDashboard } from './AdminDashboard';
import { AdminProductPicker } from './AdminProductPicker';
import type { ChildProduct } from './CatalogFixture';

/**
 * The admin product form for a new aggregate, reached the way a merchant reaches it: Catalog >
 * Products > Add Product > Aggregate Product. Admin URLs carry a per-route secret key, so a
 * hand-built URL lands on the dashboard instead.
 */
export class AdminAggregateProductForm {
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
        const picker = this.productPicker();
        await picker.open();

        for (const child of children) {
            await picker.search(child.sku);
            await expect(picker.rows()).toHaveCount(1);
            await picker.select(child.sku);
        }

        await picker.addSelected();

        for (const child of children) {
            await this.childRow(child.sku).locator('td[data-index="qty"] input').fill(child.linkQty.toString());
        }
    }

    async save(): Promise<void> {
        await this.page.locator('#save-button').click();
        await expect(this.page.locator('.message-success')).toContainText('You saved the product.', { timeout: 60_000 });
    }

    productPicker(): AdminProductPicker {
        return new AdminProductPicker(this.page);
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
