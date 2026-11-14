# Operational shift report filtering

Implemented locally; no production deployment or history backfill.

## Scope and attribution

Branch → Business Unit → Counter → Shift. The shared picker requests at most 25 authorized shifts, supports number/employee search and cursor paging, and pins an authorized selection outside the recent page. Labels and a wrapping summary show employee, branch, saved Business Unit, counter, opening/closing times and status. Dependent changes clear the selected shift and incompatible location choices. Hidden controls for unsupported report filters remain disabled.

Specific shifts are authoritative: conflicting dates are ignored and the UI disables dates. All Shifts restores existing date behavior, including existing all-history current-balance reports. Overnight shifts remain intact; no midnight boundary is introduced. Report state survives pagination, language/theme changes, reopening, and browser back/forward within the authenticated workspace. State is retained per report in the tab's session storage; it does not grant access or bypass server validation.

Supported reports:

- Daily Sales; Order Report; Paid Orders; Unpaid Orders; sales by waiter, cashier, product, category, table, counter and branch; best/slow selling products; Profit Report; Business Unit Performance.
- Payments and Payment Method Report; Waiter Outstanding and Waiter Settlement.
- Shift Report, Cashier Shift, Waiter Shift, Cash Shortage / Excess.
- Opening Stock, Closing Stock, Stock Movement and the existing dedicated Shift Stock Reconciliation screen/PDF.
- Returns, using the return's processing cashier shift or its order's waiter session.
- Cashier Handover and Cash Received from Cashiers, using the actual source cashier shift.
- Audit trail, for events referencing the shift or its linked orders, payments, handovers, cashier treasury receipts and stock movements. Unrelated events remain available without a shift filter.

Cashier sales use `orders.issued_shift_id`; waiter sales use `orders.shift_id`. Cashier collections use `payments.shift_id`, independently of who issued the order. Waiter collections use order/payment allocation relationships and include only the selected waiter session's share of multi-order payments. No counter/time overlap is used to guess ownership when multiple cashiers are active.

Closed sales reconstruct mutable paid/balance/returned projections as of close using posted payment allocations (or direct order-linked legacy payments), returns and approved void decisions. Product quantities use returns posted by close. Later collections, returns, voids and other shifts cannot change that closed projection. Open reports remain live. Opening/closing stock reuse the existing opening/final reconciliation snapshots; legacy shifts without an opening snapshot retain the supervised-cutover blocker. Shared-counter stock snapshots remain counter-wide and must not be added across concurrent cashier shifts.

Treasury receipts can legitimately arrive after the cashier closes: source-shift filtering includes those actually linked receipts, rather than pretending later unrelated spending belongs to the shift. Audit views show the linked reference history, including later events on that reference.

## Intentionally excluded

Treasury Ledger, Cash Custody Balance, Expenses, Purchases, Wages, Bank Deposits and Reversals do not acquire an inferred shift. GRNs, transfers, breakage/complimentary/staff-consumption documents and Voided Sales retain existing date/Business Unit reports without a fabricated shift link. Stock Movement supports a shift only for rows that actually store it; unassigned GRN/transfer/adjustment movements stay under All Shifts. Legacy sales without the appropriate stored shift relationship remain in general/date reports and are excluded from specific shifts. There are no migrations or financial-history repairs.

## Exports and authorization

Screen, PDF and CSV call the same report service with the same filters. PDF/CSV export all matching pages. CSV pagination now runs inside a database transaction, like PDF collection. Existing PDF size limits and CSV formula escaping remain. Filenames include the selected shift number; PDFs print full context and the date-scope notice. Each new live request reflects current committed activity; separately generated live screen/PDF/CSV files may differ if transactions arrive between requests.

Picker and export resolution enforce active company/user, existing report permissions, company, assigned branches, immutable saved Business Unit, configured counters, staff visibility and report-specific cashier restrictions. Treasury recipients retain only their existing receipt/handover access. Changing IDs cannot broaden these rules. Historical location names come from final snapshots where available; an authorized shift's fallback lookup remains company-bound.

## Main files

Backend: `ReportShiftService`, `ReportShiftController`, `ReportService`, `ReportFilterService`, `ReportPdfService`, `CashCustodyReportService`, `AuditTrailService`, report/audit/custody controllers, `WorkspaceService` (authorized report counter options independent of POS selection), `config/report_pdf.php`, `routes/web.php`.

UI: `resources/js/report-shifts.js`, `resources/js/nightshift.js`, `resources/css/nightshift.css`, `partials/report-shift-filter.blade.php`, audit/treasury report views and EN/SW dictionaries. Existing dashboard shell, controls, typography and themes are reused.

Validation: `tests/Feature/ReportShiftTest.php`, `scripts/report-shift-browser-fixtures.php`, `scripts/browser-report-shifts.mjs`, `scripts/report-shift-browser-verify.php`. Fixtures fail closed outside the named disposable validation database. They create actual operational transactions on a new counter/product and hash-check pre-existing financial, stock and shift history.

## Completed validation (11 November 2026)

- Formatting: Laravel Pint and focused JavaScript formatting checks pass. PHPStan: zero errors. Production frontend build and artifact verification pass. Existing frontend tests: 3/3 pass.
- Full Laravel suite, run in bounded groups and with the reporting group refreshed after the final security test: **441 tests / 4,189 assertions pass**. A further final workspace/report regression run passes **117 tests / 1,146 assertions**.
- MySQL **8.4.11**: **113 unique relevant cases** passed across regression/concurrency and final focused runs. The broader run's 77 non-report cases passed; its two early report failures were corrected and superseded by the final report/PDF run (**23 tests / 150 assertions**). Final Business Unit/staff visibility/routing run: **43 tests / 307 assertions**, including 30 overlapping Business Unit cases. Existing concurrency tests are included in the broader run.
- Browser: **60/60** responsive/report checks plus **4/4** workspace/security checks pass, with no JavaScript exceptions or server errors. Viewports: 390, 412, 768, 1440, 1920 pixels; EN/SW; light/dark. Search, hierarchy, date disabling, selection persistence, back/forward, dedicated treasury/audit screens and actual exports were checked. The audit check waits for the shared dashboard shell to reveal the page after asynchronous mounting.
- Real Chromium PDFs and CSV: closed shift contains exactly its two before/after-midnight invoices totaling **2,000**; the subsequent live shift contains one separate invoice totaling **1,000**. PDF text extraction confirms the exact invoice sets; filenames contain the shift numbers. Automated tests also export 105 matching orders across screen pagination.
- Reconciliation: selected closed sales **2,000**, cashier collections **2,000**, treasury source receipt **2,000**; opening stock **10**, frozen counter closing stock **7**, later live physical stock **6**; closed waiter debt **0**. Hashes confirm **13 pre-existing financial/stock/shift history tables are unchanged**.

Reusable additional checks: `scripts/browser-report-shift-scope.mjs` and `scripts/validate-report-shift-exports.mjs`. Local evidence is summarized in `/tmp/nightshift-report-shift-validation-summary.json`; browser screenshots, PDFs and results are in `/tmp/nightshift-report-shift-browser/`. Validation artifacts are disposable and are not production data. No production deployment was performed.
