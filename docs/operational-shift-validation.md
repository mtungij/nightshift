# Operational shift lifecycle implementation and validation

No production deployment or production migration was performed.

## Behavior

- One open cashier operation per counter. Company transaction locking covers both
  self-open and manager-open requests, final close and subsequent handover.
- Cashiers own their operations even when a manager opens them. Opening actor,
  counter, branch, opening cash, stock snapshot and audit attribution are retained.
- Waiters have independent sessions beneath the active cashier operation. Multiple
  waiters can work together or rotate. A waiter cannot overlap their own sessions,
  reopen with debt or pending settlement conditions, or close with positive debt.
- Cashier close identifies open waiters and debtors by name. All must be clean
  before cash and physical counts can be saved. Final close additionally requires
  current complete stock counts, a matching stock ledger, required stock-variance
  approval, recorded actual cash and an explanation for any cash variance.
- Receipts, waiter session changes and stock movements invalidate saved counts.
  Editing actual cash requires a new reconciliation save. Finalization freezes
  the closing report; closed shift models reject edits and deletion.
- Midnight has no lifecycle action. Operating date comes from the opening date in
  Africa/Dar_es_Salaam. The regression scenario from 18:00 to 03:00 remains one
  operation with a 540-minute duration and one frozen report.
- Reports include the cashier, opening actor, participating waiters, timestamps,
  duration, operating date, sales, payment channels, settlements, cash and stock
  reconciliation and approvals. Explicit `shift_id` report/CSV queries ignore
  calendar boundaries while retaining existing access checks.
- Shared POS Terminal keeps waiter selection and PIN verification. Orders retain
  the waiter session in `shift_id`; immediate issuance and stock-out use the
  cashier operation in `issued_shift_id`. No terminal-owned session or second
  ledger path is created. Counter stock postings without an explicit historical
  shift inherit the current cashier operation.
- Existing branch/tenant scopes, permission gates, posting services, receipts,
  return/void adjustments, WhatsApp and audit paths remain in use.

## Migration and rollout

`2026_11_10_000001_link_operational_shifts.php` adds nullable cashier-session and
opening-actor references with company-composite foreign keys and a counter lookup
index. It is forward-only and does not backfill history.

Read [the supervised cutover requirements](operational-shift-lifecycle.md) before
planning rollout. Existing overlapping operations or legacy shifts without opening
snapshots require supervised resolution. No generic legacy-history repair command
or fabricated snapshot is included. Existing reconciliation counts must be saved
again because their concurrency basis now includes receipts and waiter sessions.
The count command now requires `actual_cash`; the included frontend sends it.

## Validation results

| Validation | Result |
| --- | --- |
| Laravel full suite (`php artisan test --compact`) | 317 passed; 3377 assertions |
| MySQL 8.4 full suite | 339 passed; 4643 assertions |
| Final MySQL stock-attribution / legacy-ambiguity regressions | 2 passed; 23 assertions |
| PHP formatting | Pint passes |
| Static analysis | PHPStan passes, zero errors |
| Frontend regression | 1 passed |
| Localization checks | 7 passed |
| Production frontend build | passes, including asset verification |
| Desktop EN / mobile SW browser | 59 checks pass; no runtime exceptions or HTTP 5xx |
| PDF / print | 2 PDFs, 4 A4 landscape pages; 921 checks pass |
| Stock / ledger reconciliation | 2 counter operations verified; stock matches movement ledger; variance approval did not alter stock |

The MySQL full suite includes 19 real multi-process concurrency tests and three
MySQL schema/constraint tests, in addition to the Laravel unit and feature suites.
It covers cashier and manager opening contention, duplicate close requests with
both identical and distinct idempotency keys, settlement versus waiter close,
waiter orders versus waiter/cashier close, stock movements versus finalization,
and a new cashier opening during final close.

Browser cases use two simultaneous waiter sessions, a rotating third waiter,
opening timestamps on the preceding operating date, genuine settlement postings,
cash reconciliation, physical stock count, mobile stock-variance approval and a
new cashier opening only after final close. Desktop cash and stock balance;
mobile verifies a TSh 10 cash shortage with notes and an approved TSh 6,000 stock
variance. Both cases finish with zero waiter debt. Frozen reports compare equal
after handover.

Validation used temporary MySQL **8.4.11** on loopback port 3340, strict SQL mode,
Africa/Dar_es_Salaam session offset (+03:00), and REPEATABLE READ. Unit/feature and
concurrency tests used `nightshift_validation`; browser scenarios used a separate
`nightshift_browser_validation` database. Binary logging was disabled only on the
temporary instance, and its flush mode was set to 2 for disposable test throughput.
The installed server's global security settings were not changed. Physical printer
hardware was not exercised; browser printing and generated PDFs were validated.

Local evidence:

- `/tmp/shift-laravel-reviewed.log`
- `/tmp/shift-mysql-complete.log`
- `/tmp/shift-mysql-final-events.log`
- `/tmp/shift-mysql-legacy-final.log`
- `/tmp/shift-static-reviewed.log`
- `/tmp/nightshift-reconciliation-browser/result.json`
- `/tmp/nightshift-reconciliation-browser/desktop-final.png`
- `/tmp/nightshift-reconciliation-browser/mobile-final.png`
- `/tmp/nightshift-reconciliation-browser/desktop.pdf`
- `/tmp/nightshift-reconciliation-browser/mobile.pdf`
- `/tmp/shift-reconciliation-verification.json`
- `/tmp/shift-pdf-final.log`

Remaining rollout limitation: legacy shifts without opening snapshots or with
ambiguous overlapping cashier ownership need supervised cutover. The migration
preserves them and the application rejects ambiguous new attribution; it does not
invent history or supply a generic repair command. Existing open reconciliation
counts need a fresh save under the expanded concurrency basis.

## Files changed

- `app/Http/Controllers/NightshiftController.php`
- `app/Http/Requests/NightshiftCommandRequest.php`
- `app/Models/Shift.php`
- `app/Services/OrderService.php`
- `app/Services/ReportService.php`
- `app/Services/ShiftService.php`
- `app/Services/ShiftStockService.php`
- `app/Services/StockService.php`
- `app/Services/WorkspaceService.php`
- `database/seeders/NightshiftDemoSeeder.php`
- `lang/en/ui.php`
- `lang/sw/ui.php`
- `lang/sw/validation.php`
- `resources/js/nightshift.js`
- `resources/js/shift-stock.js`
- `resources/views/shift-stock-sheet.blade.php`
- `resources/views/shift-stock.blade.php`
- `scripts/browser-shift-stock-smoke.mjs`
- `scripts/mysql-validation-server.php`
- `scripts/shift-stock-browser-fixtures.php`
- `scripts/shift-stock-browser-verify.php`
- `scripts/validate-shift-stock-pdfs.mjs`
- `tests/Feature/Auth/EmailVerificationTest.php`
- `tests/Feature/MyOrdersTest.php`
- `tests/Feature/NightshiftWorkflowTest.php`
- `tests/Feature/OperationalShiftRoutingTest.php`
- `tests/Feature/PosTerminalTest.php`
- `tests/Feature/ShiftStockReconciliationTest.php`
- `tests/Feature/StaffDataAuthorizationTest.php`
- `tests/Feature/StaffVisibilityTest.php`
- `tests/Feature/UsernamePinAuthenticationTest.php`
- `tests/Feature/WaiterPinWorkflowTest.php`
- `tests/Feature/WhatsAppAfterCommitTest.php`
- `tests/Feature/WhatsAppIntegrationTest.php`
- `tests/MySql/ConcurrencyTest.php`
- `tests/MySql/transaction-worker.php`
- `tests/Support/CompletesStockCount.php`
- `tests/Support/WhatsAppFixtures.php`
- `database/migrations/2026_11_10_000001_link_operational_shifts.php`
- `docs/operational-shift-lifecycle.md`
- `docs/operational-shift-validation.md`
- `tests/Feature/StrictShiftLifecycleTest.php`
- `tests/Support/SettlesCounterWaiters.php`
