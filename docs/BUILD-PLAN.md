# FINOX Build Plan

Consolidates the four planning documents (`FINOX.docx`, System Function Plan, Usability Plan, Complete System Plan) and the reference files (BDO check scan, Excel check workbook). Where this file disagrees with the .docx files, **this file wins**.

Function IDs (CHQ-07 etc.) are from the Function Plan.

## 1. Decisions log

| Topic | Decision |
|---|---|
| Stack | Laravel 12, Inertia 2, React, Tailwind, Vite, MySQL. (Docs said Blade/Bootstrap; superseded.) |
| Currency | PHP (₱), 2 decimals. Words format: `ONE HUNDRED SIXTY FIVE THOUSAND FIVE HUNDRED FORTY EIGHT AND 69/100 PESOS ONLY`, uppercase, one format everywhere. |
| Launch layout | BDO Corporate Check Printing (3-part). Template engine must be **bank-agnostic** so any bank/layout is added by configuration, not code. |
| Payment Advice | Prints payee, amount, purpose, reference, prepared by, date. |
| Register | Read-only SQL **view** over cheques + receipts. No `transactions` table. |
| Edits | Number, payee, amount, date locked once **Prepared**. A fix = cancel and recreate. |
| Cancel/Void | Allowed Draft → Released, reason mandatory. Void after Released also asks for a stop-payment note. Finance/Admin only. |
| Stale | Configurable period (default 6 months from release). Scheduled job **flags** cheques; a user confirms the status change. |
| Outstanding | Cheque status Released or For Clearing. |
| Receipts | May stand alone; link to cheque optional. Tax = one amount plus optional rate. |
| Import | No Excel import in v1 (except one-time payee/bank seed, see §8). |
| Network | Internal only. 2FA for Admin/Finance if ever exposed remotely. |
| Auth | No public registration, no email verification. Admins create users. Forced password change on first login, lockout after repeated failures. |
| Permissions | In-house `roles` / `permissions` / `permission_role` tables and a `config/permissions.php` catalogue (spatie could not be installed offline); five seeded roles per the matrix in the Function Plan §3. Enforced by policies, `can:` middleware and `Gate::before`. |
| PDF / printing | Browser-rendered pages positioned in millimetres (`@page` size, margins 0) so alignment is exact and calibratable; "Save as PDF" from the print dialog. DomPDF was not installable offline and is not needed. |

Still open (non-blocking until Phase 3): exact BDO sheet dimensions in mm, printer model(s), document/log retention period, stale period confirmation.

## 2. Cleanup of the Breeze scaffold (do first)

- Remove `/register` route and page; remove `verified` middleware from `/dashboard`.
- Remove Sanctum unless an API is added.
- Add `is_active`, `must_change_password`, `last_login_at` to `users`; block inactive users at login.
- Set `APP_TIMEZONE=Asia/Manila`; default currency/date format via settings.

## 3. Architecture

- Modular monolith: `app/Modules/{Auth,Users,Banks,Templates,Cheques,Receipts,Documents,Printing,Reports,Logs,Settings}` each with Models, Policies, Requests, Services, Enums. Controllers thin, returning `Inertia::render()`.
- **Built once in Phase 2, reused everywhere:**
  - `Money` helper (DECIMAL(15,2), formatting, rounding) and `AmountInWords` service (PHP rules above, unit-tested).
  - `ActivityLogService` called inside the same DB transaction as the change; log table has no update/delete route.
  - `HasStatusHistory` trait (user, time, old, new, reason).
  - Status enums, one Policy per model, permission middleware, idempotency token on create/print actions.
  - Private-disk file streaming controller (policy check, no public URLs).
- React shared components: `StatusBadge`, `Money`, `DataTable`, `FilterBar`, `ConfirmDialog`, `SidePanel`, `Toast`, global search, role-based sidebar.
- Queues (`database` driver): batch print, PDFs, exports. Failed jobs surface on the admin home.
- Cache dashboard counts and permissions; invalidate on change.

## 4. Data model

```
users ─< user roles/permissions (spatie)
banks ─< bank_accounts >─ print_templates (versioned)
payees ─< cheques, receipts
bank_accounts ─< cheques ─< cheque_status_history
cheques ─1 cheque_releases, ─1 cheque_clearing
receipts ─< receipt_status_history; receipts >─ cheques (nullable)
receipt_categories ─< receipts
document_files (versioned, polymorphic via documentables) 
print_jobs ─< print_job_items ─< print_logs (initiated/confirmed/failed, is_reprint, reason)
print_templates ─< print_template_regions ─< print_template_fields
activity_logs, system_settings
view: transaction_register (cheques UNION receipts)
```

Key columns and constraints:

- **cheques:** `bank_account_id`, `cheque_number` (**unique with bank_account_id**), `cheque_date`, `payee_id`, `payee_line` (nullable override, e.g. BIR "FAO … IFO … TIN …"), `is_cash`, `amount` DECIMAL(15,2), `amount_words`, `purpose`, `reference_no`, `status`, `stop_payment_note`, `created_by`, soft deletes (never hard-deleted).
- **cheque_releases:** `released_at`, `released_to`, `released_by`, `remarks`. One per cheque.
- **cheque_clearing:** `result` (cleared/bounced), `cleared_at`, `remarks`, `document_id`, `updated_by`.
- **receipts:** `receipt_number`, `receipt_date`, `payee_id`, `amount`, `tax_amount`, `tax_rate`, `category_id`, `cheque_id` nullable, `status`.
- **document_files:** `path`, `original_name`, `mime` (checked by content), `size`, `version`, `replaced_by`, `replace_reason`.
- **Indexes:** every FK, plus number, payee name, date, status, bank_account_id. Unique on (bank_account_id, cheque_number).

## 5. Print template engine (the high-risk piece)

The BDO sheet is three stacked parts on one page; other banks are single-cheque. So templates are generic:

- `print_templates`: name, paper width/height (mm), orientation, version, background image (the scan, **preview only, never printed**), active.
- `print_template_regions`: named areas of the page (e.g. `acknowledgement`, `advice`, `cheque`) with an origin offset in mm. Single-part banks just have one region.
- `print_template_fields`: per region, a field with `source` (payee_line, amount_number, amount_words, date, check_no, purpose, reference, prepared_by, free_text), `x_mm`, `y_mm`, `width_mm`, font, size, alignment, `uppercase`, `wrap/max_lines`, and `format`.
- Special format **`date_segmented`**: prints MM-DD-YYYY one character per box with configurable spacing (BDO date boxes).
- Special format **`amount_number`**: `₱` handling and thousands separators per template.
- Pre-printed items (cheque number, account, MICR) are **never printed**; the number is *recorded* from the stock and confirmed by the user.
- Editor: drag fields over the scan background with live preview; calibration print on plain paper; global X/Y nudge per printer. Templates are versioned; a template in use cannot be deleted.

Payment Advice region prints: payee, amount, purpose, reference, prepared by, date.

## 6. Cheque lifecycle

`Draft → Prepared → Printed → Released → For Clearing → Cleared | Bounced | Stale`; `Cancelled`/`Voided` from Draft–Released (reason required; void after release also needs a stop-payment note). Reprint never changes status. Transitions, actors and inputs are as in Function Plan §4. Every transition writes status history + activity log in one transaction. Clearing screens show: "Recorded manually. Not verified with the bank."

## 7. Phases

| # | Phase | Deliverables | Exit test |
|---|---|---|---|
| 1 | Design | Measure BDO sheet (mm), pick printer, wireframes (cheque form, receipt queue, print center), walk 2–3 users through | Dimensions and printer recorded |
| 2 | Foundation | Scaffold cleanup, AUTH, USR (spatie), LOG, SET, layout shell, shared components, Money, AmountInWords, enums, seeders | Restricted action refused **and** logged; amount-in-words tests pass |
| 3 | Banks & templates | BNK, payees, TPL engine + editor, segmented date, calibration print | BDO sample prints correctly on real stock |
| 4 | Cheques | CHQ-01–16, history, release, clearing, duplicate check, stale flag job | Draft → Cleared, every step logged |
| 5 | Receipts & documents | RCP, DOC, upload queue, versioned replace, private streaming | PDF/image upload, replace, verify under permissions |
| 6 | Printing center | Print queue, preview, single/batch, initiated vs confirmed, reprint with reason | Mixed batch validates, prints, confirms |
| 7 | Register, reports, dashboard | Register view, saved filters, RPT, queued exports, role work queues | Dashboard totals = register = reports |
| 8 | QA & rollout | Seed 50k records, perf checks, backup + restore test, role guides, 2-week pilot | Restore passes; users sign off |

## 8. Reference files (format only)

Decision 2026-11-10: use the reference files for **format only**. `check format w date and formula.xlsm` is not a data source: no payees or bank accounts are imported from it. Users enter payees and bank accounts in the app (Payees, Admin > Bank accounts).
From `Check Voucher.pdf`: BDO template background and field positions (measured in Phase 1/3).

## 9. Performance and quality targets

Pages < 2 s; search < 1 s at 100k records; single cheque PDF < 3 s; batch of 50 starts < 10 s (queued). Server-side pagination everywhere; eager loading; no hard deletes on financial records; DB transactions for multi-record writes; scheduled DB + file backups.

## 10. Testing priorities

1. AmountInWords (edge cases: centavos, 0.01, thousands, millions, rounding).
2. Duplicate cheque number per bank account (app + DB unique).
3. Policy tests per role/action from the permission matrix.
4. Status transitions (only valid next step; history + log written atomically).
5. Reprint rules (reason required, no status/total change).
6. File access (no public URL; 403 without permission; type checked by content).
7. Print alignment on real BDO stock using calibration.

## 11. Build status

| Phase | Status | Notes |
|---|---|---|
| 1 Design | Done (with defaults) | BDO sheet measured from the scan (200 × 280 mm); positions in the seeded template |
| 2 Foundation | Done | Auth (lockout, forced change, idle timeout), users, roles, activity log, settings, shared UI, money + amount-in-words (PHP & JS twins) |
| 3 Banks & templates | Done | Template editor (drag/nudge, versioned), segmented date, calibration print |
| 4 Cheques | Done | Full lifecycle, duplicate check, locking, stale flag job, bulk prepare, duplicate-as-draft |
| 5 Receipts & documents | Done | Upload queue, duplicate check/override, verify → archive, versioned file replace, private streaming |
| 6 Printing center | Done | Preview, single/batch, initiated vs confirmed, reprint with reason, history |
| 7 Register, reports, dashboard | Done | Merge-sorted register, 16 reports + CSV, role work queues, saved filters |
| 8 QA & rollout | Backend/performance done | 30 automated tests; 50k-record timings in OPERATIONS.md. **Still to do by people:** real-stock calibration, backup restore drill, 2-week pilot, role walkthroughs |

### Not done / deliberate gaps
* **Excel (.xlsx) and server-side PDF export:** CSV and browser "Save as PDF" are provided. Native XLSX/DomPDF need packages that could not be installed offline — add `openspout/openspout` / `barryvdh/laravel-dompdf` later if wanted.
* **Real printer calibration** cannot be done in software; follow OPERATIONS.md §4 on actual stock. The BDO field positions come from the scan and may need a few tenths of a millimetre of adjustment.
* **Decisions made 2026-11-10:**
  * **Retention:** documents and activity logs stay in the live system for 10 years (suits BIR/bank record-keeping; confirm with the accountant). After that they are **archived, never deleted**: they leave the default lists and search but stay retrievable (an "Archived" filter for authorised roles). The archive job itself is not built yet; until it is, nothing is removed.
  * **Receipt tax:** keep one amount plus an optional rate. No VAT/withholding breakdown for now.
  * **2FA:** not needed while FINOX stays on the internal network. Revisit before exposing it outside.
* **No seeded payees or bank accounts.** The seeder creates only roles, permissions, receipt categories, bank names (BDO, BPI) and the print templates; add real payees and accounts in the app.
