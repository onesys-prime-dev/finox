# FINOX Quick Guides (one page per role)

**Shortcuts everywhere:** `/` search · `N` new record · `Ctrl+S` save draft (cheque form) · `Ctrl+Enter` save & next (receipt queue) · `Esc` close panel · `Enter` moves to the next field.
**Status colours:** grey Draft/Stale/Archived · blue Prepared/Recorded · purple Printed/Verified · amber Released/For Clearing · green Cleared/Processed · red Bounced/Cancelled/Voided.
**Important:** release and clearing are **recorded by hand**. FINOX is not connected to any bank.

---

## Encoder
Home shows **My drafts**.
1. **New cheque** (`N`): choose the bank account → the next cheque number is suggested; check it against the paper stock. Pick the payee (type to search; add a new one from the same box). Enter the amount — the words appear automatically and cannot be edited.
2. **Save draft** or **Save and add another** for batches. Duplicate numbers are caught as you type.
   **Breakdown (optional):** under the amount, add one row per OR/invoice (date, OR/invoice no., vendor, particulars, amount) or use **Add from receipts** to pull in the payee's receipts (tick *Show all vendors* for payments covering several vendors). The rows print in the blank Payment Advice part of the cheque; the **Advice** tab of the preview shows it. If the rows do not add up to the cheque amount you get a warning, not a block — **Use total as cheque amount** fixes it in one click. Rows lock when the cheque is Prepared.
3. **Receipts → Upload files**: drop up to 10 files, then fill the details beside each preview. `Ctrl+Enter` saves and moves to the next. Uploading never verifies a receipt.
4. You only see records you created.

## Finance staff
Home shows **To prepare · To verify · To release · For clearing**.
1. **Prepare** a draft (one click, or tick several in the list → *Prepare drafts*). Number, payee, amount and date are locked afterwards — fix a mistake by cancelling and creating a new cheque.
2. After printing, open the cheque → **Record release** (date, released to/by).
3. **Mark as for clearing**, then **Record clearing result** (cleared or bounced) with the date. A banner reminds everyone this is manual.
4. **Void/Cancel** needs a reason; voiding a released cheque also needs a stop-payment note.
5. **Receipts**: review → **Mark verified** → **Processed** → **Archived**.
6. If a breakdown has more rows than fit on the advice (15 by default), the advice prints "see attached schedule": open the cheque → Breakdown → **A4 schedule** and print the full list on plain paper. **Reports → Cheque Breakdown** finds which cheque paid a given OR/invoice no. or vendor.
7. A flag icon on a cheque means it passed the stale period (default 180 days): review it and press **Mark as stale** if appropriate.

## Printing staff
Home shows **Ready to print · Awaiting confirmation · Failed prints**.
1. *Print center* → tick cheques → **Preview** (check the layout; use *Show full sheet* if needed) → **Print**.
2. When the browser dialog closes, answer **Printed correctly** or **There was a problem** and describe it. Do not skip this step.
3. **Reprint** (from the cheque page or the Print center) requires a reason and acknowledgement that a paper cheque may already exist. A reprint never creates a new transaction.
4. Use **Test print** on plain paper whenever paper, printer or template changes.

## Management
Home shows the **Overview** (cheques and receipts are always totals of their own — never added together) and recent activity. Use **Transactions** to search both together, save filters, and export CSV. **Reports** has 16 reports with print/PDF and CSV export. **Activity log** is read-only.

## Administrator
* **Users**: add (temporary password, forced change at first sign-in), deactivate, reset password, unlock.
* **Roles & permissions**: tick what each role may do; applies on the next request and is logged.
* **Banks & accounts**, **Print templates**, **Settings** (organization, formats, upload limit, stale days, security, receipt categories).
* **Problem reports** collects what users send with *Report a problem*.
* Home alerts: inactive users, accounts without a template, failed background jobs, open problem reports.
