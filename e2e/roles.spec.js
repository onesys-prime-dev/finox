import { test, expect } from '@playwright/test';
import { login, watch } from './helpers';

// page => roles that may open it (from config/permissions.php)
const PAGES = {
    '/dashboard': ['admin', 'finance', 'encoder', 'printing', 'management'],
    '/profile': ['admin', 'finance', 'encoder', 'printing', 'management'],
    '/cheques': ['admin', 'finance', 'encoder', 'printing', 'management'],
    '/cheques/create': ['admin', 'finance', 'encoder'],
    '/cheques/1': ['admin', 'finance', 'encoder', 'printing', 'management'],
    '/receipts': ['admin', 'finance', 'encoder', 'printing', 'management'],
    '/receipts/intake': ['admin', 'finance', 'encoder'],
    '/receipts/new': ['admin', 'finance', 'encoder'],
    '/documents': ['admin', 'finance', 'encoder', 'printing', 'management'],
    '/printing': ['admin', 'finance', 'printing'],
    '/printing/history': ['admin', 'finance', 'printing', 'management'],
    '/transactions': ['admin', 'finance', 'management'],
    '/reports': ['admin', 'finance', 'printing', 'management'],
    '/reports/cheque-register': ['admin', 'finance', 'management'],
    '/reports/printing-history': ['admin', 'finance', 'printing', 'management'],
    '/activity': ['admin', 'management'],
    '/payees': ['admin', 'finance'],
    '/admin/users': ['admin'],
    '/admin/roles': ['admin'],
    '/admin/banks': ['admin'],
    '/admin/templates': ['admin'],
    '/admin/settings': ['admin'],
    '/admin/problems': ['admin'],
};
// the encoder only owns cheque 1; other roles see everything
for (const role of ['admin', 'finance', 'encoder', 'printing', 'management']) {
    test(`pages render without errors as ${role}`, async ({ page }) => {
        const issues = watch(page);
        await login(page, role);
        const bad = [];
        for (const [url, roles] of Object.entries(PAGES)) {
            const before = issues.length;
            const res = await page.goto(url);
            await page.waitForLoadState('networkidle');
            const allowed = roles.includes(role);
            const status = res?.status();
            if (allowed && status !== 200) bad.push(`${url}: expected 200, got ${status}`);
            if (!allowed && status !== 403) bad.push(`${url}: expected 403, got ${status}`);
            if (allowed && status === 200) {
                const body = await page.locator('body').innerText();
                const hit = body.match(/server error|whoops|\bundefined\b|\bNaN\b|\[object Object\]/i);
                if (hit) bad.push(`${url}: suspicious text on page: "${hit[0]}"`);
                if (!(await page.locator('h1, h2').first().isVisible().catch(() => false))) bad.push(`${url}: no heading rendered`);
            }
            for (const i of issues.slice(before)) {
                if (!allowed && /status of 403/.test(i)) continue; // expected on a denied page
                bad.push(`${url}: ${i}`);
            }
        }
        expect(bad, bad.join('\n')).toEqual([]);
    });
}
