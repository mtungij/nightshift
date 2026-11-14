# Business Unit operational contexts

Normal operation is Company → Branch → Business Unit. Existing physical stock locations remain the accounting and stock movement keys; simple mode resolves a unit to its default operational location. Advanced mode retains explicit location selection. No production deployment is included.

## Configuration and migration

`2026_11_12_000008_add_unit_operational_defaults.php` adds the unit default location reference, unit cashier capacity, optional advanced mode, cutover review notes, location system-managed flag and terminal unit binding. Company-bound foreign keys prevent cross-company configuration. The migration is forward-only.

For each active unit, one active nonshared counter is adopted when unambiguous. Otherwise an empty internal location is created. Multiple legacy counters retain advanced mode and get a review note. No balances are copied. Inactive units retain their physical references and receive a cutover note. Legacy cashier limits are summed and existing open cashier counts are respected during initial configuration, with a minimum of one. New units default to one cashier and simple mode.

The migration changes configuration only. Existing orders, movement rows, stock quantities, shifts, snapshots, tickets, handovers, payments and saved operational unit attribution remain intact. A current system migration audit records the configuration decision; it does not invent past user actions.

New unit creation initializes its default inside the tenant context, before any later staff branch assignment. Explicit default resolution checks the actor's company, branch and unit access. Missing defaults are healed idempotently. Invalid configured defaults produce a configuration error rather than silently selecting another unit's stock.

## Standard workflows

POS and Inventory load an explicit unit context. Simple-mode location selectors are hidden, while stock continues to use the stored physical key. Unit switches clear the stock context and keep the existing cart guard. Cashiers and waiters retain their saved shift context; terminals resolve their fixed unit. An inactive unit's existing staff shift can be viewed for closing, with no sale catalogue.

Receiving and adjustment commands accept `business_unit_id`. Transfers accept `source_business_unit_id` and `destination_business_unit_id`; each resolves its default independently, retaining authorization for both sides. Screen selection is cleared only while executing a cross-unit transfer; tenant, branch and unit authorization remain applied. Existing explicit location callers remain supported for compatibility and advanced workflows.

Shift opening supports the unit ID directly, including the dedicated open-shift page and dashboard modal. Unit-wide cashier capacity counts immutable shift attribution and is checked under the existing company transaction lock. Advanced mode additionally retains the physical location limit. Waiters do not consume cashier slots. Cash stays attributable to each cashier's shift, while physical stock is shared. Existing reconciliation, waiter-debt, discrepancy and handover controls remain in force. Simple-mode waiter close blockers include saved unit history across legacy locations.

Staff creation and unit settings emphasize branch/unit assignments. Simple-mode default location access derives from the existing authorized unit assignment. Explicit counter assignments remain available for advanced units. Terminal creation accepts one unit and resolves the default, preserving the stored legacy location field for compatibility. Terminal settings do not grant broader roles or unit access.

My Orders retains its workstation session token, PIN verification, active staff shift, ownership and cashier settlement rules. Simple mode filters saved unit attribution instead of limiting historical rows to the current physical default. Advanced mode keeps the physical location scope.

## Reports and receipts

Standard report controls use Branch → Business Unit → Shift. Location controls are hidden/reset for simple units. Report calculations retain stored historical attribution and never substitute the current default for a historical shift. Overnight periods, separate cashier shifts and shared stock snapshot deduplication remain unchanged.

New ticket snapshots include a location visibility flag. Simple-mode tickets show the saved Business Unit without an internal location. Advanced and historical nondefault locations remain visible. Old snapshots without the flag keep their prior display. Both 58mm and 80mm templates use the same rule. Reprinting does not rewrite snapshots or financial records.

WhatsApp shift/daily summaries emphasize branch, unit, employee and shift. Location details appear for advanced/historical multi-location contexts. Existing delivery, filtering, queue and provider architecture is reused. Browser validation mocks all provider calls.

## Supervised legacy cutover

1. Review the inactive General unit and its physical stock; establish actual product allocation to each destination unit.
2. Finish all shifts, unpaid/pending orders, waiter debt, pending adjustments/transfers, unresolved handovers and active terminal assignments at the source. The service rejects busy sources.
3. Use **Supervised stock cutover** in Business Units settings with the source location, destination unit, product unit, quantity and explanatory notes. Both `manage-business-units` and `transfer-stock` are required.
4. The company transaction lock encloses a normal transfer create/send/receive. The source decreases and destination increases through paired stock movements. The inactive-source allowance exists only within this guarded call and is restored in `finally`; it does not reactivate the unit or permit normal sales there.
5. Reconcile the remaining source quantities against the physical allocation. Repeat explicit allocations as needed. Never copy stock rows or relabel old movements.

The idempotent `/api/business-units/cutover` action uses this same path. `adopt-default` allows an authorized, idle unit to adopt one of its own active nonshared counters, only if the prior default is empty. A previous system-generated default is retired rather than deleted. Mode changes require the unit to be idle; disabling advanced mode also requires all nondefault balances to be zero. Shared stores and historical rows are retained.

## Validation artifacts

- `tests/Feature/UnitOperationalWorkflowTest.php`: defaults, idempotency, tenant/unit rejection, supervised adoption, unit cashier capacity, unit receiving/transfers/adjustments and audited inactive-unit cutover.
- `tests/MySql/ConcurrencyTest.php`: dedicated simultaneous unit opens at capacities one and two, alongside existing cashier/stock/payment/shift races.
- `scripts/unit-operations-history-check.php`: guarded disposable MySQL migration and full-row hashes across 15 operational/history tables.
- `scripts/unit-operations-browser-fixtures.php` and `scripts/browser-unit-operations.mjs`: guarded local fixtures and desktop/tablet/390px/412px EN/SW, light/dark checks.

Validation totals and any remaining failures are recorded after final checks; this document does not imply deployment or completed validation.

## Implementation files

- Schema/configuration: `database/migrations/2026_11_12_000008_add_unit_operational_defaults.php`, `app/Models/BusinessUnit.php`, `StockLocation.php`, `TenantModel.php`, `User.php`, `app/Services/UnitOperationalService.php`, `BusinessUnitService.php`, `TenantDefaultDataService.php`, `database/seeders/NightshiftDemoSeeder.php`.
- Authorization and locking: `app/Domain/BusinessUnitContext.php`, `StaffVisibility.php`, `app/Services/TransactionService.php`, `app/Providers/AppServiceProvider.php`.
- Operational writes: `app/Services/OrderService.php`, `GoodsReceiptService.php`, `StockTransferService.php`, `AdjustmentService.php`, `StockService.php`, `ShiftService.php`, `CounterCashierService.php`, `CatalogService.php`, `MyOrdersService.php`.
- Presentation/reporting: `app/Services/WorkspaceService.php`, `SalesTicketService.php`, `ShiftStockService.php`, `CashHandoverService.php`, `ReportShiftService.php`, `OperationalPeriodReportService.php`, `WhatsApp/ReportBuilder.php`.
- HTTP boundaries: `app/Http/Controllers/NightshiftController.php`, `BusinessUnitController.php`, `OpenShiftController.php`, `ProductConfigurationController.php`, `AuditTrailController.php`, `CashCustodyController.php`, `app/Http/Requests/NightshiftCommandRequest.php`.
- Screens: `resources/views/business-units.blade.php`, `open-shift.blade.php`, `shift-stock.blade.php`, `partials/cash-handover-list.blade.php`, both `partials/sales-tickets-{58,80}.blade.php`, `whatsapp/schedule-form.blade.php`; `resources/js/nightshift.js`, `counter-cashiers.js`, `business-units.js`, `staff-scopes.js`, `report-shifts.js`, `whatsapp.js`; `resources/css/nightshift.css`; `lang/en/ui.php`, `lang/sw/ui.php`.
- Tests updated for explicit simple/advanced configuration and active terminal locations: Business Unit, onboarding, cashier capacity, custody, operational workflow, staff creation/visibility and MySQL concurrency fixtures. New operational workflow tests and browser/history/PDF scripts are described above.

The working tree contains earlier Nightshift feature work. This list identifies files touched by this refactor; it does not imply that every change in those files originated in this task.

## Operational boundaries

- Ambiguous legacy units intentionally remain in advanced mode until a person verifies stock allocation and completes the explicit cutover. The software cannot infer which part of old General stock belongs to BAR or Restaurant.
- An inactive legacy unit with unresolved work cannot be transferred from until that work is resolved under the existing authorized operational controls. Cutover never force-closes shifts, forgives debt or bypasses cash reconciliation.
- Explicit historical/advanced location command inputs remain accepted for backward compatibility. Normal screens submit unit IDs; a supplied unit/location mismatch is rejected in simple mode except for finishing a user's existing saved shift context.
- Cross-unit physical stock is not automatically consolidated. Current defaults do not redefine old sales, prices, cashier attribution or physical stock movements.

## Validation results

- Full Laravel Feature/Unit suite: **485 tests, 4,508 assertions, all passed** (bounded groups). Subsequent focused checks cover the final MySQL-compatible default update and capacity guard.
- PHPStan: no errors. Pint and frontend formatting checks pass. Production frontend build and its manifest/asset verification pass. Frontend test files pass.
- Responsive browser matrix: **138 checks passed** across 1440px, 1920px, 768px, 390px and 412px, English/Kiswahili and light/dark, covering POS, inventory receiving, unit settings, shared shell, drawer and horizontal overflow. Long stock forms keep their actions visible while their contents scroll.
- A second populated mobile pass (**74 checks passed**) verifies product selection and an enabled receiving submit button at both requested mobile widths, both languages and themes. It does not post a financial/stock transaction.
- Eight ticket PDF previews pass physical width, unit label, copy, total and simple/legacy location-visibility checks (58mm/80mm × EN/SW × simple/legacy).
- The migration preserved all original rows in **15 operational/history tables** in the disposable MySQL browser database. Later fixture configuration appended current audit contexts only; every original context hash and all financial/stock rows still match. Existing invoice reconciliation remains total/paid/allocated 5,000; stock and movement balance 9; waiter debit and credit both 5,000.
- MySQL feature pass initially found a real scoped-update incompatibility (`1093`). Default location updates now load the authorized locked model before writing. All seven new operational tests pass on MySQL after that fix. Final MySQL suite totals are added after the clean rerun completes.

Existing legacy cashier counts above the old physical limits are flagged during migration and retain advanced physical-limit enforcement for supervised review; migration does not silently authorize previously overlapping cashier sessions.
