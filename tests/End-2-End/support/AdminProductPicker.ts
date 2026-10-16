import { expect, type Locator, type Page } from '@playwright/test';

export interface PriceRange {
    from: number;
    to: number;
}

/**
 * The "Add Products to Aggregate" modal on the aggregate product form. It is a paged grid, so a
 * product further down the catalog is found through the keyword search or the filters, the way a
 * merchant finds it, before it can be ticked.
 */
export class AdminProductPicker {
    constructor(private readonly page: Page) {}

    async open(): Promise<void> {
        await this.page.getByRole('button', { name: 'Add Products to Aggregate' }).click();
        await expect(this.modal()).toBeVisible();
        await expect(this.pager()).toBeVisible();
    }

    async search(keyword: string): Promise<void> {
        const search = this.modal().getByRole('textbox', { name: 'Search by keyword' });
        await search.fill(keyword);
        await search.press('Enter');
    }

    async filterByPrice(range: PriceRange): Promise<void> {
        await this.modal().getByRole('button', { name: 'Filters' }).click();
        await this.filtersPanel().locator('input[name="price[from]"]').fill(range.from.toString());
        await this.filtersPanel().locator('input[name="price[to]"]').fill(range.to.toString());
        await this.modal().getByRole('button', { name: 'Apply Filters' }).click();
    }

    async clearFilters(): Promise<void> {
        await this.modal().getByRole('button', { name: 'Clear all' }).click();
    }

    async select(sku: string): Promise<void> {
        await this.row(sku).locator('input[type=checkbox]').check();
    }

    async addSelected(): Promise<void> {
        await this.modal().getByRole('button', { name: 'Add Selected Products' }).click();
        await expect(this.modal()).toBeHidden();
    }

    rows(): Locator {
        return this.modal().locator('tbody tr.data-row');
    }

    row(sku: string): Locator {
        return this.rows().filter({ hasText: sku });
    }

    recordsFound(): Locator {
        return this.modal().locator('.admin__control-support-text').first();
    }

    activeFilters(): Locator {
        return this.modal().locator('.admin__current-filters-list li');
    }

    pager(): Locator {
        return this.modal().locator('.admin__data-grid-pager');
    }

    private filtersPanel(): Locator {
        return this.modal().locator('.admin__data-grid-filters');
    }

    private modal(): Locator {
        return this.page.locator('.product_form_product_form_aggregate_aggregate_products_modal');
    }
}
