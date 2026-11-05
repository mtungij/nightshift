# Shift stock reconciliation

The new closing sheet compares a physical counter count against the existing immutable stock-movement ledger. It does not replace that ledger, alter sales or payments, create waiter liability, or adjust stock to conceal a discrepancy.

## Opening and movement boundaries

Opening a CASHIER shift now creates an immutable `OPENING` snapshot in the same transaction. It contains company/branch/counter/shift IDs, posting user, timestamp, the counter's last committed movement ID, and one row per active product (plus inactive products with remaining stock). Rows capture the base unit, opening quantity, category/name, conversion descriptions, selling price per base unit and purchase cost. Different selling units never become separate stock pools. Reference pricing prefers the explicitly sold base unit, then a one-to-one conversion; only when neither is available is another pack normalized to a base-unit price. Decimal comparisons select fallback packs without floating-point conversion.

Inventory mutations and snapshot/count/finalization use Nightshift's existing company transaction lock. `StockService::change` now also takes that lock when called inside a plain database transaction, so the opening boundary cannot miss a concurrent posting. Movement IDs, rather than ambiguous same-second timestamps or the stock movement's `shift_id`, define the interval. This includes waiter-issued orders, transfers and GRNs posted without a cashier shift reference.

Every committed counter movement after the opening cursor is counted once:

- Positive GRNs, received transfers and other permitted positive additions become Added.
- Negative ISSUE movements become System Sold and stock out.
- All other negative movements, including transfer-out, breakage and complimentary stock, become Other Stock Out.
- RETURN and VOID_REVERSAL positives become Valid Returns, separate from new additions.

Expected closing is `opening + additions - all stock out + valid returns`. It is independently checked against materialized stock balances. Drift blocks finalization and is never silently repaired. Draft orders and uncommitted transactions have no movements and do not enter the calculation.

## Counts, approval and closing

Use **Close cashier shift** or an open cashier shift link on the Shifts page to open `/shift-stock/{id}`. The screen shows payment-method totals, refunds, expected cash and counter waiter outstanding before the stock count. Product search, category and variance-only filters help with the stock sheet. Physical counts start blank; all relevant products must be counted in base units. Quantities are displayed without artificial trailing zeroes, retaining six decimal places internally.

Saving requires the current inventory basis and count revision. The basis is signed with an application-key HMAC, so the digest does not provide an offline guessing oracle for purchase costs hidden from cashiers. This prevents one cashier overwriting another count or saving a count against movements posted since the page loaded. Non-zero variances require a reason. Count edits are audited with before/after values and clear previous approval. A later stock movement makes the saved count and approval stale; the user must reload, verify and save the count again.

Variance is `physical - expected`: Balanced/Sawa, Short/Pungufu or Over/Ziada. Selling-value exposure is `abs(variance) × opening base-unit selling price`; separate cost impact is retained in snapshots. Cashiers cannot retrieve purchase costs or cost impacts through JSON or page data. Existing inventory/report permissions control cost visibility.

Closing requires a complete current count. Non-zero stock variances require a reviewer with both existing `override-shifts` and `view-staff-operations` permissions. Ordinary cashiers cannot approve. Default managers/owners can review and approve within existing branch/company boundaries. Default supervisors may review their branch but do not gain override or staff-management permissions. Editing another cashier's count follows the existing `manage-users` requirement used by shift closing.

The existing cash-close calculation and cash-shortage/excess note rule still run. Expected cash uses opening cash plus signed Cash payments, including refunds. M-Pesa, Airtel Money, Mixx by Yas, Card and Bank remain separate; Credit promises are not received money. Waiter debt is shown independently and never restores issued stock.

Finalization and shift closing commit together. A unique immutable `CLOSING` snapshot stores the complete report, prices, quantities, count user/time, reason, approval identity/time/note, cash/payment breakdown, waiter outstanding and close identity/time. It is protected against model and raw SQL updates/deletes by the same immutable-history approach used elsewhere. Subsequent catalog or stock changes do not recalculate a finalized report.

No automatic adjustment is posted. A correction must be separately investigated and processed using the existing stock-adjustment/approval or legitimate receiving/transfer workflows. Approving a variance acknowledges it; it does not make it zero.

## Values and report scope

The traditional sheet's physical-depletion estimate remains `(opening + additions - physical closing) × frozen base-unit selling price`. This is a reference estimate, not posted revenue or assumed cash. Returns, breakages, transfers and other non-sale movements are shown separately. Bulk discounts and price changes mean physical estimates need not equal invoice or payment totals; those accounting totals remain unchanged.

Counter inventory includes all activity during that shift's interval, regardless of which cashier or waiter posted it. Payment totals belong to the selected cashier shift. Counter waiter outstanding is the current counter liability at the closing cutoff. Where existing cashier shifts overlap at a counter, their inventory windows can overlap; these sheets must not be summed as accounting sales reports.

Historical views and print routes remain available after a cashier closes their operational shift, under the same shift policy. Waiters cannot access full counter reconciliation. Cross-company, unassigned-branch and other-cashier IDs are rejected server-side.

The printable closing sheet uses white/black A4 landscape pages, repeating table headers and natural pagination. English and simple Tanzanian Kiswahili are supported. Existing 58mm/80mm order tickets and reprint logic are unchanged.

## Deployment limitation

No production deployment or production migration was performed. Pre-feature shifts have no trustworthy instantaneous opening cursor. The feature refuses to fabricate or silently reconstruct an opening snapshot. Plan a supervised cutover: close existing cashier shifts using the old version before deploying the migration/application, then open new shifts. Earlier closed shifts retain their existing history but do not acquire invented reconciliation sheets. This is explicitly reported as unavailable rather than showing reconstructed quantities as historical fact.

## Files changed

- Migration: `database/migrations/2026_11_06_000001_create_shift_stock_reconciliation.php` creates `shift_stock_snapshots` and `shift_stock_counts`, tenant/source foreign keys, unique snapshot/count keys and two immutable-history triggers.
- Models/services: `app/Models/ShiftStockSnapshot.php`, `app/Models/ShiftStockCount.php`, `app/Services/ShiftStockService.php`, hooks in `app/Services/ShiftService.php` and the serialization lock in `app/Services/StockService.php`.
- HTTP: `app/Http/Controllers/ShiftStockController.php`, command dispatch in `NightshiftController.php`, validation in `NightshiftCommandRequest.php`, `routes/web.php`.
- UI/report: `resources/views/shift-stock.blade.php`, `shift-stock-sheet.blade.php`, `resources/js/shift-stock.js`, imports/navigation in `app.js` and `nightshift.js`, `resources/css/nightshift.css`.
- Localization: `lang/en/ui.php`, `lang/sw/ui.php`, `lang/sw.json`.
- New tests/helper: `tests/Feature/ShiftStockReconciliationTest.php`, `tests/Support/CompletesStockCount.php`.
- Existing close scenarios updated to supply required counts: `NightshiftWorkflowTest.php`, `OperationalShiftRoutingTest.php`, `StaffDataAuthorizationTest.php`, `WhatsAppAfterCommitTest.php`, `WhatsAppIntegrationTest.php`.
- Schema/concurrency coverage: `tests/Unit/MySqlSchemaCompatibilityTest.php`, `tests/MySql/DatabaseCompatibilityTest.php`, `ConcurrencyTest.php`, `transaction-worker.php`.
- `tests/Feature/SalesTicketPrintingTest.php`: existing page-break assertion accepts CSS whitespace; order-ticket layout was not changed.
- Browser tools: `scripts/shift-stock-browser-fixtures.php`, `browser-shift-stock-smoke.mjs`, `shift-stock-browser-verify.php`, `validate-shift-stock-pdfs.mjs`.
- Report: `docs/shift-stock-reconciliation.md`.

## Validation

- Focused reconciliation suite: **20 tests / 105 assertions passed**.
- Full Laravel suite: **274 tests / 2,782 assertions passed**.
- PHPStan: **0 errors**. Pint, frontend formatting, production frontend build and whitespace checks passed.
- Desktop/mobile Chrome: **35 checks passed**, EN/SW, light/dark, blank required counts, filtering, unpaid waiter stock-out, cash-method separation, manager approval, final read-only views and printable reports; zero runtime exceptions or HTTP 5xx.
- PDF checks: **2 PDFs / 4 A4 landscape pages / 803 checks passed**, including extracted totals, outstanding, variance and all text bounding boxes inside page limits.
- Read-only stock/ledger verification: both counters still match the movement ledger. Counts before/after closing remain **68 stock movements / 5 waiter-ledger entries / 1 payment** in the isolated fixture database. The shortage remains visible without a corrective stock movement or payment.

Evidence: `/tmp/nightshift-reconciliation-browser/result.json`, `/tmp/nightshift-recon-pdf-result.json`, `/tmp/nightshift-recon-stock-ledger.json`. PDFs and screenshots are in `/tmp/nightshift-reconciliation-browser/`. Physical printers were not connected; validate the target driver's A4 landscape margins before operational use.
