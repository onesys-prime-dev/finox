import { test, expect } from '@playwright/test';
import { login, watch } from './helpers';

const today = new Date().toISOString().slice(0, 10);

test.describe.configure({ mode: 'serial' });

test('finance: cheque lifecycle through the screens', async ({ page }) => {
    const issues = watch(page);
    await login(page, 'finance');

    // create + prepare
    await page.goto('/cheques/create');
    await page.locator('select').first().selectOption({ index: 1 }).catch(() => {});
    await page.getByPlaceholder('Search payee or vendor…').fill('Metro Office');
    await page.getByText('Metro Office Supplies Inc.').first().click();
    await page.getByPlaceholder('0.00').fill('2,345.67');
    await expect(page.getByText('TWO THOUSAND THREE HUNDRED FORTY FIVE AND 67/100 PESOS ONLY').first()).toBeVisible();
    await page.getByRole('button', { name: /Save and prepare/ }).click();
    await page.waitForURL(/\/cheques\/\d+$/);
    await expect(page.getByText(/Prepared/i).first()).toBeVisible();
    const chequeUrl = page.url();

    // print center: preview, start, confirm
    await page.getByRole('link', { name: /Print cheque/ }).click();
    await page.waitForURL(/printing/);
    await page.getByRole('button', { name: /Preview/ }).click();
    await expect(page.locator('body')).toContainText(/TWO THOUSAND THREE HUNDRED/i);
    await page.screenshot({ path: 'test-results/print-preview.png', fullPage: true });
    await page.getByRole('button', { name: /^(Print|Start print|Print now)/ }).first().click();
    // headless Chromium shows no print dialog, so send the signal a real browser sends when the dialog closes
    await page.waitForTimeout(600);
    await page.evaluate(() => window.dispatchEvent(new Event('afterprint')));
    const confirm = page.getByRole('dialog');
    await expect(confirm).toContainText(/what happened/i);
    await confirm.getByRole('button', { name: 'Yes, printed correctly' }).click();
    await expect(page.locator('body')).toContainText(/confirmed|recorded/i);

    // release
    await page.goto(chequeUrl);
    await expect(page.getByText(/Printed/i).first()).toBeVisible();
    await page.getByRole('button', { name: 'Record release' }).click();
    const dlg = page.getByRole('dialog');
    await dlg.getByLabel(/Released to/).fill('Juan Dela Cruz');
    await dlg.getByLabel(/Released by/).fill('Finance Clerk');
    await dlg.getByRole('button', { name: 'Record release' }).click();
    await expect(page.getByText(/Released/i).first()).toBeVisible();

    // for clearing -> cleared
    await page.getByRole('button', { name: 'Mark as for clearing' }).click();
    await page.getByRole('button', { name: 'Record clearing result' }).click();
    await page.getByRole('dialog').getByRole('button', { name: /Record as cleared/ }).click();
    await expect(page.getByText(/Cleared/i).first()).toBeVisible();
    await expect(page.getByText(/Recorded manually/i).first()).toBeVisible();

    expect(issues, issues.join('\n')).toEqual([]);
});

test('encoder: sees own cheque only, cannot prepare', async ({ page }) => {
    const issues = watch(page);
    await login(page, 'encoder');
    await page.goto('/cheques');
    await expect(page.locator('body')).not.toContainText('2,345.67');
    await page.goto('/cheques/1');
    await expect(page.getByRole('button', { name: /Prepare cheque/ })).toHaveCount(0);
    expect(issues, issues.join('\n')).toEqual([]);
});

test('admin: exports download as real files', async ({ page }) => {
    await login(page, 'admin');
    for (const [fmt, sig] of [['csv', null], ['xlsx', 'PK'], ['pdf', '%PDF']]) {
        const res = await page.request.get(`/reports/cheque-register/export?format=${fmt}`);
        expect(res.status(), fmt).toBe(200);
        if (sig) expect((await res.body()).subarray(0, sig.length).toString('latin1'), fmt).toBe(sig);
    }
});

test('admin: settings page saves', async ({ page }) => {
    const issues = watch(page);
    await login(page, 'admin');
    await page.goto('/admin/settings');
    await page.getByLabel('Keep records live for (years)').fill('12');
    await page.getByRole('button', { name: /Save settings/ }).click();
    await expect(page.getByText(/Settings saved/i)).toBeVisible();
    expect(issues, issues.join('\n')).toEqual([]);
});
