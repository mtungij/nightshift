# Cash handover and treasury

Implemented locally; not deployed to production. This extends the operational shift lifecycle without replacing sales, waiter debt, stock, bank, or supplier accounting.

## Cashier workflow

Normal cashier opening cash is zero. The ordinary opening form has no editable cash amount. A confirmed incoming handover for the same company, branch, counter and named cashier supplies opening cash automatically and can be consumed only once. The recipient can confirm and open together. Disputed receipts require a reason and authorized approval; both expected and received amounts remain visible. A manager opening on someone else's behalf cannot attest that person's unconfirmed receipt.

Exceptional external opening cash requires `authorize-opening-cash` and a meaningful reason, with an audit record. Existing historical floats are not reset.

Closing retains all existing waiter, debt, cash reconciliation, stock count and variance approval blockers. The shift summary includes sales/payment methods, waiter settlements/outstanding, expected/actual cash, stock variance and custody information. Positive counted cash must be assigned to Next Cashier or Accountant / Admin. Zero cash can close with a no-cash disposition.

Staging a handover freezes further drawer activity and count changes until it is closed or cancelled. A next-cashier transfer may await receipt after source closure, but cannot fund the next opening until confirmed or its discrepancy approved. A treasury transfer must be confirmed/approved before source final closure. Treasury receives spendable funds atomically with source closure, so confirming an open drawer does not create spendable money early. A replacement handover for already closed custody credits treasury when its recipient confirms.

## Accountant and permissions

Accountant is a dedicated restricted staff role with treasury viewing, receiving, purchases, expenses, wages and bank deposit permissions. It gains no general administration permission. Accountant login opens Treasury. Owner and Super Admin have treasury functions and oversight; a separate Accountant account is optional. Manager can review custody, approve differences and manage authorized handovers without automatically gaining treasury spending rights.

Cashiers see their own outgoing/incoming custody records and assigned counters. Waiters and POS Terminals cannot access treasury. All commands and reports enforce active-company membership, permission, branch and recipient/counter scope. Company-wide oversight is a separate permission. Custodian balances remain separate by company and branch.

## Immutable custody

`cash_handovers` stores original destination, source, expected amount and lineage. `cash_handover_events` records receipt, discrepancy, approval, cancellation and correction without overwriting history. `treasury_entries` records positive decimal amounts with IN/OUT direction, type, company, branch, custodian, reference, actor, description and timestamp. Balances are derived from entries; there is no mutable balance column. Model guards and database UPDATE/DELETE triggers protect all three tables.

Closed shift financial snapshots stay frozen. Their original cash disposition remains in that snapshot; live custody state separately shows later receipt and correction events. Sales are not posted again when custody changes.

Transactions serialize through the existing company row lock and lock relevant shifts/handovers. Unique constraints protect handover receipts, consumed opening transfers, reversals and business references. Request idempotency protects repeat commands. Concurrent spends recheck the derived balance under lock, and cannot drive it negative.

## Spending and corrections

- Purchases link an existing goods receipt and cap cumulative unreversed cash payments at its value. They do not receive stock a second time or create POS payments.
- Expenses require category, description, amount and reference. Available categories include transport, electricity, water, cleaning, security, maintenance, petty cash and other.
- Wages require employee, period, amount and reference. This is cash payment tracking, not a payroll engine.
- Bank deposits require bank and reference details. They reduce physical treasury cash without inventing a bank account balance.

All uses require the relevant permission and sufficient funds in the selected custodian/branch account. Authorized reversals preserve originals and create opposite entries with a reason. Reversing an outflow represents physically returned cash. Reversing a treasury receipt debits its original custodian and creates a replacement handover to a specified recipient; the new recipient must confirm. A reversal cannot be reversed or repeated. Cancelling a closed pending handover requires a replacement recipient so the cash remains accounted for.

## Screens and reports

Treasury shows current available balances, received cash, purchases, expenses, wages, deposits, pending receipts and recent transactions. Date filters apply to transactions and period totals; available balances include the full history. Branch filters respect permissions.

Eight reports are available at `/treasury/report`, with CSV and browser printing: Cashier Handover, Treasury Ledger, Cash Received from Cashiers, Cash Used for Purchases, Expenses, Salaries and Wages, Bank Deposits and Cash Custody Balance. The existing report API also accepts Salaries/Wages as an alias. Shift views and PDFs include destination, recipient, amount, receipt status and discrepancy. English and Tanzanian Kiswahili are supported.

## Files

Core additions: `config/treasury.php`; `app/Models/{CashHandover,CashHandoverEvent,TreasuryEntry}.php`; `app/Services/{CashHandoverService,TreasuryService,CashCustodyReportService}.php`; `app/Http/Controllers/CashCustodyController.php`; `app/Http/Requests/CashCustodyRequest.php`.

Integration changes: shift opening/closing services, stock/reconciliation guards, report service/controller, operational routing/login response, role/business configuration, routes, demo seeder and existing shift workflow fixtures/tests. Previous operational-shift work in this workspace is preserved.

UI additions: `resources/js/cash-custody.js`; treasury, treasury report/print and cash-handover views; custody/ledger partials. Changes also cover opening/shift stock views, existing app/shift JavaScript, responsive CSS and EN/SW dictionaries.

Validation additions: `tests/Feature/CashCustodyTest.php`, `tests/Support/CompletesCashDisposition.php`, MySQL concurrency workers/cases and schema assertions; `scripts/{treasury-browser-fixtures.php,browser-treasury-smoke.mjs,treasury-browser-verify.php,validate-treasury-pdfs.mjs}`. See the separate validation report for results.

## Migration and cutover

New migration: `database/migrations/2026_11_10_000002_create_cash_custody.php`. It depends on the existing operational shift migration `2026_11_10_000001_link_operational_shifts.php`. It adds tables, composite tenant foreign keys, uniqueness, immutable triggers, new permissions and Accountant role. Existing roles receive the new intended permissions without replacing their prior grants.

The migration is forward-only: `down()` refuses rollback. Back up before applying; recovery requires restoring a backup or a reviewed forward correction. Use a migration account able to create the required triggers. Run the normal deployment checks and migration in the intended environment only after review; this task has not deployed anything.

No historical handovers, recipients or treasury balances are invented. Historical closed shifts remain valid and show legacy/no custody record. Existing open shifts must satisfy the new cash disposition requirement at their next close. Review role assignments, branches and intended cash recipients before cutover, and explain the new receipt step to cashiers and custodians.

## Limits

Purchases use existing goods receipts; this does not introduce supplier debt management. Wages do not calculate payroll or store sensitive payroll credentials. Bank deposits do not establish bank balances. Browser/PDF output was tested; physical printers were not exercised. Treasury credit waits for both receipt confirmation and source final close. A real physical cash transfer or returned-cash reversal still depends on staff recording what actually happened.
