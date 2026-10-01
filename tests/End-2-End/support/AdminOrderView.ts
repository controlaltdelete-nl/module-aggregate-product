import { expect, type Locator, type Page } from '@playwright/test';
import { openAdminDashboard } from './AdminDashboard';

/**
 * The admin order view, opened through Sales > Orders and the grid's keyword search, the way a
 * merchant finds an order.
 */
export class AdminOrderView {
    constructor(private readonly page: Page) {}

    async open(incrementId: string): Promise<void> {
        await openAdminDashboard(this.page);
        await this.page.locator('#menu-magento-sales-sales > a').click();
        await this.page.locator('[data-ui-id="menu-magento-sales-sales-order"] a').click();
        await this.page.waitForLoadState('networkidle');

        const search = this.page.getByRole('textbox', { name: 'Search by keyword' }).filter({ visible: true });
        await search.fill(incrementId);
        await search.press('Enter');

        const row = this.page.locator('.data-grid tbody tr', { hasText: incrementId });
        await expect(row).toHaveCount(1, { timeout: 30_000 });
        await row.getByRole('link', { name: 'View' }).click();
        await this.page.waitForLoadState('networkidle');
        await expect(this.page.locator('h1.page-title')).toContainText(incrementId);
    }

    itemsOrdered(): Locator {
        return this.page.locator('.edit-order-table');
    }

    /**
     * The row of the ordered product itself, as opposed to the "Includes" rows under it.
     */
    productRow(sku: string): Locator {
        return this.itemsOrdered().locator('tbody tr', { has: this.page.locator('.product-sku-block', { hasText: sku }) });
    }
}
