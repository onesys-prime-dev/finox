import { test, expect } from '@playwright/test';
import { login, watch } from './helpers';

test.describe.configure({ mode: 'serial' });

test('finance: breakdown rows on a cheque, advice preview, print preview and A4 schedule', async ({ page }) => {
    const issues = watch(page);
    await login(page, 'finance');

    await page.goto('/cheques/create');
    await page.getByPlaceholder('Search payee or vendor…').fill('Metro Office');
    await page.getByText('Metro Office Supplies Inc.').first().click();
    await page.getByPlaceholder('0.00').first().fill('1000');

    // three manual rows
    const rows = [['OR-1001', 'Metro Office Supplies Inc.', 'Bond paper', '10,000.00'], ['INV-2201', 'Toner World Corp.', 'Toner cartridges', '25,500.50'], ['OR-1003', 'Metro Office Supplies Inc.', 'Folders', '3,000.00']];
    for (const [i, r] of rows.entries()) {
        await page.getByRole('button', { name: 'Add row' }).click();
        await page.getByLabel(`Row ${i + 1} OR or invoice no.`).fill(r[0]);
        await page.getByLabel(`Row ${i + 1} vendor`).fill(r[1]);
        await page.getByLabel(`Row ${i + 1} particulars`).fill(r[2]);
        await page.getByLabel(`Row ${i + 1} amount`).fill(r[3]);
    }
    // mismatch only warns
    await expect(page.getByText(/Breakdown total ₱38,500\.50/)).toBeVisible();
    await expect(page.getByText(/more than the cheque amount/)).toBeVisible();
    await page.getByRole('button', { name: 'Use total as cheque amount' }).click();
    await expect(page.getByText(/matches the cheque amount/)).toBeVisible();

    // advice preview shows the table
    await page.getByRole('button', { name: 'Advice', exact: true }).click();
    await expect(page.locator('body')).toContainText('Toner cartridges');
    await expect(page.locator('body')).toContainText('38,500.50');
    await page.screenshot({ path: 'test-results/breakdown-form.png', fullPage: true });

    // the receipts picker opens
    await page.getByRole('button', { name: 'Add from receipts' }).click();
    await expect(page.getByRole('dialog')).toContainText('Add from receipts');
    await page.getByRole('dialog').getByRole('button', { name: 'Cancel' }).click();

    await page.getByRole('button', { name: /Save and prepare/ }).click();
    await page.waitForURL(/\/cheques\/\d+$/);
    await expect(page.getByText('Breakdown', { exact: true }).first()).toBeVisible();
    await expect(page.locator('body')).toContainText('Toner World Corp.');
    const chequeUrl = page.url();

    // print center preview with the full sheet
    await page.getByRole('link', { name: /Print cheque/ }).click();
    await page.waitForURL(/printing/);
    await page.getByRole('button', { name: /Preview/ }).click();
    await page.getByRole('button', { name: 'Show full sheet' }).click();
    await expect(page.locator('body')).toContainText('Bond paper');
    await page.screenshot({ path: 'test-results/breakdown-print-preview.png', fullPage: true });

    // A4 schedule
    await page.goto(`${chequeUrl}/schedule`);
    await expect(page.locator('body')).toContainText('Breakdown schedule');
    await expect(page.locator('body')).toContainText('INV-2201');
    await page.screenshot({ path: 'test-results/breakdown-schedule.png', fullPage: true });

    expect(issues, issues.join('\n')).toEqual([]);
});
