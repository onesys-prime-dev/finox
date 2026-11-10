import { defineConfig } from '@playwright/test';

// Browser tests run against a separate database (finox_test) served on port 8765 by:
//   APP_ENV=e2e php artisan serve --host=127.0.0.1 --port=8765      (reads .env.e2e)
export default defineConfig({
    testDir: './e2e',
    timeout: 60_000,
    workers: 1,
    reporter: [['list']],
    use: { actionTimeout: 8000, baseURL: 'http://127.0.0.1:8765', headless: true, viewport: { width: 1366, height: 800 } },
});
