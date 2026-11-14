# Nightshift report PDF downloads

Implemented locally. No production deployment or migration was performed. Financial calculations, operational shift guards, custody/treasury semantics, stock movement accounting, immutable history and ticket printing remain in their existing services.

## Coverage and export scope

PDF download defaults to **all filtered results across every pagination page**, as requested. `page` remains a navigation parameter and never limits a PDF. Exports are labelled accordingly in the report modal. CSV continues to export all matching pages.

Download PDF / Pakua PDF is available on:

- Every existing report modal: Business Unit Performance; Daily Sales; sales by branch/counter/table/waiter/cashier/product/category; profit/best/slow selling products; waiter outstanding/settlement; cashier/waiter shifts and shortage/excess; opening/closing stock; stock movement/transfer; goods received; breakages, complimentary drinks, staff consumption, returns and voided sales.
- Historical opening/closing stock panels, with their selected business date and operational scope.
- Treasury dashboard, treasury report screens, Cash Handovers and filtered Audit Trail.
- Shift reconciliation/closing sheets, including a download link on the existing print document.
- Existing treasury reports: ledger, cashier receipts, purchases, expenses, wages, bank deposits, custody balance and cashier handover. A read-only Reversals report uses the existing ledger/type query.

`Shift Report` is also supported by the shared report API/PDF endpoint. Overnight reporting retains the existing opening/closing timestamps and operating-date semantics; it does not split shifts at midnight.

## Deliberate exclusions

No PDF was forced onto operational boards or administration forms: Dashboard KPI widgets, POS, live Orders/status boards, tables, waiter settlement/action cards, staff/Business Unit editing, product catalog, pending approvals, shift-opening/count-entry forms, setup and notifications. These combine live state, actions, search results or different accounting sources and are not interchangeable with a report dataset. Use the corresponding sales, waiter, payment, stock or shift report. Existing CSV shortcuts are retained; this work does not mislabel their underlying reports as a snapshot of an operational board.

58mm/80mm tickets, receipt rendering and printer dispatch are unchanged. Dedicated print documents remain available; interactive report actions now explicitly download a file.

## Filter propagation and consistency

The report modal freezes canonical export parameters only when the matching query completes. Request sequencing prevents a slower earlier response from replacing a newer filter result. Export buttons are disabled during loading or failed application. PDF and CSV use the same applied parameter set. Branch/counter/staff selections use stable IDs. Selecting a unit updates the report's branch by **ID**, fixing an old name-based synchronization path.

Server-rendered pages construct download URLs from the applied request. Treasury includes the effective rendered date defaults in its URL so crossing midnight does not silently switch the displayed day. Unsaved form changes are not exported as though they had been applied.

Report controls only advertise fields their existing query supports. Unsupported filter combinations are rejected instead of silently discarded. A Paid/Voided live-board tab is not presented as a report filter. Date, branch, unit, counter, staff, category and product IDs are validated; company overrides and scope mismatches are rejected. PDF metadata additionally rejects supplied filter parameters that a surface cannot apply. Audit keeps its user/action/module/search filters through export.

`ReportService::generate` is the shared source for screen, CSV and PDF rows. PDF gathers all pages without a separate monetary calculation path. Page gathering uses the configured repeatable-read transaction snapshot. Rendering runs after data collection; it does not hold database locks while Chrome generates the document. Treasury gathers its existing summaries and activity in one collection window.

The PDF shows existing source summaries and totals; it does not invent totals by adding unrelated columns or double-count shared stock. Full-history waiter/custody balances remain full-history, with inactive date controls and an explicit scope note. Treasury unit filters still apply only to source activity; available cash and summary totals retain the existing full branch/custodian account scope. The PDF repeats that distinction.

## Authorization and audit safety

Report permission checks run before filter-record lookup, preserving existing 403 behavior. PDF uses the same service policies, tenant/unit scopes and shift authorization as the screen/CSV. Metadata name resolution is also company/branch/unit scoped; an empty filtered result cannot reveal an unauthorized staff name in its header.

Cashier handover downloads remain accessible through the existing custody history permission path after a shift closes. General operational endpoints keep their existing shift middleware. Shift PDFs use the authorized reconciliation result and original unit context, including historical shifts whose counter was later reassigned.

Audit PDF contains only time, actor, friendly action, record and summary. It uses the existing server-redacted presenter and never includes raw old/new payloads. PIN/password/token/recovery/private-key/authorization secrets are not exported.

## PDF layout and filenames

One report layout supplies Nightshift branding, company/currency, localized title and filter summary, generation time in company timezone, generating user, rows, existing summaries, row count and page numbers. Tables repeat headers, wrap long text and use fixed column widths.

Tables with up to seven columns use A4 portrait; wider tables use A4 landscape. Closing sheets retain their existing landscape layout and calculations, with shared PDF metadata and page numbering.

Names are sanitized ASCII slugs with report, selected branch where applicable, date/range and a guaranteed `.pdf` extension. For example: `nightshift-daily-sales-masaki-branch-2026-11-01-to-2026-11-30.pdf` or `nightshift-shift-shf-000125-2026-11-11.pdf`. Labels and page footers follow EN/SW. Stored codes and user-entered names are retained consistently with the existing report sources.

## Runtime requirements and limits

There is no new Composer PDF framework. `ChromiumReportPdfRenderer` uses a small Node/CDP adapter around the same Chrome print engine used for existing print validation. It renders only server-produced HTML, disables user scripts and blocks HTTP/HTTPS/file/FTP document requests. No public URL or user-supplied HTML is accepted.

Runtime requires Node and Chrome/Chromium, plus writable private Laravel storage. Configure absolute executable paths if they are not on the web process PATH:

- `REPORT_PDF_NODE_BINARY`
- `REPORT_PDF_CHROME_BINARY`

Chrome sandboxing is enabled by default. `REPORT_PDF_NO_SANDBOX=1` was used only for the isolated local container validation. Configure a working Chrome sandbox for deployment; no production runtime was changed here. Missing/unavailable rendering returns an explicit error, not HTML or a print-dialog fallback.

Private UUID directories hold temporary input/profile/output files and are cleaned in `finally`. Responses are attachments with private/no-store headers. Generation is synchronous. Defaults are 3,000 rows per primary dataset, a 90-second process timeout and 30MB maximum output. Oversized datasets return a clear validation error asking for narrower filters, without truncation. Defaults are in `config/report_pdf.php`.

Exports preserve applied filters and read the current authorized data at generation time; they are not a historical cache of an earlier screen load if underlying data has since changed. Generation timestamps make that explicit.

## Files changed

New:

- `app/Contracts/ReportPdfRenderer.php`
- `app/Services/ChromiumReportPdfRenderer.php`, `ReportPdfService.php`, `ReportFilterService.php`
- `config/report_pdf.php`
- `resources/views/reports/pdf.blade.php`, `pdf-header.blade.php`
- `resources/js/report-pdf.js`
- `scripts/render-report-pdf.mjs`
- `tests/Feature/ReportPdfTest.php`
- `scripts/browser-report-pdf.mjs`, `browser-report-pdf-filters.mjs`, `browser-report-pdf-final.mjs`, `validate-report-pdfs.mjs`, `report-pdf-layout-smoke.php`

Integrated:

- Nightshift, CashCustody, AuditTrail and ShiftStock controllers; route registration
- Treasury activity pagination and Reversals report mapping in existing services/config
- Workspace report-filter metadata and renderer service binding
- Report modal/history actions and shared JavaScript entry
- Treasury, treasury-report, Cash Handovers, Audit Trail, reconciliation and closing-sheet views
- EN/SW dictionaries, static-page language catalog and a scoped hidden-field style

No migrations or financial model definitions were changed by this task. Earlier uncommitted work is preserved.

## Validation

- Focused report/unit/custody/administration run: **78 passed, 527 assertions**.
- Final Laravel complete suite: **395 passed, 3,937 assertions**.
- Final relevant MySQL 8.4.11 suite: **82 passed, 1,840 assertions**.
- PHPStan: zero errors; formatting, production build/asset verification, frontend tests and seven localization checks passed.
- Main real-browser run: **149 checks**, **84 real report PDFs**, EN/SW, desktop and 390px/412px, actual PDF actions and a rejected Restaurant export by a Bar-only Manager. No runtime exceptions or HTTP 5xx.
- Detailed mobile filter run: **15 checks**, including actual URL propagation for dates, branch, unit, counter, waiter, cashier, product and category, and a saved browser download.
- Final supplementary report-PDF regressions: **10 passed, 77 assertions** after filename/empty-destination presentation refinements.
- Final supported-filter/default-date/metadata-security browser checks: **8 passed**. Total browser checks: **172**, with no runtime exceptions or HTTP 5xx.
- Main PDF validation: **84 files, 94 A4 pages, 12,030 bounded words**, branding/page numbering, redaction and matching Restaurant screen/CSV/PDF amounts (TSh 2,500, excluding Bar's TSh 2,000).
- Additional filter PDFs: **2 files, 2 pages, 131 bounded words**.
- Synthetic long/wide layout stress: **2 files × 500 rows, 84 A4 landscape pages, 29,078 bounded words**. Each of the 500 unique row references appears exactly once in each document.
- Financial reconciliation: all 13 original table fingerprints unchanged (orders/items, payments/allocations, waiter ledger, stocks/movements, treasury, handovers/events, shifts and stock snapshots/counts).

Evidence: `/tmp/pdf-{verified,mysql-verified,scope-final,static-ultimate,format-verified,build-verified,frontend,localization}.log`; `/tmp/pdf-financial-final.json`; `/tmp/pdf-wide-validation.json`; `/tmp/nightshift-report-pdf-browser/`; `/tmp/nightshift-report-pdf-filters/`; `/tmp/nightshift-report-pdf-layout/`.

No production deployment or migration was performed. Physical printer hardware was not exercised.

Temporary local validation web, browser and MySQL services were stopped after these checks.
