# Cash handover / treasury validation

Validation date: 2026-11-10. Local implementation only; no production deployment.

## Automated checks

Final results are recorded below. The full suites include existing POS, authentication, terminal, My Orders, stock, shifts, waiter debt, reports, tenant isolation and WhatsApp tests as well as the custody additions.

- Formatting: Pint passed; `git diff --check` passed.
- Static analysis: PHPStan passed, zero errors, with `--memory-limit=1G`. This environment requires local worker socket access and more than its default 128 MB memory.
- Production frontend build: passed, including manifest/assets verification.
- Frontend tests: passed.
- Localization: all 7 checks passed.
- Laravel full suite: **346 passed, 3,557 assertions** on the final clean run.
- MySQL 8.4.11 full suite: **375 executed, 374 passed, 5,037 assertions**. Its sole failure was the EN/SW catalog mismatch fixed while that run was in progress. The final MySQL rerun of all 8 localization tests passed with 64 assertions. All 26 concurrency tests and all 3 actual-database compatibility tests passed in the complete run. No unresolved test failures remain; the full MySQL run was not repeated again after the catalog-only correction.

The custody feature class covers default/authorized opening cash, receipt lineage, discrepancy approval, immutable history, tenant/branch/counter authorization, spending and reversals, historical snapshots and reports/localization. Existing workflow fixtures now explicitly satisfy the real cash disposition requirement.

The MySQL suite includes 26 concurrency tests, including duplicate receipt, simultaneous spend, duplicate business references/purchase payment, repeated close/open and staged handover interactions. The concurrency harness rebuilds only its dedicated validation database; it does not attempt rollback through forward-only financial history migrations. The actual MySQL schema audit expects 22 immutable-history triggers and validates decimal precision, engines, indexes and session settings.

## Browser scenarios

A dedicated MySQL database `nightshift_treasury_browser_validation` was used separately from the automated suite's `nightshift_validation`. Neither is the production database. Synthetic accounts and fixtures were used.

The main browser workflow passed 56 checks with no JavaScript exceptions or HTTP 5xx responses:

1. Desktop English: real cashier sale/settlement; opening zero; cashier-to-next-cashier pending handover; named recipient confirmation and automatic opening of TSh 650,000; no arbitrary cash input; frozen prior shift financial snapshot; next cashier stages treasury handover; Accountant confirms; funds unavailable until source closes; treasury then receives TSh 650,000.
2. Mobile Kiswahili: cashier-to-Accountant handover expected TSh 650,000; Accountant reports TSh 645,000 with reason; Owner approves the TSh -5,000 discrepancy; source closes; treasury receives TSh 645,000.
3. Both scenarios: purchase TSh 200,000, expense TSh 50,000, wage TSh 150,000 and bank deposit TSh 100,000; overspending rejected; reports accessible.

A final read-only pass verified all eight HTML report screens in both languages, header button bounds at 1440 px and 390 px, and no runtime/server errors: 20 additional checks passed. Screenshots were refreshed after the responsive layout fix.

## Reconciliation and PDF

Reconciliation passed: desktop balance TSh 150,000; mobile balance TSh 145,000; ten treasury ledger movements. POS orders, payments and waiter ledger counts/totals were unchanged by treasury spending. Stock matched movement totals. Purchase payment did not receive stock twice. Confirmed handover lineage supplied opening cash. The original discrepancy remained visible.

Four shift/treasury PDFs passed 1,290 checks across six A4 landscape pages, including text within page bounds and required totals. Physical printer hardware was not tested.

## Local evidence

Artifacts are local temporary files, not committed fixtures or production data:

- `/tmp/treasury-laravel-complete.log`: final clean Laravel suite.
- `/tmp/treasury-mysql-release.log` and `/tmp/treasury-mysql-release-events.log`: final full MySQL run.
- `/tmp/treasury-mysql-language-final.log`: final MySQL localization rerun (8 passed, 64 assertions).
- `/tmp/treasury-mysql-final-focused.log`: final focused MySQL schema/report/immutability checks (5 tests, 1,212 assertions passed).
- `/tmp/treasury-static-release.log`, `/tmp/treasury-format-release.log`, `/tmp/treasury-build-final.log`, `/tmp/treasury-frontend-release.log`, `/tmp/treasury-localization-release.log`.
- `/tmp/nightshift-treasury-browser/result.json`: 56 workflow checks.
- `/tmp/nightshift-treasury-browser/responsive-reports.json`: final 20 read-only checks.
- `/tmp/treasury-reconciliation.json`: database reconciliation.
- `/tmp/treasury-pdfs.log`: PDF checks.
- `/tmp/nightshift-treasury-browser/`: PDFs and desktop/mobile treasury, shift and report screenshots.

The initial final MySQL pass had 374/375 passing because the schema test still expected 16 triggers rather than the new 22. The assertion was corrected; focused checks passed and the complete suite was rerun. That rerun found only the new Filter catalog-key mismatch; it was corrected and all 8 localization tests then passed on both SQLite and MySQL. An earlier harness rollback issue was fixed by keeping the concurrency test teardown compatible with forward-only migrations.

See [implementation and cutover notes](cash-handover-treasury.md) for files, permission changes, workflow rules, migration safety and remaining limits.
