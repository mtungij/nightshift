# Business Units validation

Validated locally on 2026-11-10. No production deployment or production migration was performed.

## Automated results

Final runs include the staff-status/access-refresh and cross-unit physical reconciliation regressions. Earlier exploratory failures were corrected before these runs: terminal branch compatibility, a long MySQL primary-key identifier, actor-bound selection, Blade spacing, a zero row from another unit in filtered performance, raw default-price formatting and report print overflow.

- Formatting: Pint and `git diff --check` passed.
- Static analysis: PHPStan passed with zero errors; this environment needs `--memory-limit=1G` and access to its local worker socket.
- Production frontend build and manifest/assets verification: passed.
- Frontend tests: passed.
- Localization: all 7 checks passed.
- Laravel complete suite: **375 passed, 3,664 assertions**.
- MySQL 8.4.11 complete suite: **411 passed, 5,357 assertions**, including all 33 concurrency cases.
- Feature coverage: 29 Business Unit cases, including ownership/CRUD permissions, General initialization, Bar/Restaurant isolation, multi-unit users, shared product availability and price fallback, historical price/context stability, counters, shared locations, transfer endpoint privacy, terminals, waiter/cashier shifts, My Orders, report filters, custody source context, cross-company/branch tampering, assignment protection, immutable context and EN/SW rendering.
- Added concurrency coverage: complete assignment sets, price changes versus POS send, availability versus POS send, unit deactivation versus opening, counter reassignment versus opening, and competing cross-unit transfers. The complete MySQL suite includes the earlier shift/payment/treasury cases too, plus terminal deactivation versus an already authenticated POS request, for 33 concurrency tests total.
- Final cutover regressions also verify pending stock adjustments and confirmed but unconsumed next-cashier handovers block location reassignment.

All database tests use disposable validation databases. The MySQL test harness rebuilds its explicitly named validation database and does not roll back forward-only financial history migrations.

## Browser results

Seven browser runners passed **79 checks** with no JavaScript exceptions or HTTP 5xx responses in their final successful runs:

- Main workflow: 26 checks. Desktop English Bar terminal and mobile Kiswahili Restaurant terminal; only eligible unit waiters/counters; PIN-confirmed order issuance; same company product sold for TSh 2,000 in Bar and TSh 2,500 in Restaurant; own-unit cashier payment; rejected cross-unit counter/waiter IDs; Bar-only Manager isolation; multi-unit Manager consolidated and selected-unit reporting; Owner management access.
- Management/report review: 13 checks. Real UI unit creation, editing, safe idle deactivation, product availability disable/re-enable, blocked disabled-product order, separated company/override prices, shared-source private-transfer isolation, report unit filter and CSV, mobile selector bounds.
- Report review: 6 checks. EN/SW reports retain all eight columns and use stable counter IDs; no runtime/server errors.
- Operational context review: 12 checks. Stable POS counter IDs, waiter modal scope in both units, real UI send/receipt from shared central store to Bar, stock conservation, rejected Restaurant transfer, and real staff multi-unit assignment/restoration forms.

The separate browser database was `nightshift_treasury_browser_validation` on local port 3340. It contained the earlier custody validation fixtures before the unit migration. No test-suite refresh was run against it.

- Staff status: 7 checks. Owner disables/reactivates a terminal; unsafe unit deactivation gives a clear blocker and leaves the unit active; the terminal retains its unit scope.
- Branch assignments: 8 checks. Real staff branch/unit editing, branch-aware unit selection and immediate backend rejection after access removal.
- Language controls: 7 checks. Both new static screens switch EN/SW through their real language controls; the product editor excludes Restaurant data for a Bar-only Manager.

## Legacy migration and reconciliation

Applying the unit migrations to that existing browser database preserved the original counts and amounts: five orders, three payments, seven waiter-ledger entries, ten treasury entries and TSh 1,320,000 in recorded payments. Existing operational records were attributed to General; no Bar or Restaurant recipients/history were invented.

The two new browser invoices total **TSh 4,500**, both paid. Their waiter debits and credits balance. Their immutable contexts point to the correct unit and counter. Treasury entries remain unchanged at ten. A single company product master supplies both prices.

Stock balances match stock movement sums at every tested location. The shared product totals **98 base units** after receiving 100 and issuing two. The additional shared-store → Bar transfer changes location quantities while preserving that total. There are no duplicate inventory receipts or sales/payments from configuration or transfer actions.

## Reports and PDF

The selected Restaurant performance report and CSV include only Restaurant's TSh 2,500 sale/cash receipt. Both EN and SW PDFs are A4 landscape with all eight columns present. Two files/two pages passed 70 word-bound checks plus required headers/amount/unit checks. A real paid/closed Bar shift was also tested after its counter moved to Restaurant: old sales/counter labels and frozen stock history remained available, inherited stock stayed correct, and a later void/refund reconciled the new Restaurant drawer and stock without changing old prices. This regression passed on SQLite and MySQL.

The original portrait preview clipped the final columns; the final landscape/wrapping layout corrected that. Physical printer hardware was not tested.

## Local evidence

Temporary artifacts remain local and can be removed after review:

- `/tmp/bu-laravel-release-final.log`, `/tmp/bu-laravel-release-final-events.log`: final complete Laravel run.
- `/tmp/bu-mysql-release-final.log`, `/tmp/bu-mysql-release-final-events.log`: final complete MySQL run.
- `/tmp/bu-mysql-focus.log`: initial added concurrency/schema checks (9 passed, 1,370 assertions).
- `/tmp/bu-mysql-final-targeted.log`: 33 final hierarchy/schema/access-race cases passed (1,416 assertions).
- `/tmp/bu-mysql-physical-regression.log`: late cross-unit historical refund/physical reconciliation passed (1 test, 14 assertions).
- `/tmp/bu-domain-ready.log`: 29 feature cases passed before final cutover guard extensions.
- `/tmp/bu-cutover-guards.log`: final pending-adjustment and received-cash cutover guards (2 passed, 8 assertions).
- `/tmp/bu-accounting-static.log`, `/tmp/bu-format-final-result.log`, `/tmp/bu-build-completed.log`, `/tmp/bu-frontend-final-result.log`, `/tmp/bu-localization-final-result.log`.
- `/tmp/bu-reconciliation-release-final.json`: final reconciliation after all browser scenarios, including unchanged legacy General attribution.
- `/tmp/bu-pdf-final.json`: complete EN/SW PDF validation.
- `/tmp/nightshift-business-unit-browser/{result,review-result,reports-result,context-result,status-result,branches-result,locales-result}.json`: 79 final browser checks.
- `/tmp/nightshift-business-unit-browser/`: screenshots, filtered CSV, EN/SW report PDFs and initial screen printouts. Use `unit-report-en.pdf` and `unit-report-sw.pdf` for final report output.

See [architecture, changed files and cutover instructions](business-units.md) for migration safety, permissions, access defaults and limitations. In particular, the existing one-active-shift-per-employee and one-cashier-per-counter rules remain in force; multi-unit assignment does not introduce simultaneous waiter shifts.
