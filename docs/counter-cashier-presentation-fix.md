# Counter cashier capacity display and editing

## Root cause

Counter cards interpolated the raw serialized `max_active_cashiers` field directly into both the occupancy denominator and the numeric input. Eloquent's creation defaults do not fill attributes omitted when an existing row is hydrated, and an explicitly null hydrated value also stays null. The UI template helper converts missing/null substitutions to an empty string. That combination reproduced both `Active Cashiers: 1 /` and `value=""`.

The regression is covered using a partially selected saved row and legacy/null hydrated values. This identifies the rendering failure without assuming that a production database was inspected or modifying its data.

## Read-only payload fix

`CounterLocationPresenter` provides an explicit location representation. It reloads partially selected existing models through the normal scoped query so a saved limit of 2 is returned as 2. For genuinely absent/null/nonpositive legacy display values it uses the current default of 1. It never saves a location or mutates the caller's hydrated model.

The workspace payload now guarantees:

- `max_active_cashiers`: integer saved/display limit.
- `active_cashier_count`: authoritative backend OPEN CASHIER count.
- `active_cashiers`: retained compatibility alias of the same count.
- `business_unit_name`: the actual authorized unit name for a private location; null for a shared store.
- `is_shared`: explicit boolean.

The existing cashier-limit command response adds a current `location` representation after the existing mutation/idempotency transaction. This reads fresh metadata even for a retried cached command result. The existing mutation, validation, permissions, audit and shift/accounting logic are unchanged. No migration was added or altered.

## UI behavior

Counter cards render a complete `current / maximum` occupancy and a required number input with `min=1`, `step=1`, the existing technical maximum, and the saved value. They include branch and Business Unit context, retain Active/Inactive status, and show a small “Cashier limit reached” badge when full. The Swahili label is “Idadi ya makashia imefikia kiwango cha juu”.

Stores and other non-cashier types have no occupancy/editor/save controls. Shared stores display “Shared across Business Units”, without a misleading General assignment.

Successful saves update the input, saved-value state, occupancy denominator and full badge from the returned backend representation, without reloading the page. They show localized success feedback. The dashboard's location state is synchronized so subsequent rendering retains the saved value. In-flight older workspace refreshes cannot overwrite a newer capacity save.

Rejected saves restore the last backend-supplied value and show an inline error. The UI never treats the rejected value as the saved maximum. The existing Business Units editor uses the same read-only presentation and keeps its location identity occupancy synchronized with its capacity form.

Normal workspace/page refreshes reflect shift opening and closing without logout/login. Mobile controls remain full-width where appropriate, full-state text wraps, and the existing Nightshift shell, typography, cards, status badges and themes are retained.

## Validation results

| Check | Result |
| --- | --- |
| Full Laravel suite | 428 passed, 4,116 assertions; matches full PHPUnit discovery |
| Focused presentation/counter suite | 21 passed, 119 assertions |
| Relevant MySQL 8.4.11 suite | 51 passed, 268 assertions |
| Frontend regression suite | Passed, including four new counter-rendering/binding cases |
| Browser checks | 62 passed; no runtime/server errors |
| Formatting, PHPStan, production frontend build | Passed |

Browser coverage includes 390/412px mobile, 820px tablet, 1440/1920px desktop, EN/SW, light/dark, default 0/1, full 1/1, saved 1/2 and full 2/2. It verifies unit/shared-store context, invalid native inputs, in-place success, normal refresh, rejected reduction restoring the saved 2, success after rejection, and the server-rendered Business Units form. Actual pointer-driven Save clicks were hit-tested and completed at 390px and 412px. Mobile full-state screenshots were visually reviewed.

The regression suite specifically prevents an empty denominator and blank maximum input and verifies that partial-model presentation loads the real saved 2. Legacy fallback tests prove that rendering writes no location/audit data and does not mutate the original model.

Existing rows across 13 financial/stock/shift tables remained identical under ordered fingerprints. Two synthetic zero-cash fixture shifts were opened and finalized through the existing services to test refreshes. They each received one opening snapshot, one closing snapshot and an independent zero-cash disposition. No new orders, posted lines, payments, allocations, waiter ledger entries, stocks, movements or treasury entries were created. The synthetic counter finished at 0/1.

Evidence: `/tmp/counter-binding-validation-summary.json`, `/tmp/counter-binding-reconciliation.json`, test-group JSON/JUnit files under `/tmp/counter-binding-{sqlite,mysql}-group*`, and browser JSON/screenshots under `/tmp/nightshift-counter-binding-browser`. Laravel coverage ran in bounded groups; MySQL groups and browser data used separate disposable schemas.

## Files changed in this focused task

- `app/Support/CounterLocationPresenter.php`
- `app/Services/WorkspaceService.php`
- `app/Http/Controllers/NightshiftController.php`
- `app/Http/Controllers/BusinessUnitController.php`
- `resources/js/counter-cards.js`
- `resources/js/counter-cashiers.js`
- `resources/js/nightshift.js`
- `resources/css/nightshift.css`
- `resources/views/business-units.blade.php`
- `lang/en/ui.php`, `lang/sw/ui.php`
- `tests/Feature/CounterCashierPresentationTest.php`
- `tests/Frontend/CounterCards.test.mjs`
- `scripts/counter-presentation-browser-fixtures.php`
- `scripts/counter-presentation-browser-verify.php`
- `scripts/browser-counter-presentation.mjs`
- `scripts/browser-counter-presentation-full-state.mjs`
- `scripts/browser-cashier-limits.mjs`, `scripts/browser-cashier-limit-settings.mjs`: existing helpers updated for in-place saves.
- This report.

Shift lifecycle, cashier capacity enforcement, accounting, audit mutation rules, tenant/Business Unit permissions and historical context were not redesigned. Earlier working-tree changes were preserved. No production deployment was performed.
