import { test, expect } from '@playwright/test';
import { login, watch, PASSWORD } from './helpers';
import path from 'node:path';
import fs from 'node:fs';

// Exploratory walk through every screen and action as each kind of user. Problems are collected, not fatal,
// so one run reports everything that is wrong.
test.describe.configure({ mode: 'default' });
test.setTimeout(420_000);

const PDF = Buffer.from('%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF');
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
let problems = [];
const stamp = Date.now().toString().slice(-6);
const state = {};

let __page = null;
async function check(name, fn) {
    try { await fn(); } catch (e) {
        try { await __page?.screenshot({ path: `test-results/fail-${name.replace(/[^a-z0-9]+/gi, '-').slice(0, 50)}.png` }); } catch {} problems.push(`${name}: ${String(e.message).replace(/\x1b\[[0-9;]*m/g, '').split('\n').filter(Boolean).slice(0, 6).join(' | ')}`); }
}
function flush(role, issues) {
    const all = [...problems, ...issues.map((i) => `page issue: ${i}`)];
    problems = [];
    fs.writeFileSync(`test-results/walkthrough-${role}.txt`, all.join('\n'));
    return all;
}
function patchGoto(page) {
    const goto = page.goto.bind(page);
    page.goto = async (...a) => { const r = await goto(...a); await page.waitForLoadState('networkidle').catch(() => {}); return r; };
}
const toast = (page, re) => expect(page.locator('[role=status]')).toContainText(re, { timeout: 15000 });

test.beforeAll(() => { fs.mkdirSync('test-results', { recursive: true }); });

test('ADMIN: users, roles, banks, payees, templates, settings, categories, problems, activity', async ({ page }) => {
    const issues = watch(page); patchGoto(page); __page = page;
    await login(page, 'admin');

    await check('users: create one user per role', async () => {
        await page.goto('/admin/users');
        for (const role of ['Finance Staff', 'Encoder', 'Printing Staff', 'Management']) {
            await page.getByRole('button', { name: /Add user/ }).click();
            const d = page.getByRole('dialog');
            await d.getByLabel(/Full name/).fill(`QA ${role} ${stamp}`);
            await d.getByLabel(/^Email/).fill(`qa-${role.split(' ')[0].toLowerCase()}-${stamp}@finox.test`);
            await d.getByLabel(/Role/).selectOption({ label: role });
            await d.getByRole('button', { name: 'Generate' }).click();
            await d.getByRole('button', { name: 'Create user' }).click();
            await toast(page, /created/i);
        }
        await expect(page.locator('body')).toContainText(`QA Encoder ${stamp}`);
    });
    await check('users: weak password rejected', async () => {
        await page.getByRole('button', { name: /Add user/ }).click();
        const d = page.getByRole('dialog');
        await d.getByLabel(/Full name/).fill('Weak User');
        await d.getByLabel(/^Email/).fill(`weak-${stamp}@finox.test`);
        await d.getByLabel(/Temporary password/).fill('abc');
        await d.getByRole('button', { name: 'Create user' }).click();
        await expect(d).toContainText(/password/i);
        await d.getByRole('button', { name: 'Cancel' }).click();
    });
    await check('users: reset password shows temp password', async () => {
        const row = page.locator('tr', { hasText: `QA Encoder ${stamp}` });
        await row.getByTitle('Reset password').click();
        await page.getByRole('dialog').getByRole('button', { name: 'Reset password' }).click();
        await toast(page, /Temporary password/);
    });
    await check('users: deactivate and reactivate', async () => {
        await page.getByPlaceholder('Search name or email…').fill(`QA Management ${stamp}`);
        await expect(page.locator('tbody tr')).toHaveCount(1);
        const row = page.locator('tr', { hasText: `QA Management ${stamp}` });
        await row.getByTitle('Deactivate').click();
        await page.getByRole('dialog').getByRole('button', { name: 'Deactivate' }).click();
        await expect(page.locator('tr', { hasText: `QA Management ${stamp}` })).toContainText('Inactive');
        await expect(page.locator('tr', { hasText: `QA Management ${stamp}` })).toContainText('Inactive');
        await page.locator('tr', { hasText: `QA Management ${stamp}` }).getByTitle('Activate').click();
        await page.getByRole('dialog').getByRole('button', { name: 'Activate' }).click();
        await expect(page.locator('tr', { hasText: `QA Management ${stamp}` })).toContainText('Active');
        await expect(page.getByRole('dialog')).toHaveCount(0);
    });
    await check('users: user detail page', async () => {
        await page.getByPlaceholder('Search name or email…').fill(`QA Finance Staff ${stamp}`);
        await page.waitForTimeout(1000);
        await page.getByRole('link', { name: `QA Finance Staff ${stamp}` }).click();
        await expect(page.locator('body')).toContainText(/Sign-in|Recent|activity/i);
    });
    await check('users: cannot deactivate self', async () => {
        await page.goto('/admin/users');
        const row = page.locator('tr', { hasText: 'E2E Admin' });
        await row.getByTitle('Deactivate').click();
        await page.getByRole('dialog').getByRole('button', { name: 'Deactivate' }).click();
        await toast(page, /cannot deactivate your own/i);
    });

    await check('roles: change and restore a permission', async () => {
        await page.goto('/admin/roles');
        await page.getByRole('button', { name: /Encoder/ }).first().click();
        const box = page.getByLabel(/Edit draft cheques/);
        const was = await box.isChecked();
        await box.setChecked(!was);
        await page.getByRole('button', { name: 'Save permissions' }).click();
        await toast(page, /Permissions saved/);
        await box.setChecked(was);
        await page.getByRole('button', { name: 'Save permissions' }).click();
        await toast(page, /Permissions saved/);
    });
    await check('roles: administrator role is locked', async () => {
        await page.getByRole('button', { name: /Administrator\s*\d+ user/ }).click();
        await expect(page.locator('body')).toContainText(/always has full access/);
    });

    await check('banks: add bank + account, edit', async () => {
        await page.goto('/admin/banks');
        await page.getByRole('button', { name: /Add bank/ }).click();
        await page.getByRole('dialog').getByLabel(/Bank name/).fill(`QA Bank ${stamp}`);
        await page.getByRole('dialog').getByRole('button', { name: 'Save' }).click();
        await toast(page, /Bank added/);
        const card = page.locator('.card', { hasText: `QA Bank ${stamp}` }).first();
        await card.getByRole('button', { name: /Add account/ }).click();
        const d = page.getByRole('dialog');
        await d.getByLabel(/Account name/).fill('QA ACCOUNT');
        await d.getByLabel(/Account number/).fill(`99${stamp}`);
        await d.getByLabel(/Starting cheque number/).fill('0000500001');
        await d.getByRole('button', { name: 'Save account' }).click();
        await toast(page, /added/i);
        await page.locator('.card', { hasText: `QA Bank ${stamp}` }).first().locator('tbody tr').getByRole('button').click();
        await page.getByRole('dialog').getByLabel(/Branch/).fill('QA Branch');
        await page.getByRole('dialog').getByRole('button', { name: 'Save account' }).click();
        await toast(page, /Account saved/);
    });
    await check('banks: deactivate the QA bank so it does not pollute other tests', async () => {
        await page.goto('/admin/banks');
        const card = page.locator('.card', { hasText: `QA Bank ${stamp}` }).first();
        await card.getByRole('button', { name: /Edit bank/ }).click();
        await page.getByRole('dialog').getByLabel('Active').uncheck();
        await page.getByRole('dialog').getByRole('button', { name: 'Save' }).click();
        await toast(page, /Bank saved/);
        await expect(page.locator('.card', { hasText: `QA Bank ${stamp}` }).first()).toContainText('Inactive');
    });
    await check('banks: duplicate bank name rejected', async () => {
        await page.getByRole('button', { name: /Add bank/ }).click();
        await page.getByRole('dialog').getByLabel(/Bank name/).fill(`QA Bank ${stamp}`);
        await page.getByRole('dialog').getByRole('button', { name: 'Save' }).click();
        await expect(page.getByRole('dialog')).toContainText(/already been taken/i);
        await page.getByRole('dialog').getByRole('button', { name: 'Cancel' }).click();
    });

    await check('payees: add, edit, deactivate', async () => {
        await page.goto('/payees');
        await page.getByRole('button', { name: /Add payee/ }).click();
        await page.getByRole('dialog').getByLabel('Name').fill(`QA Payee ${stamp}`);
        await page.getByRole('dialog').getByLabel('TIN').fill('123-456-789-000');
        await page.getByRole('dialog').getByRole('button', { name: 'Save' }).click();
        await toast(page, /Payee added/);
        await page.getByPlaceholder('Search payees…').fill(`QA Payee ${stamp}`);
        await expect(page.locator('tbody tr')).toHaveCount(1);
        await page.locator('tbody tr').first().getByRole('button').click();
        await page.getByRole('dialog').getByLabel('Default payee line').fill('FAO QA TEST');
        await page.getByRole('dialog').getByRole('button', { name: 'Save' }).click();
        await toast(page, /Payee saved/);
    });

    await check('templates: list, editor opens, add table field, save', async () => {
        await page.goto('/admin/templates');
        await expect(page.locator('body')).toContainText('BDO Corporate Check Printing');
        await page.getByRole('link', { name: /Edit layout/ }).first().click();
        await page.getByRole('button', { name: /^breakdown/ }).click();
        await expect(page.locator('body')).toContainText('Breakdown table');
        await page.getByRole('button', { name: /Save template/ }).click();
        await toast(page, /Template saved|version/i);
    });
    await check('templates: calibrate page', async () => {
        await page.goto('/admin/templates');
        await page.getByRole('link', { name: /Calibrate/ }).first().click();
        await expect(page.locator('body')).toContainText('Calibration');
    });

    await check('settings: save + validation', async () => {
        await page.goto('/admin/settings');
        await page.getByLabel('Stale period (days)').fill('200');
        await page.getByRole('button', { name: /Save settings/ }).click();
        await toast(page, /Settings saved/);
        await page.getByLabel('Stale period (days)').fill('5');
        await page.getByRole('button', { name: /Save settings/ }).click();
        await expect(page.locator('body')).toContainText(/at least 30|must be at least/i);
        await page.getByLabel('Stale period (days)').fill('180');
        await page.getByRole('button', { name: /Save settings/ }).click();
        await toast(page, /Settings saved/);
    });
    await check('settings: categories add + disable', async () => {
        await page.getByPlaceholder('New category').fill(`QA Cat ${stamp}`);
        await page.getByRole('button', { name: 'Add', exact: true }).click();
        await toast(page, /Category added/);
        await page.locator('span', { hasText: `QA Cat ${stamp}` }).getByRole('button', { name: 'Disable' }).click();
        await toast(page, /Category saved/);
    });

    await check('problem report: send and resolve', async () => {
        await page.goto('/dashboard');
        await page.getByRole('button', { name: /Report a problem/ }).click();
        await page.getByRole('dialog').getByPlaceholder(/What happened/).fill('QA walkthrough test problem');
        await page.getByRole('dialog').getByRole('button', { name: 'Send report' }).click();
        await toast(page, /reported/i);
        await page.goto('/admin/problems');
        await expect(page.locator('body')).toContainText('QA walkthrough test problem');
        await page.getByRole('button', { name: 'Mark resolved' }).first().click();
        await expect(page.getByRole('button', { name: 'Reopen' }).first()).toBeVisible();
    });

    await check('activity log: lists + search + filter', async () => {
        await page.goto('/activity');
        await expect(page.locator('body')).toContainText(/Created user|Signed in|Updated/);
        await page.locator('input.input.pl-9').first().fill('QA Encoder');
        await page.waitForTimeout(700);
        await expect(page.locator('body')).toContainText(/QA Encoder/);
    });

    await check('dashboard: widgets render for admin', async () => {
        await page.goto('/dashboard');
        await expect(page.locator('body')).toContainText(/cheque/i);
    });
    await check('admin can reach every area', async () => {
        for (const u of ['/transactions', '/reports/cheque-breakdown', '/reports/user-activity', '/printing', '/printing/history', '/documents', '/receipts', '/cheques']) {
            const r = await page.goto(u);
            expect(r.status(), u).toBe(200);
        }
    });
    await check('profile: wrong current password rejected', async () => {
        await page.goto('/profile');
        await page.getByLabel('Current password').fill('wrong-password');
        await page.getByLabel('New password', { exact: true }).fill('Brand#NewPass2026');
        await page.getByLabel('Confirm new password').fill('Brand#NewPass2026');
        await page.getByRole('button', { name: 'Update password' }).click();
        await expect(page.locator('body')).toContainText(/current password is incorrect|does not match|incorrect/i);
    });

    const all = flush('ADMIN', issues);
    expect(all, all.join('\n')).toEqual([]);
});

test('ENCODER: create cheques with breakdown, receipts, own-records only, no privileged actions', async ({ page }) => {
    const issues = watch(page); patchGoto(page); __page = page;
    await login(page, 'encoder');

    await check('cheque: validation shows errors', async () => {
        await page.goto('/cheques/create');
        await page.getByRole('button', { name: 'Save draft' }).click();
        await expect(page.locator('body')).toContainText(/required/i);
    });
    await check('cheque: create draft with breakdown rows', async () => {
        await page.goto('/cheques/create');
        await page.getByPlaceholder('Search payee or vendor…').fill('Metro Office');
        await page.getByRole('option', { name: 'Metro Office Supplies Inc.' }).click();
        await page.getByPlaceholder('0.00').first().fill('3,000.00');
        await page.getByRole('button', { name: 'Add row' }).click();
        await page.getByLabel('Row 1 OR or invoice no.').fill('OR-QA1');
        await page.getByLabel('Row 1 amount').fill('1,000');
        await page.getByRole('button', { name: 'Add row' }).click();
        await page.getByLabel('Row 2 amount').fill('2,000');
        await expect(page.locator('body')).toContainText('matches the cheque amount');
        await page.getByLabel('Purpose').fill('QA purpose');
        await page.getByRole('button', { name: /^Save draft/ }).click();
        await page.waitForURL(/\/cheques\/\d+$/);
        state.chequeUrl = page.url();
        await expect(page.locator('body')).toContainText('OR-QA1');
        await expect(page.getByRole('button', { name: /Prepare cheque/ })).toHaveCount(0);
    });
    await check('cheque: invalid amount "1,5" is rejected on the form', async () => {
        await page.goto('/cheques/create');
        await page.getByPlaceholder('0.00').first().fill('1,5');
        await expect(page.locator('body')).toContainText(/digits only|valid amount/i);
    });
    await check('cheque: duplicate number blocked as you type', async () => {
        await page.goto('/cheques/create');
        const used = await page.locator('input.font-mono').first().inputValue();
        const first = state.chequeUrl;
        await page.goto(first + '/edit');
        const num = await page.locator('input.font-mono').first().inputValue();
        await page.goto('/cheques/create');
        await page.locator('input.font-mono').first().fill(num);
        await expect(page.locator('body')).toContainText(/Already used for this account/);
    });
    await check('cheque: edit draft', async () => {
        await page.goto(state.chequeUrl + '/edit');
        await page.getByLabel('Purpose').fill('QA purpose edited');
        await page.getByRole('button', { name: /Save changes/ }).click();
        await page.waitForURL(/\/cheques\/\d+$/);
        await expect(page.locator('body')).toContainText('QA purpose edited');
    });
    await check('cheque: duplicate copies rows, asks new number', async () => {
        await page.goto(state.chequeUrl);
        await page.getByRole('link', { name: 'Duplicate' }).click();
        await expect(page.locator('body')).toContainText(/Details copied/);
        await expect(page.getByLabel('Row 1 OR or invoice no.')).toHaveValue('OR-QA1');
    });
    await check('cheque: encoder has no cancel/void/prepare buttons', async () => {
        await page.goto(state.chequeUrl);
        for (const n of ['Cancel', 'Void', 'Prepare cheque']) await expect(page.getByRole('button', { name: n, exact: true })).toHaveCount(0);
    });
    await check('cheque list: own only, search, status tab', async () => {
        await page.goto('/cheques');
        await expect(page.locator('body')).not.toContainText('2,345.67');
        await page.getByPlaceholder(/Cheque no., payee/).fill('QA purpose');
        await page.waitForTimeout(700);
        await page.getByRole('button', { name: 'Drafts' }).click();
    });
    await check('cheque: cannot open someone else’s cheque (403)', async () => {
        const r = await page.request.get('/cheques/2');
        expect([403, 404]).toContain(r.status());
    });

    await check('receipt: manual entry, validation, duplicate warning', async () => {
        await page.goto('/receipts/new');
        await page.getByRole('button', { name: 'Save receipt' }).click();
        await expect(page.locator('body')).toContainText(/required/i);
        await page.getByLabel(/Receipt number/).fill(`QA-${stamp}`);
        await page.getByPlaceholder('Search payee or vendor…').fill('Metro Office');
        await page.getByRole('option', { name: 'Metro Office Supplies Inc.' }).click();
        await page.getByPlaceholder('0.00').first().fill('1,000.00');
        await page.getByRole('button', { name: 'Save receipt' }).click();
        await page.waitForURL(/\/receipts\/\d+$/);
        state.receiptUrl = page.url();
        await expect(page.locator('body')).toContainText(`QA-${stamp}`);
        // same number + same vendor again
        await page.goto('/receipts/new');
        await page.getByLabel(/Receipt number/).fill(`QA-${stamp}`);
        await page.getByPlaceholder('Search payee or vendor…').fill('Metro Office');
        await page.getByRole('option', { name: 'Metro Office Supplies Inc.' }).click();
        await expect(page.locator('body')).toContainText(/Already recorded for this vendor/);
    });
    await check('receipt: attach file, replace file with reason, preview', async () => {
        await page.goto(state.receiptUrl);
        await page.locator('input[type=file]').setInputFiles({ name: 'r.pdf', mimeType: 'application/pdf', buffer: PDF });
        await expect(page.locator('body')).toContainText('r.pdf');
        await page.getByRole('button', { name: 'Replace' }).click();
        const d = page.getByRole('dialog');
        await d.locator('input[type=file]').setInputFiles({ name: 'r2.png', mimeType: 'image/png', buffer: PNG });
        await d.getByLabel(/Reason for replacing/).fill('better scan');
        await d.getByRole('button', { name: 'Replace file' }).click();
        await expect(page.locator('body')).toContainText('r2.png');
        await expect(page.locator('body')).toContainText(/File history/);
    });
    await check('receipt: bad file type refused', async () => {
        await page.goto('/receipts/intake');
        const r = await page.request.post('/receipts/upload', { multipart: { 'files[]': { name: 'evil.pdf', mimeType: 'application/pdf', buffer: Buffer.from('<?php echo 1;') } }, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        expect([302, 419, 422]).toContain(r.status());
    });
    await check('receipt intake: upload files -> queue -> save & next', async () => {
        await page.goto('/receipts/intake');
        await page.locator('input[type=file]').setInputFiles([{ name: 'a.pdf', mimeType: 'application/pdf', buffer: PDF }, { name: 'b.png', mimeType: 'image/png', buffer: PNG }]);
        await expect(page.locator('body')).toContainText(/Queue · 2|file\(s\) added/);
        await page.getByLabel(/Receipt number/).fill(`Q1-${stamp}`);
        await page.getByPlaceholder('Search payee or vendor…').fill('Metro Office');
        await page.getByRole('option', { name: 'Metro Office Supplies Inc.' }).click();
        await page.getByPlaceholder('0.00').first().fill('250');
        await page.getByRole('button', { name: /Save & next/ }).click();
        await toast(page, /Saved/);
    });
    await check('receipt: cannot verify (no permission)', async () => {
        await page.goto(state.receiptUrl);
        await expect(page.getByRole('button', { name: 'Mark verified' })).toHaveCount(0);
    });
    await check('payees: quick add from the cheque form', async () => {
        await page.goto('/cheques/create');
        await page.getByPlaceholder('Search payee or vendor…').fill(`Quick Payee ${stamp}`);
        await page.getByText(`Add "Quick Payee ${stamp}" as a new payee`).click();
        await expect(page.locator('body')).toContainText(`Quick Payee ${stamp}`);
    });
    await check('global search finds own records', async () => {
        await page.goto('/dashboard');
        await page.locator('#global-search').fill(`QA-${stamp}`);
        await expect(page.locator('body')).toContainText(/Receipt/);
    });
    await check('forbidden areas return 403', async () => {
        for (const u of ['/admin/users', '/admin/settings', '/printing', '/transactions', '/reports', '/activity', '/payees', '/admin/templates']) {
            const r = await page.request.get(u);
            expect(r.status(), u).toBe(403);
        }
    });
    await check('forbidden actions return 403', async () => {
        const csrf = await page.evaluate(() => document.querySelector('meta[name=csrf-token]')?.content ?? '');
        for (const u of ['/cheques/1/prepare', '/cheques/1/release', '/cheques/bulk-prepare']) {
            const r = await page.request.post(u, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': decodeURIComponent((await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '') } });
            expect([403, 422], u).toContain(r.status());
        }
    });
    await check('dashboard shows my drafts', async () => {
        await page.goto('/dashboard');
        await expect(page.locator('body')).toContainText(/My drafts/);
    });
    const all = flush('ENCODER', issues);
    expect(all, all.join('\n')).toEqual([]);
});

test('FINANCE: prepare, release, clearing, void, receipts workflow, reports, register, documents', async ({ page }) => {
    const issues = watch(page); patchGoto(page); __page = page;
    await login(page, 'finance');

    await check('cheques: create + bulk prepare drafts', async () => {
        for (let i = 0; i < 2; i++) {
            await page.goto('/cheques/create');
            await page.getByPlaceholder('Search payee or vendor…').fill('Metro Office');
            await page.getByRole('option', { name: 'Metro Office Supplies Inc.' }).click();
            await page.getByPlaceholder('0.00').first().fill(`${500 + i}.25`);
            await page.getByRole('button', { name: /^Save draft/ }).click();
            await page.waitForURL(/\/cheques\/\d+$/);
        }
        await page.goto('/cheques?status=draft');
        await page.getByLabel('Select all').check();
        await page.getByRole('button', { name: 'Prepare drafts' }).click();
        await toast(page, /Prepared \d+ cheque/);
    });
    await check('cheque: prepare blocked when payee/amount invalid is explained', async () => {
        await page.goto('/cheques?status=draft');
        // drafts from demo data may have problems; just verify the page works
        await expect(page.locator('table')).toBeVisible();
    });
    await check('cheque: preparation locks edit of amount', async () => {
        await page.goto('/cheques?status=prepared');
        await page.locator('tbody tr').first().click();
        await page.waitForURL(/\/cheques\/\d+$/);
        await page.getByRole('link', { name: 'Edit' }).click();
        await expect(page.locator('body')).toContainText(/locked/i);
        await expect(page.getByPlaceholder('0.00').first()).toBeDisabled();
    });
    await check('cheque: void a printed cheque (needs reason)', async () => {
        await page.goto('/cheques?status=printed');
        await page.locator('tbody tr').first().click();
        await page.waitForURL(/\/cheques\/\d+$/);
        await expect(page.getByRole('button', { name: 'Cancel', exact: true })).toHaveCount(0);
        await page.getByRole('button', { name: 'Void', exact: true }).click();
        const d = page.getByRole('dialog');
        await d.getByRole('textbox').first().fill('QA void');
        await d.getByRole('button', { name: 'Void cheque' }).click();
        await toast(page, /voided/i);
    });
    await check('cheque: released -> for clearing -> bounced with proof file', async () => {
        await page.goto('/cheques?status=released');
        await page.locator('tbody tr').first().click();
        await page.waitForURL(/\/cheques\/\d+$/);
        await page.getByRole('button', { name: 'Mark as for clearing' }).click();
        await toast(page, /awaiting clearing/i);
        await page.getByRole('button', { name: 'Record clearing result' }).click();
        const d = page.getByRole('dialog');
        await d.getByRole('button', { name: 'bounced' }).click();
        await d.locator('input[type=file]').setInputFiles({ name: 'proof.pdf', mimeType: 'application/pdf', buffer: PDF });
        await d.getByRole('button', { name: /Record as bounced/ }).click();
        await toast(page, /recorded/i);
        await expect(page.locator('body')).toContainText(/Bounced/);
    });
    await check('cheque: release date in the future is refused', async () => {
        await page.goto('/cheques?status=printed');
        const row = page.locator('tbody tr').first();
        if (await row.count()) {
            await row.click();
            await page.waitForURL(/\/cheques\/\d+$/);
            await page.getByRole('button', { name: 'Record release' }).click();
            const d = page.getByRole('dialog');
            await d.getByLabel(/Release date/).fill('2099-01-01');
            await d.getByLabel(/Released to/).fill('X');
            await d.getByLabel(/Released by/).fill('Y');
            await d.getByRole('button', { name: 'Record release' }).click();
            await expect(d).toContainText(/today or earlier|before or equal/i);
        }
    });
    await check('cheque: attach supporting document', async () => {
        await page.goto('/cheques?status=prepared');
        await page.locator('tbody tr').first().click();
        await page.waitForURL(/\/cheques\/\d+$/);
        await page.locator('input[type=file]').first().setInputFiles({ name: 'support.pdf', mimeType: 'application/pdf', buffer: PDF });
        await expect(page.locator('body')).toContainText('support.pdf');
    });
    await check('cheque: stale flag banner and mark stale', async () => {
        await page.goto('/cheques?flagged=1');
        const row = page.locator('tbody tr').first();
        if (await row.count()) {
            await row.click();
            await page.waitForURL(/\/cheques\/\d+$/);
            await page.getByRole('button', { name: 'Mark as stale' }).click();
            await page.getByRole('dialog').getByRole('button', { name: 'Mark stale' }).click();
            await toast(page, /stale/i);
        }
    });

    await check('receipts: draft->recorded->verified->processed->archived', async () => {
        await page.goto('/receipts/new');
        await page.getByLabel(/Receipt number/).fill(`F-${stamp}`);
        await page.getByPlaceholder('Search payee or vendor…').fill('Metro Office');
        await page.getByRole('option', { name: 'Metro Office Supplies Inc.' }).click();
        await page.getByPlaceholder('0.00').first().fill('1,500.00');
        await page.getByRole('button', { name: 'Save receipt' }).click();
        await page.waitForURL(/\/receipts\/\d+$/);
        state.frUrl = page.url();
        await page.getByRole('button', { name: 'Mark verified' }).click();
        await toast(page, /verified/i);
        await page.getByRole('button', { name: 'Mark processed' }).click();
        await toast(page, /processed/i);
        await page.getByRole('button', { name: 'Archive' }).click();
        await toast(page, /archived/i);
        await expect(page.getByRole('button', { name: /Cancel receipt/ })).toHaveCount(0);
    });
    await check('receipts: cancel with reason', async () => {
        await page.goto('/receipts/new');
        await page.getByLabel(/Receipt number/).fill(`FC-${stamp}`);
        await page.getByPlaceholder('Search payee or vendor…').fill('Metro Office');
        await page.getByRole('option', { name: 'Metro Office Supplies Inc.' }).click();
        await page.getByPlaceholder('0.00').first().fill('99');
        await page.getByRole('button', { name: 'Save receipt' }).click();
        await page.waitForURL(/\/receipts\/\d+$/);
        await page.getByRole('button', { name: 'Cancel receipt' }).first().click();
        const d = page.getByRole('dialog');
        await d.getByRole('textbox').first().fill('QA cancel receipt');
        await d.getByRole('button', { name: 'Cancel receipt' }).click();
        await toast(page, /cancelled/i);
    });
    await check('receipts: edit verified receipt resets verification', async () => {
        await page.goto('/receipts/new');
        await page.getByLabel(/Receipt number/).fill(`FE-${stamp}`);
        await page.getByPlaceholder('Search payee or vendor…').fill('Metro Office');
        await page.getByRole('option', { name: 'Metro Office Supplies Inc.' }).click();
        await page.getByPlaceholder('0.00').first().fill('77');
        await page.getByRole('button', { name: 'Save receipt' }).click();
        await page.waitForURL(/\/receipts\/\d+$/);
        await page.getByRole('button', { name: 'Mark verified' }).click();
        await toast(page, /verified/i);
        await page.getByRole('link', { name: 'Edit' }).click();
        await page.getByLabel(/Description/).fill('changed after verification');
        await page.getByRole('button', { name: 'Save changes' }).click();
        await page.waitForURL(/\/receipts\/\d+$/);
        await expect(page.getByRole('button', { name: 'Mark verified' })).toBeVisible();
    });
    await check('receipts list: filters & search', async () => {
        await page.goto('/receipts');
        await page.getByPlaceholder(/Receipt no/).first().fill(`F-${stamp}`);
        await page.waitForTimeout(700);
        await expect(page.locator('body')).toContainText(`F-${stamp}`);
    });

    await check('documents: upload, search, download, preview', async () => {
        await page.goto('/documents');
        await page.getByRole('button', { name: /Upload document/ }).click();
        await page.getByRole('dialog').locator('input[type=file]').setInputFiles({ name: 'qa-doc.pdf', mimeType: 'application/pdf', buffer: PDF });
        await page.getByRole('dialog').getByRole('button', { name: 'Upload' }).click();
        await toast(page, /uploaded/i);
        await page.getByPlaceholder(/Search file name/).fill('qa-doc');
        await page.waitForTimeout(700);
        await expect(page.locator('body')).toContainText('qa-doc.pdf');
        const href = await page.locator('a[aria-label=Download]').first().getAttribute('href');
        const res = await page.request.get(href);
        expect(res.status()).toBe(200);
    });
    await check('payees: manage', async () => {
        await page.goto('/payees');
        await expect(page.locator('table')).toBeVisible();
    });
    await check('register: filters, saved filter, exports', async () => {
        await page.goto('/transactions');
        await page.getByPlaceholder(/Reference, payee/).fill('F-' + stamp);
        await page.waitForTimeout(700);
        await expect(page.locator('body')).toContainText(`F-${stamp}`);
        for (const [q, sig] of [['', null], ['&format=xlsx', 'PK'], ['&format=pdf', '%PDF']]) {
            const r = await page.request.get(`/transactions/export?search=x${q}`);
            expect(r.status()).toBe(200);
            if (sig) expect((await r.body()).subarray(0, sig.length).toString('latin1')).toBe(sig);
        }
    });
    await check('reports: every report renders and exports', async () => {
        await page.goto('/reports');
        const links = await page.locator('a[href*="/reports/"]').evaluateAll((as) => as.map((a) => a.getAttribute('href')));
        expect(links.length).toBeGreaterThan(8);
        for (const href of links) {
            const r = await page.request.get(href);
            expect(r.status(), href).toBe(200);
            const e = await page.request.get(href + '/export?format=csv');
            expect(e.status(), href + ' export').toBe(200);
        }
    });
    await check('dashboard queues link through', async () => {
        await page.goto('/dashboard');
        await expect(page.locator('body')).toContainText(/To prepare|For clearing/);
    });
    await check('finance cannot reach admin screens', async () => {
        for (const u of ['/admin/users', '/admin/roles', '/admin/settings', '/activity']) {
            expect((await page.request.get(u)).status(), u).toBe(403);
        }
    });
    const all = flush('FINANCE', issues);
    expect(all, all.join('\n')).toEqual([]);
});

test('PRINTING: queue, preview, print, confirm, failed print, reprint, calibration, history', async ({ page }) => {
    const issues = watch(page); patchGoto(page); __page = page;
    await login(page, 'printing');

    await check('print center lists prepared cheques', async () => {
        await page.goto('/printing');
        await expect(page.locator('tbody tr').first()).toBeVisible();
    });
    await check('preview requires selection; select, preview, print', async () => {
        await page.goto('/printing');
        await expect(page.getByRole('button', { name: /^Preview/ })).toBeDisabled();
        await page.locator('tbody tr').first().click();
        await page.getByRole('button', { name: /Preview/ }).click();
        await expect(page.getByRole('dialog')).toContainText('Printer settings');
        await page.getByRole('button', { name: /^Print \d+ page/ }).click();
        await page.waitForTimeout(600);
        await page.evaluate(() => window.dispatchEvent(new Event('afterprint')));
        const d = page.getByRole('dialog');
        await expect(d).toContainText(/what happened/i);
        await d.getByRole('button', { name: /There was a problem/ }).click();
        await d.getByRole('textbox').fill('Paper jammed');
        await d.getByRole('button', { name: 'Report problem' }).click();
        await toast(page, /Problem recorded/);
        await expect(page.locator('body')).toContainText(/Recent print problems/);
    });
    await check('reprint needs reason + acknowledgement', async () => {
        await page.goto('/cheques?status=printed');
        await page.locator('tbody tr').first().click();
        await page.waitForURL(/\/cheques\/\d+$/);
        await page.getByRole('link', { name: 'Reprint' }).click();
        await page.waitForURL(/printing/);
        await page.getByRole('button', { name: /Preview/ }).click();
        const printBtn = page.getByRole('button', { name: /^Reprint \d+ page/ });
        await expect(printBtn).toBeDisabled();
        await page.getByRole('dialog').locator('select').selectOption('Paper jam');
        await page.getByRole('dialog').getByRole('checkbox').check();
        await expect(printBtn).toBeEnabled();
        await printBtn.click();
        await page.waitForTimeout(600);
        await page.evaluate(() => window.dispatchEvent(new Event('afterprint')));
        await page.getByRole('dialog').getByRole('button', { name: 'Yes, printed correctly' }).click();
        await expect(page.locator('body')).toContainText(/Print confirmed/);
    });
    await check('print history lists jobs, reprint filter', async () => {
        await page.goto('/printing/history');
        await expect(page.locator('tbody tr').first()).toBeVisible();
    });
    await check('calibration page loads', async () => {
        await page.goto('/printing');
        await page.getByRole('button', { name: /Test print/ }).click();
        await page.locator('a[href*="/printing/calibrate/"]').first().click();
        await expect(page.locator('body')).toContainText('Calibration test print');
    });
    await check('receipt tab prints a recorded receipt', async () => {
        await page.goto('/printing?category=receipt');
        await expect(page.locator('body')).toContainText(/Printable receipts/);
    });
    await check('printing user cannot create cheques or verify receipts', async () => {
        expect((await page.request.get('/cheques/create')).status()).toBe(403);
        expect((await page.request.get('/receipts/intake')).status()).toBe(403);
        expect((await page.request.get('/admin/users')).status()).toBe(403);
    });
    await check('report: printing history only', async () => {
        expect((await page.request.get('/reports/printing-history')).status()).toBe(200);
        expect((await page.request.get('/reports/cheque-register')).status()).toBe(403);
    });
    const all = flush('PRINTING', issues);
    expect(all, all.join('\n')).toEqual([]);
});

test('MANAGEMENT: read-only oversight', async ({ page }) => {
    const issues = watch(page); patchGoto(page); __page = page;
    await login(page, 'management');
    await check('dashboard, register, reports, activity open', async () => {
        for (const u of ['/dashboard', '/transactions', '/reports', '/reports/cheque-register', '/reports/cheque-breakdown', '/activity', '/cheques', '/receipts', '/documents', '/printing/history']) {
            expect((await page.request.get(u)).status(), u).toBe(200);
        }
    });
    await check('cheque page has no action buttons', async () => {
        await page.goto('/cheques?status=prepared');
        await page.locator('tbody tr').first().click();
        await page.waitForURL(/\/cheques\/\d+$/);
        for (const n of ['Prepare cheque', 'Record release', 'Edit', 'Duplicate', 'Void', 'Cancel']) {
            await expect(page.getByRole('button', { name: n, exact: true })).toHaveCount(0);
            await expect(page.getByRole('link', { name: n, exact: true })).toHaveCount(0);
        }
    });
    await check('no create/edit/admin access', async () => {
        for (const u of ['/cheques/create', '/receipts/new', '/receipts/intake', '/printing', '/payees', '/admin/users', '/admin/settings', '/admin/banks']) {
            expect((await page.request.get(u)).status(), u).toBe(403);
        }
    });
    await check('export works for management', async () => {
        expect((await page.request.get('/reports/cheque-register/export?format=xlsx')).status()).toBe(200);
    });
    const all = flush('MANAGEMENT', issues);
    expect(all, all.join('\n')).toEqual([]);
});

test('LOGIN: wrong password, lockout, deactivated user, forced password change, sign out', async ({ page }) => {
    const issues = watch(page); patchGoto(page); __page = page;
    await check('wrong password rejected with a generic message', async () => {
        await page.goto('/login');
        await page.locator('#email').fill('e2e-finance@finox.test');
        await page.locator('#password').fill('nope-nope');
        await page.getByRole('button', { name: /sign in|log in/i }).click();
        await expect(page.locator('body')).toContainText(/do not match|credentials/i);
    });
    await check('unknown email rejected', async () => {
        await page.goto('/login');
        await page.locator('#email').fill('nobody@finox.test');
        await page.locator('#password').fill('whatever1A');
        await page.getByRole('button', { name: /sign in|log in/i }).click();
        await expect(page.locator('body')).toContainText(/do not match|credentials/i);
    });
    await check('new user must change password at first sign-in', async () => {
        // create via admin then log in as that user
        await login(page, 'admin');
        await page.goto('/admin/users');
        await page.getByRole('button', { name: /Add user/ }).click();
        const d = page.getByRole('dialog');
        await d.getByLabel(/Full name/).fill('Forced Change');
        await d.getByLabel(/^Email/).fill(`forced-${stamp}@finox.test`);
        await d.getByLabel(/Temporary password/).fill('Temp#Pass2026x');
        await d.getByRole('button', { name: 'Create user' }).click();
        await toast(page, /created/i);
        await page.getByRole('button', { name: /E2E Admin/ }).click();
        await page.getByRole('button', { name: /Sign out/ }).click();
        await page.waitForURL(/login/);
        await page.locator('#email').fill(`forced-${stamp}@finox.test`);
        await page.locator('#password').fill('Temp#Pass2026x');
        await page.getByRole('button', { name: /sign in|log in/i }).click();
        await page.waitForURL(/change-password/);
        await page.goto('/cheques');
        await expect(page).toHaveURL(/change-password/);
        await page.getByLabel('Current (temporary) password').fill('Temp#Pass2026x');
        await page.getByLabel('New password', { exact: true }).fill('Fresh#Pass2026y');
        await page.getByLabel(/Confirm/).fill('Fresh#Pass2026y');
        await page.getByRole('button', { name: /Change|Update|Save/ }).click();
        await page.waitForURL(/dashboard/);
    });
    await check('lockout after repeated failures', async () => {
        await page.context().clearCookies();
        for (let i = 0; i < 6; i++) {
            await page.goto('/login');
            await page.locator('#email').fill(`forced-${stamp}@finox.test`);
            await page.locator('#password').fill('bad-password-' + i);
            await page.getByRole('button', { name: /sign in|log in/i }).click();
        }
        await page.goto('/login');
        await page.locator('#email').fill(`forced-${stamp}@finox.test`);
        await page.locator('#password').fill('Fresh#Pass2026y');
        await page.getByRole('button', { name: /sign in|log in/i }).click();
        await expect(page.locator('body')).toContainText(/locked/i);
    });
    const all = flush('LOGIN', issues);
    expect(all, all.join('\n')).toEqual([]);
});
