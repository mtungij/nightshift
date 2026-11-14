# Admin/Owner POS Business Unit scope

The POS now separates the authorized Business Unit option pool from its selected operational context. Company-wide Admin/Owner users can select every active authorized unit in the selected branch, including a unit with no counter. Manager/Cashier/Waiter/Terminal access remains restricted by existing permissions, assignments and shift rules.

## Root cause

POS inferred its context lock from `view-staff-operations`. A company-wide owner with `all-business-units` but without that separate staff-visibility grant could inherit a cashier-style lock and counter restriction. In addition, the old single-unit default ran before resolving the selected branch, which could select the only unit in a different branch or an inactive unit.

Company-wide unit permission already bypasses unit-assignment pivots in BusinessUnitContext; that authorization behavior is retained. The fix gives POS an independent branch-scoped `pos_unit_options` payload and explicit `broad_unit_access` / `can_switch_unit` metadata. Options no longer depend on the current counter, availability of counters, or a prior unit default.

## Resulting behavior

- Company Admin / Owner and Super Admin with `all-business-units` receive all active authorized units in the selected company/branch. No permission grants are added and no tenant scope is removed.
- Managers retain their authorized assignment scope. Cashier and Waiter active-shift context stays fixed even if their role has staff-operations visibility. Terminals remain fixed to their assigned unit/counter.
- Changing units clears incompatible counters. One eligible counter auto-selects; several counters require a choice. Counters remain active COUNTER records within the company, branch, selected unit and existing user counter authorization.
- Company-wide POS inspection uses the unit/counter permissions rather than a cashier settlement projection. Staff/report visibility and order issuance authorization are unchanged. This does not grant permission to issue outside the existing operational rules.
- POS resolves a concrete authorized branch before choosing a default unit. Inactive/unavailable prior units and inventory-store selections are discarded on entry into POS. An empty branch does not inherit a unit from another branch.
- A counterless unit keeps the unit dropdown enabled whenever other authorized choices exist. A single authorized unit can still render read-only.
- Existing cart-switch blocking, in-flight catalogue protection, product availability, unit-price override/default fallback, counter stock, immutable order context, My Orders, tickets, reports and accounting remain intact.

Empty states now distinguish:

1. No active units: **No Business Units are configured for this branch.**
2. Selected unit has no counter: **No counters are available in this Business Unit.**
3. Unit and counter are valid but catalogue is empty: **No products are available in this Business Unit.**

A unit with several eligible counters but none selected shows **Select counter**. All states use existing Nightshift styling and English/Kiswahili localization. A missing counter never hides the admin's other unit choices.

## Files changed

- `app/Services/WorkspaceService.php`: independent branch unit options, permission-specific admin inspection scope, fixed staff/terminal context, eligible counter checks and branch-first active-unit defaults.
- `resources/js/nightshift.js`: dedicated POS option pool, distinct empty messages and validation of previous context on POS entry.
- `lang/en/ui.php`, `lang/sw/ui.php`: localized no-Business-Units state.
- `tests/Feature/BusinessUnitTest.php`: eight additional admin/staff/tenant/default/empty-state regression cases.
- `scripts/pos-admin-browser-fixtures.php`: guarded disposable fixtures, legacy owner permission reproduction and explicit restoration of role/assignment metadata.
- `scripts/browser-pos-admin.mjs`: admin options, empty states, actual Dashboard → POS navigation, languages, themes and responsive checks.
- `scripts/pos-admin-browser-reconcile.php`: complete financial-table hashes and existing stock/payment/ledger reconciliation.
- `docs/pos-admin-unit-scope.md`: this report; the earlier POS context report links here for the scope refinement.
- Generated production frontend assets were rebuilt through the normal Vite build.

No migration is required. No historical attribution, prices, schedules, stock or financial records are rewritten. Provider credentials and `.env` were not changed.

## Validation

| Validation | Result |
| --- | --- |
| Complete Laravel suite, all 42 files in bounded groups | 477 tests / 4,462 assertions passed |
| Focused POS, My Orders and shift regression | 42 tests / 557 assertions passed |
| Relevant MySQL 8.4 regression | 62 tests / 527 assertions passed |
| Additional inactive/empty-company MySQL check | 1 test / 9 assertions passed |
| Final MySQL authorization cases | 7 tests / 78 assertions passed (overlap the regression run) |
| Unique relevant MySQL coverage | 63 tests / 536 assertions passed |
| PHPStan | Passed, zero errors |
| Pint and frontend formatting | Passed |
| Production frontend build / artifact verification | Passed |
| Existing frontend tests | 3 passed |
| Blade compilation / whitespace checks | Passed |
| Admin/empty-state browser checks | 31 passed |
| Existing POS/cart/staff browser checks | 32 passed |
| Filled-cart browser checks | 13 passed |
| Browser total | 76 passed, no browser exceptions or server errors |
| Responsive/language/theme coverage | 390px, 412px, 1440px; EN/SW; light/dark |

All rows in 13 financial/history tables matched their pre-regression hashes. The existing order remains paid at 5,000 with payment allocations of 5,000; waiter-ledger debit and credit are both 5,000. Physical stock and stock-movement totals both remain 9. No additional sale, payment, stock movement or accounting entry was generated by the browser checks. The disposable owner's original role and assignment metadata was restored afterward.

The browser fixtures reproduce an owner with a narrow explicit unit pivot and no staff-operations permission while retaining the existing company-wide unit grant. They also cover an owner without an active shift, Super Admin, assigned Manager, Cashier, Waiter and POS Terminal. Final validation restores the disposable owner's original role/assignment metadata.

An initial authorization fixture used ordinary mass assignment for the guarded assignment flag. It retained the intentional legacy General fallback; the fixture was corrected to explicitly configure assignments and the affected suites were rerun. The final runs include the stronger negative permission test.

## Limits retained

Role name alone does not grant company-wide unit access: an owner without `all-business-units` remains assignment-scoped. Broader POS inspection does not grant extra staff visibility or waive shift/issuance requirements. Units/counters/products still require normal administration when absent. No new shared-counter architecture or alternative pricing/stock calculation is introduced.

No production deployment or production migration was performed. Browser sends were disabled and provider calls were mocked; no real WhatsApp messages or additional sales were sent during these UI checks.
