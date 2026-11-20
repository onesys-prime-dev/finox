import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login } from './helpers';

// Accessibility gate: every page (as admin) plus the sign-in page is scanned with axe-core.
// The run fails on violations with impact "critical" or "serious"; moderate/minor ones are
// listed in the attached report but do not fail.
const PAGES = [
    '/dashboard', '/profile', '/cheques', '/cheques/create', '/cheques/1', '/receipts', '/receipts/intake',
    '/receipts/new', '/documents', '/printing', '/printing/history', '/transactions', '/reports',
    '/reports/cheque-register', '/reports/printing-history', '/activity', '/payees', '/admin/users',
    '/admin/roles', '/admin/banks', '/admin/templates', '/admin/settings', '/admin/problems',
];

const FAILING_IMPACTS = ['critical', 'serious'];

// Reviewed exceptions: { rule: 'axe-rule-id', page: '/path' or '*', reason: 'why it is accepted' }.
// Add entries only after review; every entry needs a reason.
const ALLOWED = [];

const isAllowed = (url, v) => ALLOWED.some((a) => a.rule === v.id && (a.page === '*' || a.page === url));

async function scan(page, url, testInfo) {
    const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    await testInfo.attach(`axe ${url}`, { body: JSON.stringify(result.violations, null, 2), contentType: 'application/json' });

    return result.violations
        .filter((v) => FAILING_IMPACTS.includes(v.impact) && !isAllowed(url, v))
        .map((v) => `${url}: [${v.impact}] ${v.id} - ${v.help} (${v.nodes.length} element(s): ${v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join(', ')})`);
}

test('sign-in page has no serious accessibility violations', async ({ page }, testInfo) => {
    await page.goto('/login');
    expect(await scan(page, '/login', testInfo)).toEqual([]);
});

test('every page has no serious accessibility violations (admin)', async ({ page }, testInfo) => {
    test.setTimeout(240_000);
    await login(page, 'admin');
    const problems = [];
    for (const url of PAGES) {
        await page.goto(url);
        await page.waitForLoadState('networkidle');
        problems.push(...(await scan(page, url, testInfo)));
    }
    expect(problems, problems.join('\n')).toEqual([]);
});
