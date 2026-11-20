# FINOX - Financial Operations System

Internal system for cheque preparation, receipts, documents, printing and reporting. No bank connection; release and clearing are entered manually.

**Stack:** Laravel 12, Inertia.js, React, Tailwind, Vite, MySQL.

## Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
# create a MySQL database named "finox", then set DB_* in .env
php artisan migrate --seed
npm run dev        # in one terminal
php artisan serve  # in another
```

First sign-in: `admin@finox.test` / `ChangeMe#2026` (you must change it immediately).
Run the tests with `php artisan test`. Changes reach `main` only through pull requests; the CI gate must pass ([docs/CI-CD.md](docs/CI-CD.md)).

## Docs

* [docs/BUILD-PLAN.md](docs/BUILD-PLAN.md) — decisions, architecture and build status (supersedes the .docx where they differ)
* [docs/OPERATIONS.md](docs/OPERATIONS.md) — deployment, scheduler, backups/restore, printer calibration
* [docs/CI-CD.md](docs/CI-CD.md) — CI checks, GitHub settings, staging/production deployment, releases, rollback, troubleshooting
* [docs/SERVER-SETUP.md](docs/SERVER-SETUP.md) — one-time CloudPanel VPS setup for staging and production
* [docs/USER-GUIDE.md](docs/USER-GUIDE.md) — one-page guide per role
* `docs/*.docx` — original planning documents (function IDs such as CHQ-07)

## Modules

AUTH, DSH, CHQ, RCP, DOC, PRN, TRX, RPT, USR, LOG, BNK, TPL, SET.

Useful commands: `finox:backup`, `finox:flag-stale`, `finox:seed-loadtest` (demo data — never on a live system).
