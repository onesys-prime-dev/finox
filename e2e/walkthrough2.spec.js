import { test, expect } from '@playwright/test';
import { login, watch } from './helpers';
import fs from 'node:fs';

test.describe.configure({ mode: 'default' });
test.setTimeout(420_000);

let problems = [];
const stamp = Date.now().toString().slice(-6);
const state = {};

async function check(name, fn) {
    try { await fn(); } catch (e) { problems.push(`${name}: ${String(e.message).replace(/\x1b\[[0-9;]*m/g, '').split('\n').filter(Boolean).slice(0, 6).join(' | ')}`); }
}
function patchGoto(page) {
    const goto = page.goto.bind(page);
    page.goto = async (...a) => { const r = await goto(...a); await page.waitForLoadState('networkidle').catch(() => {}); return r; };
}
const toast = (page, re) => expect(page.locator('[role=status]')).toContainText(re, { timeout: 15000 });

test('FINANCE (2): breakdown from receipts, CASH cheque, payee line, list tools, saved filters', async ({ page }) => {
    const issues = watch(page); patchGoto(page);
    await login(page, 'finance');
    const pickPayee = async () => {
        await page.getByPlaceholder('Search payee or vendor…').fill('Metro Office');
        await page.getByRole('option', { name: 'Metro Office Supplies Inc.' }).click();
    };

    await check('breakdown: receipt picker adds the receipt and links it', async () => {
        await page.goto('/receipts/new');
        await page.getByLabel(/Receipt number/).fill(`BD-${stamp}`);
        await pickPayee();
        await page.getByPlaceholder('0.00').first().fill('1,200.00');
        await page.getByLabel(/Description/).fill('Paper reams');
        await page.getByRole('button', { name: 'Save receipt' }).click();
        await page.waitForURL(/\/receipts\/\d+$/);
        state.bdReceipt = page.url();

        await page.goto('/cheques/create');
        await pickPayee();
        await page.getByRole('button', { name: 'Add from receipts' }).click();
        const d = page.getByRole('dialog');
        await expect(d).toContainText(`BD-${stamp}`);
        await d.getByRole('checkbox', { name: `Select BD-${stamp}` }).check();
        await d.getByRole('button', { name: /^Add 1 row/ }).click();
        await expect(page.getByLabel('Row 1 OR or invoice no.')).toHaveValue(`BD-${stamp}`);
        await expect(page.getByLabel('Row 1 particulars')).toHaveValue('Paper reams');
        await page.getByRole('button', { name: 'Use total as cheque amount' }).click();
        await page.getByRole('button', { name: /^Save draft/ }).click();
        await page.waitForURL(/\/cheques\/\d+$/);
        state.bdCheque = page.url();
        await expect(page.locator('body')).toContainText(`BD-${stamp}`);
        await page.goto(state.bdReceipt);
        await expect(page.getByRole('link', { name: /^\d{10}$/ })).toBeVisible();
    });
    await check('breakdown: the same receipt is hidden from a second cheque', async () => {
        await page.goto('/cheques/create');
        await pickPayee();
        await page.getByRole('button', { name: 'Add from receipts' }).click();
        await expect(page.getByRole('dialog')).not.toContainText(`BD-${stamp}`);
    });
    await check('breakdown: cancelling the cheque frees the receipt', async () => {
        await page.goto(state.bdCheque);
        await page.getByRole('button', { name: 'Cancel', exact: true }).click();
        await page.getByRole('dialog').getByRole('textbox').first().fill('free the receipt');
        await page.getByRole('dialog').getByRole('button', { name: 'Cancel cheque' }).click();
        await toast(page, /cancelled/i);
        await page.goto('/cheques/create');
        await pickPayee();
        await page.getByRole('button', { name: 'Add from receipts' }).click();
        await expect(page.getByRole('dialog')).toContainText(`BD-${stamp}`);
    });
    await check('cheque: pay to CASH prints CASH', async () => {
        await page.goto('/cheques/create');
        await pickPayee();
        await page.getByPlaceholder('0.00').first().fill('300');
        await page.getByLabel('Pay to CASH').check();
        await page.getByRole('button', { name: /^Save draft/ }).click();
        await page.waitForURL(/\/cheques\/\d+$/);
        await expect(page.locator('body')).toContainText('CASH');
    });
    await check('cheque: custom payee line is shown as printed', async () => {
        await page.goto('/cheques/create');
        await pickPayee();
        await page.getByPlaceholder('0.00').first().fill('310');
        await page.getByText('Print different text on the payee line…').click();
        await page.getByPlaceholder(/BPI Ayala/).fill('BPI AYALA FAO BUREAU OF INTERNAL REVENUE IFO QA CORP');
        await page.getByRole('button', { name: /^Save draft/ }).click();
        await page.waitForURL(/\/cheques\/\d+$/);
        await expect(page.locator('body')).toContainText('IFO QA CORP');
    });
    await check('cheque list: sort, columns, page size, quick view', async () => {
        await page.goto('/cheques');
        await page.getByRole('button', { name: /^Amount/ }).click();
        await page.waitForTimeout(800);
        await page.getByRole('button', { name: 'Columns' }).click();
        await page.getByLabel('Reference').check();
        await expect(page.locator('th', { hasText: 'Reference' })).toBeVisible();
        await page.mouse.click(900, 700); // close the Columns menu
        await page.locator('select[aria-label="Page size"]').selectOption('50');
        await page.waitForTimeout(800);
        await page.getByRole('button', { name: 'Quick view' }).first().click();
        await expect(page.getByRole('link', { name: /Open full record/ })).toBeVisible();
    });
    await check('saved filters: save, clear, apply restores the inputs', async () => {
        await page.goto('/cheques');
        const box = page.getByPlaceholder(/Cheque no., payee/);
        await box.fill('QA');
        await page.waitForTimeout(900);
        await page.getByText('+ Save this filter').click();
        await page.getByPlaceholder(/Name, e.g./).fill(`QA filter ${stamp}`);
        await page.getByRole('button', { name: 'Save', exact: true }).click();
        await toast(page, /Filter saved/);
        await box.fill('');
        await page.waitForTimeout(900);
        await page.getByRole('button', { name: `QA filter ${stamp}`, exact: true }).click();
        await page.waitForTimeout(900);
        await expect(box).toHaveValue('QA');
        await page.getByLabel(`Delete QA filter ${stamp}`).click();
    });
    await check('Print cheque link preselects the cheque in the Print center', async () => {
        await page.goto('/cheques?status=prepared');
        await page.locator('tbody tr').first().click();
        await page.waitForURL(/\/cheques\/\d+$/);
        await page.getByRole('link', { name: /Print cheque/ }).click();
        await page.waitForURL(/printing/);
        await expect(page.getByRole('button', { name: /Preview \(1\)/ })).toBeVisible();
    });

    const all = [...problems, ...issues.map((i) => `page issue: ${i}`)];
    fs.writeFileSync('test-results/walkthrough-FINANCE2.txt', all.join('\n'));
    expect(all, all.join('\n')).toEqual([]);
});
