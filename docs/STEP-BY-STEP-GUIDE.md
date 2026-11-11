| Role | Can do |# FINOX — Step-by-Step Guide (start to finish)
| Role | Can do |
| Role | Can do |FINOX is the office's system for **cheques, receipts, documents, printing and reports**. It keeps one record of everything, shows who did what and when, and never talks to a bank — release and clearing are always typed in by staff.
| Role | Can do |
| Role | Can do |This guide follows the real order of work. Read **Part 1** once, then go to the part for your job.
| Role | Can do |
| Role | Can do || If you are… | Read |
| Role | Can do ||---|---|
| Role | Can do || Everyone | Part 1 (sign in) and Part 9 (if something goes wrong) |
| Role | Can do || Setting the system up (Administrator) | Part 2 |
| Role | Can do || Encoder (enters cheques and receipts) | Parts 3, 4 |
| Role | Can do || Finance staff (prepares, releases, tracks) | Parts 3–7 |
| Role | Can do || Printing staff | Part 5 |
| Role | Can do || Management (read-only) | Part 8 |
| Role | Can do |
| Role | Can do |---
| Role | Can do |
| Role | Can do |## Part 1 — Signing in
| Role | Can do |
| Role | Can do |1. Open FINOX in your browser (ask your administrator for the address).
| Role | Can do |2. Type your **Email** and **Password**, then press **Sign in**.
| Role | Can do |3. **First time only:** you will be taken to a page asking you to change the temporary password. Enter the temporary password, then a new one (at least 10 characters, with capital and small letters and a number), and confirm it. You cannot use any other screen until you do this.
| Role | Can do |4. You land on **Home**. The boxes on Home are your own work queues (for example *My drafts*, *To prepare*, *Ready to print*). Click a box to open that list.
| Role | Can do |5. To leave, click your name (top right) → **Sign out**. You are also signed out automatically after a period of no activity (60 minutes unless your administrator changed it).
| Role | Can do |
| Role | Can do |**Good to know**
| Role | Can do |- Wrong password 5 times locks the account for 15 minutes. Ask an administrator to unlock it if you can't wait.
| Role | Can do |- Forgot your password? There is no "forgot password" email. Ask an administrator to reset it; they will give you a new temporary password.
| Role | Can do |- Change your own password any time: your name → **Profile & password**.
| Role | Can do |- Shortcuts: `/` jumps to search, `N` starts a new cheque (or receipt, on the receipts pages), `Ctrl+S` saves a cheque draft.
| Role | Can do |- Something looks wrong? Click **Report a problem** (bottom of the left menu). It sends the page you are on to the administrator.
| Role | Can do |
| Role | Can do |---
| Role | Can do |
| Role | Can do |## Part 2 — First-time setup (Administrator)
| Role | Can do |
| Role | Can do |Do these once, in this order. Everything is under **Administration** in the left menu.
| Role | Can do |
| Role | Can do |### 2.1 Settings
| Role | Can do |**Settings** → fill in the organization name and address (printed on receipts and reports), date format, currency symbol, upload limit, stale period (days), how long to keep records live, idle sign-out minutes, password length and lock-out rules. Press **Save settings**.
| Role | Can do |
| Role | Can do |### 2.2 Banks and accounts
| Role | Can do |1. **Banks & accounts** → **Add bank** → type the bank name → **Save**.
| Role | Can do |2. On that bank press **Add account** and fill in:
| Role | Can do |   - *Account name* and *Account number* (required),
| Role | Can do |   - *Branch*, *Account reference* (e.g. BRSTN),
| Role | Can do |   - **Print template** — pick the cheque layout for this account (see 2.3),
| Role | Can do |   - **Starting cheque number** — the first cheque number of the stock you are loading, so FINOX can suggest the next one automatically.
| Role | Can do |3. Press **Save account**. An account without a print template can be used for drafts but **cannot be printed** (the page shows a warning).
| Role | Can do |
| Role | Can do |### 2.3 Print templates
| Role | Can do |A template tells FINOX where to print each item on your cheque stock. FINOX comes with **BDO Corporate Check Printing (3-part)** (cheque + Payment Advice + Acknowledgement Receipt) and a Standard Receipt layout.
| Role | Can do |1. **Print templates** → **Edit layout** on the template.
| Role | Can do |2. Click a field on the sheet (or in the list on the right) and drag it, or use the arrow keys (0.1 mm; hold Shift for 1 mm). Change the font, alignment, label and so on in the panel.
| Role | Can do |3. **Breakdown table** — the table that prints the receipts a cheque pays in the blank Payment Advice area. Click *breakdown* in the field list to set its position, row height, how many rows fit, whether the header and total rows print, the column positions, and what to do when there are more rows than fit (**Attached schedule** or **Shrink to fit first**). Use **Add table** if a template doesn't have one yet.
| Role | Can do |4. **Save template.** Every change that moves things creates a new version; old print records keep the version they used.
| Role | Can do |5. **Calibrate** (see Part 5.5) on plain paper before first real use.
| Role | Can do |
| Role | Can do |### 2.4 Receipt categories
| Role | Can do |**Settings** → *Receipt categories* → type a name → **Add**. Use **Disable** to hide one without deleting it.
| Role | Can do |
| Role | Can do |### 2.5 Payees and vendors
| Role | Can do |**Payees** → **Add payee** (name, TIN, and a *Default payee line* if cheques to this payee should print special text such as `BPI Ayala FAO Bureau of Internal Revenue IFO <company> TIN <number>`). Staff can also add payees on the spot while entering a cheque.
| Role | Can do |
| Role | Can do |### 2.6 Users and roles
| Role | Can do |1. **Users** → **Add user**. Enter name, email, choose the **Role**, and press **Generate** for a temporary password (or type one). Press **Create user** and give the person the email and temporary password.
| Role | Can do |2. The five standard roles:
| Role | Can do |
| Role | Can do || Role | Can do |
| Role | Can do ||---|---|
| Role | Can do || Administrator | Everything |
| Role | Can do || Finance Staff | Create, prepare, release, record clearing, cancel/void cheques; handle receipts; reports; print and reprint |
| Role | Can do || Encoder | Create and edit own draft cheques and receipts; sees only own records |
| Role | Can do || Printing Staff | Print queue, print, reprint, calibration; view all cheques and receipts |
| Role | Can do || Management | Read-only: dashboard, transactions, reports, activity log |
| Role | Can do |
| Role | Can do |3. **Roles & permissions** lets you change what a role can do (tick or untick, then **Save permissions**; takes effect on that user's next click). The Administrator role is always full access.
| Role | Can do |4. On the Users list: pencil = edit, key = **reset password** (a temporary password is shown **once** — copy it), lock = **deactivate / activate**, open padlock = **unlock** a locked account. You cannot deactivate yourself or change your own role.
| Role | Can do |5. Click a user's name to see their recent sign-ins and activity.
| Role | Can do |
| Role | Can do |### 2.7 Daily admin checks
| Role | Can do |- **Home** shows alerts: inactive users, bank accounts without a print template, failed background jobs, open problem reports.
| Role | Can do |- **Problem reports** lists what staff reported; press **Mark resolved** when done.
| Role | Can do |- **Activity log** is a permanent, read-only record of who did what. Nothing in it can be edited or deleted.
| Role | Can do |- A nightly job flags stale cheques and makes a backup. Backups are described in `docs/OPERATIONS.md`.
| Role | Can do |
| Role | Can do |---
| Role | Can do |
| Role | Can do |## Part 3 — Receipts: from paper to "Processed"
| Role | Can do |
| Role | Can do |Receipts should be entered **before** the cheque that pays them, so they can be pulled into the cheque's breakdown.
| Role | Can do |
| Role | Can do |### 3.1 Enter one receipt by typing
| Role | Can do |1. **Receipts** → **Enter manually**.
| Role | Can do |2. Fill in: **Receipt number**, **Receipt date**, **Vendor / payee**, **Amount** (and tax amount / rate if needed), category, description, reference.
| Role | Can do |3. If the same number already exists for the same vendor, a red warning appears. Only users with the override permission can save anyway, and must type a reason.
| Role | Can do |4. Optional: attach the scanned file (PDF, JPG or PNG).
| Role | Can do |5. Press **Save receipt** (status **Recorded**) or **Save as draft** if you are not finished.
| Role | Can do |
| Role | Can do |### 3.2 Enter many receipts from scans (the intake queue)
| Role | Can do |1. **Receipts** → **Upload files**. Drag up to 10 files in (PDF/JPG/PNG, up to the size limit).
| Role | Can do |2. Each file becomes a draft in the queue on the left, with a preview on the right.
| Role | Can do |3. Fill in the details beside the preview. Press **Save & next** (or `Ctrl+Enter`) to record it and jump to the next one, or **Save draft** to come back later.
| Role | Can do |4. Uploading never verifies a receipt.
| Role | Can do |
| Role | Can do |### 3.3 Checking a receipt (Finance)
| Role | Can do |Open the receipt → press the button at the top: **Mark verified** → **Mark processed** → **Archive**. Each step is recorded with your name. Editing a verified receipt sends it back to *Recorded* so it is checked again.
| Role | Can do |
| Role | Can do |### 3.4 Files on a receipt
| Role | Can do |On the receipt page: **Preview**, **Download**, or **Replace** (you must give a reason; the old file is kept in *File history*). Files are private — they open only for people allowed to see the receipt.
| Role | Can do |
| Role | Can do |### 3.5 Cancelling a receipt
| Role | Can do |**Cancel receipt** → give a reason. The receipt is never deleted; it stays for the record.
| Role | Can do |
| Role | Can do |---
| Role | Can do |
| Role | Can do |## Part 4 — Cheques: from draft to printed
| Role | Can do |
| Role | Can do |### 4.1 Create a cheque
| Role | Can do |1. **Cheques** → **New cheque** (or press `N`).
| Role | Can do |2. **Bank account** — choose it. The **Cheque number** is filled with the next number; check it against the paper stock in your hand and correct it if needed. If the number already exists you are told straight away.
| Role | Can do |3. **Cheque date** and **Amount**. The **amount in words** appears automatically (e.g. `THIRTY EIGHT THOUSAND FIVE HUNDRED AND 50/100 PESOS ONLY`) and cannot be typed over. Amounts accept thousands commas (`1,234.50`) but not `1,5`.
| Role | Can do |4. **Payee** — type to search, pick from the list, or press *Add "name" as a new payee*.
| Role | Can do |   - **Pay to CASH** prints CASH on the payee line (a payee is still kept for reports).
| Role | Can do |   - **Print different text on the payee line…** replaces the printed name (for example BIR-style lines).
| Role | Can do |5. Fill **Purpose**, **Reference no.** and **Remarks (internal)** if needed.
| Role | Can do |
| Role | Can do |### 4.2 The breakdown (the table printed in the blank Payment Advice area)
| Role | Can do |The breakdown lists what the cheque pays — one row per receipt or invoice. It is optional; a cheque without rows prints as before.
| Role | Can do |1. Under the amount find **Breakdown (optional)**.
| Role | Can do |2. **Add from receipts** — a list of the payee's recorded or verified receipts that are not yet on another cheque. Tick the ones this cheque pays → **Add rows**. The row is filled from the receipt and the receipt becomes linked to this cheque.
| Role | Can do |   - To pay receipts of several vendors on one cheque (for example one BIR payment covering several suppliers) tick **Show all vendors**.
| Role | Can do |3. **Add row** — type a row yourself: Date, OR/invoice no., Vendor, Particulars, Amount. Use this when the vendor's receipt does not exist yet.
| Role | Can do |4. Use the arrows to reorder, the bin to remove a row.
| Role | Can do |5. Watch the line under the rows:
| Role | Can do |   - green: *Breakdown total … matches the cheque amount*;
| Role | Can do |   - amber: shows how much more or less it is. You can still save and print (it is only a warning). **Use total as cheque amount** copies the total into the Amount field.
| Role | Can do |6. The **Live preview** on the right has three tabs: **Cheque**, **Advice** (shows the table as it will print) and **Full sheet**.
| Role | Can do |
| Role | Can do |### 4.3 Save it
| Role | Can do |- **Save draft** (or `Ctrl+S`) — saves, you can edit later.
| Role | Can do |- **Save and add another** — for batches.
| Role | Can do |- **Save and prepare** — saves and prepares in one go (finance staff only).
| Role | Can do |
| Role | Can do |Drafts can be edited as often as you like (cheque page → **Edit**). **Duplicate** copies a cheque (and its typed breakdown rows, but not receipt links) into a new draft — you enter a new cheque number.
| Role | Can do |
| Role | Can do |### 4.4 Prepare (Finance)
| Role | Can do |1. Open the draft → **Prepare cheque**. Or tick several drafts in **Cheques** and press **Prepare drafts**.
| Role | Can do |2. If something is missing (payee, amount, date, an inactive bank account, a duplicate number) FINOX lists what to fix.
| Role | Can do |3. **After Prepare, the cheque number, payee, amount, date and breakdown rows are locked.** Only purpose, reference and remarks can still be changed. To fix a locked mistake, cancel or void the cheque and create a new one.
| Role | Can do |
| Role | Can do |### 4.5 Cancel or void (Finance)
| Role | Can do |- **Cancel** — only before printing (Draft or Prepared): there is no paper cheque yet.
| Role | Can do |- **Void** — after printing (Printed or Released): paper exists. Voiding a **Released** cheque also needs a **stop-payment note** (what you told the bank).
| Role | Can do |- A reason is always required. Cancelled/voided cheques stay in the records, and any receipts on them become free to use on another cheque.
| Role | Can do |
| Role | Can do |---
| Role | Can do |
| Role | Can do |## Part 5 — Printing
| Role | Can do |
| Role | Can do |### 5.1 The print queue
| Role | Can do |**Print center** shows prepared cheques waiting to print. Filter by bank account if you have several. Cheques on **different templates cannot be printed in one job** (different paper layouts) — print one bank account at a time.
| Role | Can do |
| Role | Can do |### 5.2 Print a cheque
| Role | Can do |1. Load the cheque stock in the printer.
| Role | Can do |2. In **Print center**, tick the cheque(s) → **Preview**. Check the payee, amount, words and date. Use **Show full sheet** to see the Payment Advice breakdown too.
| Role | Can do |   - If the breakdown has more rows than fit, a yellow notice tells you: the advice prints *"…and N more — see attached schedule"*. See 5.6.
| Role | Can do |3. Use **Shift X / Shift Y** if your printer prints slightly off (remembered on this computer).
| Role | Can do |4. Press **Print**. In the browser's print window choose **Margins: None**, **Scale: 100%** and the right paper size, then print.
| Role | Can do |5. **Do not skip the next step.**
| Role | Can do |
| Role | Can do |### 5.3 Confirm what actually happened
| Role | Can do |After the print window closes FINOX asks *what happened?*
| Role | Can do |- **Printed correctly** — the cheque becomes **Printed**.
| Role | Can do |- **There was a problem** — describe it (jam, misaligned…) and, if only some pages failed, tick which. The failed ones are listed under *Recent print problems* so they can be reprinted.
| Role | Can do |If you choose **Decide later**, the job stays under *Awaiting confirmation* on Home and in a yellow badge at the top of the screen until you answer.
| Role | Can do |
| Role | Can do |### 5.4 Reprint
| Role | Can do |Cheque page → **Reprint** (or the Print center). You must choose a reason, and tick that you understand the earlier paper cheque must be destroyed or accounted for. A reprint never creates a new transaction. Every print is in **Print history**.
| Role | Can do |
| Role | Can do |### 5.5 Test print (calibration)
| Role | Can do |Whenever paper, printer or template changes: **Print center → Test print →** pick the template → **Print on plain paper**. It prints guide marks and sample values and is **never** logged as a real print. Hold the sheet against a real cheque in front of a light and adjust Shift X/Y or the template until it matches.
| Role | Can do |
| Role | Can do |### 5.6 When the breakdown doesn't fit (A4 schedule)
| Role | Can do |On the cheque page, in the **Breakdown** card, press **A4 schedule**. It shows every row, the total and signature lines on a plain A4 page. Press **Print on A4** and attach it to the cheque. It never uses cheque stock, and it is logged.
| Role | Can do |
| Role | Can do |### 5.7 Printing receipts
| Role | Can do |**Print center → Receipts** tab lists receipts that can be printed (not drafts or cancelled).
| Role | Can do |
| Role | Can do |---
| Role | Can do |
| Role | Can do |## Part 6 — After printing: release, clearing, stale (Finance)
| Role | Can do |
| Role | Can do |### 6.1 Record the release (the cheque was handed over)
| Role | Can do |1. Cheque page (status **Printed**) → **Record release**.
| Role | Can do |2. Release date (not in the future), **Released to**, **Released by**, optional reference and remarks → **Record release**. Status becomes **Released**.
| Role | Can do |3. Do this only after the cheque has physically been handed over.
| Role | Can do |
| Role | Can do |### 6.2 Awaiting clearing
| Role | Can do |Press **Mark as for clearing** when the payee has deposited it or you are waiting for the bank. Status: **For Clearing**.
| Role | Can do |
| Role | Can do |### 6.3 Record the clearing result
| Role | Can do |1. **Record clearing result**.
| Role | Can do |2. Choose **cleared** or **bounced**, the date, remarks, and optionally attach the bank's proof (PDF/JPG/PNG).
| Role | Can do |3. The screen reminds everyone: *Recorded manually. Not verified with the bank.*
| Role | Can do |
| Role | Can do |### 6.4 Stale cheques
| Role | Can do |Every night FINOX flags released cheques older than the stale period (default 180 days) with a red flag. Open the cheque and press **Mark as stale** if it can no longer be presented. Home shows *Stale review*; the **Cheques** list has a *flagged* view. A person always confirms — nothing changes by itself.
| Role | Can do |
| Role | Can do |### 6.5 Supporting documents
| Role | Can do |On any cheque: **Attach file** under *Supporting documents* (PDF/JPG/PNG).
| Role | Can do |
| Role | Can do |---
| Role | Can do |
| Role | Can do |## Part 7 — Finding things and reporting
| Role | Can do |
| Role | Can do |### 7.1 Search and lists
| Role | Can do |- The search box at the top (or `/`) finds cheque numbers, receipt numbers, payees and references.
| Role | Can do |- **Cheques** and **Receipts** lists: use the tabs (All, Drafts, Prepared, Printed, Outstanding, Cleared), the search box, bank account, dates and *Mine*. Click a column title to sort. **Columns** chooses what to show. The eye icon gives a quick side view.
| Role | Can do |- **+ Save this filter** stores your current filters under a name; click the name later to reapply it.
| Role | Can do |
| Role | Can do |### 7.2 Transactions (the register)
| Role | Can do |**Transactions** lists cheques and receipts together, read-only. Filter by type, status, dates, amount range, bank account or user. Totals for cheques and receipts are always shown **separately** — they are never added together. Export as **CSV**, **Excel** or **PDF**.
| Role | Can do |
| Role | Can do |### 7.3 Reports
| Role | Can do |**Reports** has these (what you can open depends on your role): Cheque Register, Daily and Monthly Cheque Reports, Outstanding, Released, Cleared, Bounced, Cancelled & Voided, **Cheque Breakdown**, Receipt Register, Daily/Monthly Receipts, Receipts by Category, Transaction Summary, Printing History, User Activity, Document Uploads.
| Role | Can do |- Set the period, bank account or user at the top. Press **Print / save as PDF**, **Export PDF**, **Export Excel** or **Export CSV**.
| Role | Can do |- **Cheque Breakdown** answers "which cheque paid OR-1234?": type the OR/invoice number, vendor or particulars in the search box and press Enter.
| Role | Can do |- Long reports show the first rows on screen; the totals above include every row. Use **Show all rows** or an export for the rest.
| Role | Can do |
| Role | Can do |### 7.4 Documents
| Role | Can do |**Documents** lists every uploaded file. Search by file name, filter by category or whether it is linked, and **Upload document** for loose files. Records older than the retention period are hidden unless you tick *Include archived* — they are never deleted.
| Role | Can do |
| Role | Can do |---
| Role | Can do |
| Role | Can do |## Part 8 — Management (read-only)
| Role | Can do |
| Role | Can do |You can open Home, Transactions, Reports, the Activity log, and view any cheque, receipt and document. You will not see buttons to create, edit, prepare, print, release, cancel or approve. Exports (CSV/Excel/PDF) work for every report you can open.
| Role | Can do |
| Role | Can do |---
| Role | Can do |
| Role | Can do |## Part 9 — If something goes wrong
| Role | Can do |
| Role | Can do || Problem | What to do |
| Role | Can do ||---|---|
| Role | Can do || "Cheque number already exists for this account" | Check the stock number; open the existing cheque (the link in the message) to see what it is. |
| Role | Can do || Can't press **Prepare** | Read the yellow list on the cheque page: missing payee, amount, date, inactive bank account, or a duplicate number. |
| Role | Can do || Need to change a prepared cheque | Cancel it (not printed yet) or void it (printed) with a reason, then create a new one. |
| Role | Can do || Print came out misaligned | Choose *There was a problem*, then reprint; adjust Shift X/Y or run a **Test print** on plain paper. |
| Role | Can do || Printed but forgot to confirm | Home → *Awaiting confirmation* (or the yellow badge at the top) → **Confirm result**. |
| Role | Can do || Breakdown total is amber | Check rows against the cheque amount; **Use total as cheque amount** if the rows are right. It will still print. |
| Role | Can do || A receipt is missing from "Add from receipts" | It must be *Recorded* or later (not a draft or cancelled), and not already on another cheque. Tick **Show all vendors** if it is a different vendor. |
| Role | Can do || "Access denied" (403) | Your role doesn't allow that screen. Ask an administrator. |
| Role | Can do || Locked out | Wait 15 minutes or ask an administrator to **Unlock**. |
| Role | Can do || Anything else | **Report a problem** (left menu); include what you were doing. |
| Role | Can do |
| Role | Can do |---
| Role | Can do |
| Role | Can do |## Appendix A — Statuses
| Role | Can do |
| Role | Can do |**Cheque:** Draft → Prepared → Printed → Released → For Clearing → Cleared / Bounced / Stale. *Cancelled* (before printing) and *Voided* (after printing) can happen from Draft to Released.
| Role | Can do |**Receipt:** Draft → Recorded → Verified → Processed → Archived. *Cancelled* from any step except Archived.
| Role | Can do |
| Role | Can do |## Appendix B — The one-page daily routine
| Role | Can do |
| Role | Can do |1. **Encoder:** enter/upload receipts → create cheques (add the breakdown) → save drafts.
| Role | Can do |2. **Finance:** check drafts → **Prepare** → verify receipts.
| Role | Can do |3. **Printing:** Print center → preview → print → **confirm** the result.
| Role | Can do |4. **Finance:** after handing over → **Record release** → later **For clearing** → **Record clearing result**.
| Role | Can do |5. **Everyone:** use **Report a problem** for anything odd.
