# FINOX - Financial Operations System

Internal system for cheque preparation, receipts, documents, printing and reporting. No bank connection; release and clearing are entered manually.

**Stack:** Laravel 12, Inertia.js, React, Vite, MySQL. Auth scaffolding from Laravel Breeze.

## Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
# create a MySQL database named "finox", then set DB_* in .env
php artisan migrate
npm run dev        # in one terminal
php artisan serve  # in another
```

## Docs

Planning documents are in [docs/](docs/): system plan, function plan (function IDs such as CHQ-07), and usability plan.

## Modules

AUTH, DSH, CHQ, RCP, DOC, PRN, TRX, RPT, USR, LOG, BNK, TPL, SET.
