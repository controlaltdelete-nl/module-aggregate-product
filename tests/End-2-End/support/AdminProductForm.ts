import { expect, type Page } from '@playwright/test';
import { openAdminDashboard } from './AdminDashboard';

/**
 * The admin edit form of an existing product, opened through Catalog > Products and the grid's
 * keyword search, the way a merchant finds a product.
 */
export class AdminProductForm {
    constructor(private readonly page: Page) {}

    async open(sku: string): Promise<void> {
        await openAdminDashboard(this.page);
        await this.page.locator('#menu-magento-catalog-catalog > a').click();
        await this.page.locator('[data-ui-id="menu-magento-catalog-catalog-products"] a').click();
        await this.page.waitForLoadState('networkidle');

        const search = this.page.getByRole('textbox', { name: 'Search by keyword' }).filter({ visible: true });
        await search.fill(sku);
        await search.press('Enter');

        const row = this.page.locator('.data-grid tbody tr', { hasText: sku });
        await expect(row).toHaveCount(1, { timeout: 30_000 });
        await row.getByRole('link', { name: 'Edit' }).click();
        await this.page.waitForLoadState('networkidle');
        await expect(this.page.locator('.admin__field[data-index="sku"] input')).toHaveValue(sku);
    }

    async changeQuantity(qty: number): Promise<void> {
        await this.page.locator('.admin__field[data-index="qty"] input').fill(qty.toString());
    }

    async save(): Promise<void> {
        await this.page.locator('#save-button').click();
        await expect(this.page.locator('.message-success')).toContainText('You saved the product.', { timeout: 60_000 });
    }
}
