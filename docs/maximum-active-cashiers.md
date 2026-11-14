# Maximum active cashiers per counter

Each COUNTER now has a positive integer `max_active_cashiers`, default 1. This is a stock-location setting, not a company or Business Unit setting. Stores retain an inert default value and expose no cashier controls or cashier shift opening.

## Migration

`2026_11_11_000006_add_counter_cashier_limits.php` adds a non-null unsigned-small-integer column with default 1. Existing operational counters get 1; existing positive configurations are preserved if the column already exists. Null/zero legacy counter settings are normalized to 1. Re-running the migration preserves configured values. The migration is forward-only and does not edit shifts, orders, posted prices, payments, stocks, ledgers, handovers or historical operational context.

Requests and the setting service accept integers from 1 through the column's technical maximum, 65535. Zero, null, fractions and out-of-range values are rejected.

## Enforcement and concurrency

Shift opening still validates company, branch, Business Unit, employee/counter assignments, active location/unit/branch, role, and the employee's own open shift. Before a CASHIER shift opens, the existing TransactionService company lock and a location row lock serialize capacity checks against limit updates and final closing. Only OPEN CASHIER shifts count; waiter sessions and terminals do not occupy slots. No manager or Super Admin capacity bypass was added.

The enforced rule is `open_cashier_count < max_active_cashiers`. The count includes every open cashier at the company/counter, even if staff are subsequently deactivated or reassigned. Reducing the limit below that count is rejected. Increases apply immediately to subsequent backend opening checks and do not change existing shifts.

The original single-cashier assumptions in `counterCashier()` and stock mutation handling now recognize configured multi-cashier capacity. Counts exceeding the configured capacity retain the existing supervised legacy-cutover error. A shared counter with available capacity can admit another cashier while its normal waiter operation continues. Starting a counter from zero active cashiers still requires the existing waiter/debt cleanup.

## Individual cash, shared stock

Payments and manually issued orders prefer the acting cashier's own open shift, including when that cashier also has management permissions. Authorized manager fallback and direct waiter/terminal issuance use the oldest open cashier as the stable operational anchor. The anchor is an attribution for counter operations, not a merged drawer. Actual cash, expected cash, payments, cash differences, handovers and final records remain attached to each individual cashier shift.

Physical stock remains the existing single counter/product balance. Every cashier's stock reconciliation reads the shared counter's movements over that cashier's opening/closing window. Counts and closing snapshots never duplicate or move physical stock. Overlapping counter-stock windows are not additive measures of posted revenue; posted sales and cash retain their existing saved order/payment classifications.

The existing counter-wide waiter-settlement, pending-order, physical-count and variance-approval requirements remain. A staged handover freezes stock movement, payment/issuance and new admissions across all active cashiers at that shared counter until its source shift closes. This prevents another drawer invalidating a frozen reconciliation. Cashiers close individually once the counter-wide checks are satisfied.

For configured multi-cashier counters, pending next-cashier handovers are selected within the receiving cashier's assignment. Two drawers' amounts are never combined. Receipt confirmation, source finalization, differences, manager approval and once-only opening application retain their existing rules.

## UI, scoping and audit

The existing Counters & Stores dashboard page uses responsive cards with Active Cashiers `current / limit`, a positive-integer limit input and help text. The Business Units page has a separate capacity form alongside the location assignment section, so changing a limit does not attempt to reassign a busy Business Unit. Both use the existing manage-counters permission and tenant/branch/unit/counter scopes.

Add Location defaults to 1 for counters. Selecting Storage hides and disables the control. The standalone Open Shift page and the manager modal show occupancy, disable opening for a full cashier counter, and explain the refusal. Waiter mode remains available under its existing rules. Occupancy is a read-time snapshot; refresh the page/workspace after another user opens or closes. Backend checks always use the current committed count.

EN/SW labels and messages use the existing catalog. The standalone page's compact JavaScript catalog now includes the occupancy and full-counter messages. The controls retain Nightshift typography, themes, buttons, cards and navigation.

`CASHIER_LIMIT_UPDATED` records actor, counter reference, branch, previous/new limits and timestamp only when the value changes. The Audit Trail shows “Cashier Limit Updated” / “Kikomo cha Makashia Kimebadilishwa” and a localized Maximum Active Cashiers field label. New-location audits include their initial capacity.

## Validation

| Check | Result |
| --- | --- |
| Full Laravel feature/unit suite | 421 passed, 4,066 assertions; matched full PHPUnit discovery |
| Final focused counter suite | 14 passed, 69 assertions |
| Relevant MySQL 8.4.11 suite | 141 unique tests passed, 2,410 assertions |
| MySQL concurrency coverage | 8 scenarios passed: max=1 simultaneous opens, max=2 three simultaneous opens, limit reduction/open race, close/open at both limits, waiter close/open safety, handover/open once-only application and stock/final-close serialization |
| Browser | 61 checks passed, zero runtime/server errors |
| Viewports | 390, 412, 820, 1440 and 1920px; EN/SW, light/dark |
| Formatting, PHPStan, frontend checks, production build | Passed |

Real browser workflows tested first admission, blocking at 1/1, immediate increase to 2, admission of the second cashier, blocking the third, unsafe reduction rejection, the manager modal, separate zero-cash counts/final closes, safe reduction and admission of the next cashier after close. Creation controls and Business Units capacity saves were also tested on mobile. Mobile screenshots were visually reviewed.

All original rows in 13 financial/stock/shift tables remained byte-for-byte equivalent under ordered hashes. The five synthetic zero-cash browser sessions, including interrupted-run cleanup, each finalized exactly once with their own opening/closing snapshots and zero-cash disposition. No new orders, posted lines, payments, allocations, waiter ledger entries, physical stock, movements or treasury entries were created. Final occupancy was zero and the test counter limit was restored to 1. The localized audit action and field presentation also passed.

A separate integration test receives shared stock, lets two cashiers issue and collect their own payments, closes their waiter, then reconciles/closes both drawers independently. The shared balance reconciles to 18 units after two sales from 20, without duplicate stock or merged cash. Another test applies two distinct next-cashier handovers as 100 and 200 to their respective recipients.

Evidence: `/tmp/cashier-validation-summary.json`, `/tmp/cashier-reconciliation-final.json`, `/tmp/nightshift-cashier-browser/{result,settings-result}.json` and screenshots. The full Laravel suite ran in bounded groups; relevant MySQL groups used separate disposable schemas. Repeated counter cases were counted once using the latest JUnit result.

## Changed files

- `database/migrations/2026_11_11_000006_add_counter_cashier_limits.php`
- `app/Models/StockLocation.php`
- `app/Services/CounterCashierService.php`
- `app/Services/ShiftService.php`, `StockService.php`, `CashHandoverService.php`
- `app/Services/CatalogService.php`, `WorkspaceService.php`, `AuditTrailService.php`
- `app/Http/Requests/NightshiftCommandRequest.php`
- `resources/js/counter-cashiers.js`, `nightshift.js`, `app.js`
- `resources/css/nightshift.css`
- `resources/views/open-shift.blade.php`, `business-units.blade.php`, `partials/language.blade.php`
- `lang/en/ui.php`, `lang/sw/ui.php`
- `tests/Feature/CounterCashierLimitTest.php`, `StrictShiftLifecycleTest.php`
- `tests/MySql/ConcurrencyTest.php`, `transaction-worker.php`
- `scripts/cashier-limit-browser-fixtures.php`, `cashier-limit-browser-verify.php`
- `scripts/browser-cashier-limits.mjs`, `browser-cashier-limit-settings.mjs`
- This report.

## Remaining boundaries

Counter-wide close/debt/count/handover safeguards remain in force; this does not introduce independently moving physical stock per drawer. Occupancy displays refresh with the page/workspace. The existing manager-resolution guard for multiple pending handovers on a limit-1 counter remains; resolve multi-drawer handovers before returning such a counter to its normal single-cashier operation.

Earlier product, reporting, shift and treasury work remains in the working tree. No production deployment was performed.
