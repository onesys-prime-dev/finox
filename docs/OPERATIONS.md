# FINOX Operations Guide

For whoever installs, runs and backs up FINOX.

## 1. First-time setup

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env && php artisan key:generate
# create a MySQL database "finox" (utf8mb4), then set DB_* and APP_URL in .env
php artisan migrate --seed
```

Set in `.env` for production: `APP_ENV=production`, `APP_DEBUG=false`, `APP_TIMEZONE=Asia/Manila`, `SESSION_SECURE_COOKIE=true` (when served over HTTPS).

**First sign-in:** `admin@finox.test` / `ChangeMe#2026`. The system forces a password change immediately. Then create real users under *Administration → Users* and deactivate or rename the seed account.

### What the seeder creates
Five roles with the permission matrix from the planning docs, receipt categories, the **BDO Corporate Check Printing (3-part)** template, a standard **A5 receipt** template, BDO/BPI banks, one BDO account, and a payee list taken from the legacy Excel workbook. **Review the payees and the BDO account details before real use.**

> The BDO scan used as the template preview background is *not* stored in git (it shows real account data). On a new machine, upload it again in *Print templates → BDO… → Scan / background image*.

## 2. Background work

FINOX needs two background processes in production:

| Process | Command | Why |
|---|---|---|
| Scheduler | `* * * * * php artisan schedule:run` (cron / Task Scheduler, every minute) | Flags stale cheques daily 01:00; runs the backup daily 02:00 |
| Queue worker | `php artisan queue:work --tries=3` (keep running with Supervisor / NSSM) | Reserved for heavy jobs; failed jobs show on the admin home |

## 3. Backups

`php artisan finox:backup` writes `storage/app/backups/finox-backup-<timestamp>.zip` containing `database.sql` and every uploaded file. The last 14 are kept (`--keep=N`).

* It needs `mysqldump`. If it is not on `PATH`, set `MYSQLDUMP_PATH` in `.env` (Laragon: `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqldump.exe`).
* **Copy backups off the server** (another disk or machine) — a backup on the same disk is not a backup.
* Retention: documents and activity logs are kept 10 years live, then archived (never deleted). Older ones are hidden from the Documents and Activity Log lists unless "Include archived" is ticked; the system never deletes either. The period is Settings > "Keep records live for (years)".

### Restore (test this before go-live)
1. Unzip the backup somewhere safe.
2. Create an empty database and import: `mysql -u root -p finox < database.sql`
3. Copy the `files/` folder contents into `storage/app/private/`.
4. `php artisan migrate --force` (no-op if schema is current) and sign in.
5. Check: a few cheques open, an uploaded receipt previews, the activity log is intact.

## 4. Printing setup (do this once per computer/printer)

1. Open *Print center → Test print* and choose the cheque template. Print on **plain paper**.
2. In the browser print dialog set **Margins: None**, **Scale: 100 %** (not "fit to page"), and the correct paper size.
3. Lay the page over a real cheque against a light. If text is off, adjust **Shift X / Y** (mm) on the calibration screen and print again. The shift is saved on that computer and used for real prints.
4. For a bank layout that is not BDO: *Print templates → New cheque template*, upload a scan, drag fields into place, save, then assign the template to the bank account under *Banks & accounts*.

Notes
* The browser can only *start* a print job. After printing, FINOX asks "Printed correctly?" — that answer is the record of what happened. Unanswered jobs stay on the home screen.
* Pre-printed items (cheque number, account details, MICR line) are never printed.
* Saving a template change creates a new **version**; earlier print logs keep the version they used.
* Dot-matrix and laser printers feed paper differently; calibrate separately for each printer model.

## 5. Security checklist
* HTTPS and a firewall limiting access to the internal network.
* Idle sign-out (default 60 min), password length (default 10), lockout (5 attempts / 15 min) are under *Settings*.
* Files live in `storage/app/private` and are only streamed after a permission check — never expose `storage/` through the web server.
* The activity log table has no update or delete path in the application.

## 6. Load-testing (optional)
`php artisan finox:seed-loadtest 50000` fills the database with demo records so you can measure speed. Reset with `php artisan migrate:fresh --seed --drop-views` (this **erases all data** — never on a live system).

Measured on 50,000 records (dev server, MySQL 8): cheque list 40 ms, search 60–90 ms, transaction register 0.35 s (worst 0.65 s), dashboard 0.5 s, 25,000-row CSV export 1.2 s.

## Automated tests

* `php artisan test` — 37+ PHP tests (roles, business rules, exports, retention). Runs on in-memory SQLite by default; add `DB_CONNECTION=mysql DB_DATABASE=finox_test DB_USERNAME=root` to run on MySQL too (the transaction register is a MySQL view).
* `npm run e2e` — real-browser tests (Playwright/Chromium): every page as every role, and the full cheque lifecycle through the screens. It needs a separate database and server:
  1. Create the database `finox_test`, copy `.env` to `.env.e2e` and set `APP_ENV=e2e`, `DB_DATABASE=finox_test`, `SESSION_DRIVER=file`, `APP_URL=http://127.0.0.1:8765`.
  2. `DB_DATABASE=finox_test php artisan migrate:fresh --seed` then `php artisan db:seed --class=DemoSeeder` and create the `e2e-<role>@finox.test` users (password in `e2e/helpers.js`).
  3. `APP_ENV=e2e php artisan serve --host=127.0.0.1 --port=8765`, then `npm run e2e`.
* Headless Chromium shows no print dialog, so the e2e print step sends the browser's `afterprint` signal itself. In a real browser the confirm window appears when the print dialog closes; if it ever does not, the job waits under "Awaiting confirmation" on the Print center.

## Scheduler (Windows)

Laravel's scheduler needs one task that runs every minute. Use the XAMPP PHP (it has the `zip` extension the backup needs; the Laragon PHP does not):

```
schtasks /Create /SC MINUTE /MO 1 /TN "FINOX Scheduler" /TR "C:\xampp\php\php.exe C:\laragon\www\finox\artisan schedule:run"
```

Check with `php artisan schedule:list`; the next backup appears in `storage/app/backups`.

## Exports

Every report and the register export to CSV, Excel (.xlsx) and PDF. PDF is capped at 2,000 rows (the page says so); use Excel or CSV for the full set.
