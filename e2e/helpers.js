export const PASSWORD = 'E2e#Password2026';

export async function login(page, role) {
    await page.goto('/login');
    await page.locator('#email').fill(`e2e-${role}@finox.test`);
    await page.locator('#password').fill(PASSWORD);
    await Promise.all([page.waitForURL(/dashboard/), page.getByRole('button', { name: /sign in|log in/i }).click()]);
}

/** Collect everything that signals a broken page. */
export function watch(page) {
    const issues = [];
    page.on('pageerror', (e) => issues.push(`JS error: ${e.message}`));
    page.on('console', (m) => { if (m.type() === 'error') issues.push(`console: ${m.text()}`); });
    page.on('response', (r) => { if (r.status() >= 500) issues.push(`HTTP ${r.status()} ${r.url()}`); });
    page.on('requestfailed', (r) => { if (!/net::ERR_ABORTED/.test(r.failure()?.errorText ?? '')) issues.push(`request failed ${r.url()} ${r.failure()?.errorText}`); });
    return issues;
}
