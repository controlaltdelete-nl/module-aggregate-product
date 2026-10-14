import { expect, type Locator, type Page } from '@playwright/test';
import { openAdminDashboard } from './AdminDashboard';

/**
 * The admin order view and the invoice and credit memo pages reached from it, opened through
 * Sales > Orders and the grid's keyword search, the way a merchant finds an order.
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

    async invoiceEverything(): Promise<void> {
        await this.page.locator('#order_invoice').click();
        await this.page.waitForLoadState('networkidle');
        await this.page.locator('.order-totals-actions button.submit-button').click();
        await expect(this.successMessage()).toContainText('The invoice has been created.', { timeout: 60_000 });
    }

    /**
     * Opens "Credit Memo" and refunds everything offline with the quantities the form proposes,
     * which is the only refund a check/money order payment offers.
     */
    async refundEverythingOffline(): Promise<void> {
        await this.page.locator('#order_creditmemo').click();
        await this.page.waitForLoadState('networkidle');
        await this.page.getByRole('button', { name: 'Refund Offline' }).click();
        await expect(this.successMessage()).toContainText('You created the credit memo.', { timeout: 60_000 });
    }

    private successMessage(): Locator {
        return this.page.locator('#messages .message-success, .page.messages .message-success').first();
    }
}
