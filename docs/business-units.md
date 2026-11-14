# Business Units / Operational Areas

Implemented locally; not deployed to production. This extends scoping below company and branch and does not redesign treasury, accounting, or the operational shift lifecycle.

## Hierarchy and access

Company → Branch → Business Unit → Counter / Stock Location.

`BusinessUnit` holds company, branch, name, code, active status, creator and timestamps. Name and code are unique within a company/branch. Composite foreign keys prevent units and locations from crossing company or branch boundaries.

`business_unit_user` grants one or several units to staff. `counter_user` additionally limits explicitly configured cashier counters. Owner and Super Admin have the separate `all-business-units` permission, alongside their existing branch permission. `manage-business-units` controls unit administration. Managers retain their existing role capabilities within their assigned units; a broad staff/report permission does not bypass unit access.

Legacy staff initially receive General access at their existing branches. Compatibility for old staff-creation integrations is limited to General at assigned branches until their unit assignments are explicitly configured. An explicitly saved empty assignment gives no unit access. Newly created staff receive explicit assignments. Removing access cannot orphan an open shift. Owner/Admin can activate or deactivate staff from the assignment screen; deactivation blocks open shifts, unresolved handovers and nonzero treasury cash, so the terminal-disable cutover step is available without editing the database. Existing role/PIN management remains separate. Managers cannot replace the assignments of staff who have access outside that manager's units. Owner/Admin can edit branch memberships before adding units in those branches, with open-work and treasury safeguards.

POS Terminal scope comes from its explicit counter, company, branch and unit. A terminal's unit assignment must match that counter. Existing same-unit counter authorization checks remain in place. A cashier opening on behalf of another employee must also respect that employee's unit and explicit counter assignments.

## Backend isolation and historical meaning

The existing tenant scope now adds unit restrictions to operational model queries. Shared company masters such as products, categories, units, suppliers and payment methods remain company-wide. Unit filters never grant company, branch, role or counter access.

`operational_unit_contexts` is an append-only mapping from operational record type/id to its original unit, branch and counter/location. It captures orders and their lines, shifts, payments/allocations, waiter ledger entries, stock documents/movements, reconciliation records, custody records, notifications and operational audit references. Model creation hooks capture context in the same business transaction as the source action. Existing financial rows and their immutable triggers are not rewritten.

A counter reassignment changes ownership of its current physical stock, while past orders, payments, movements and shifts retain their old unit context. Historical names are looked up only after the source operation is authorized, with an explicit company check. Existing frozen financial snapshots remain frozen.

Authorization and selected unit context are separate. Selection is bound to the current actor/company and cannot expand another actor's access during PIN-protected My Orders reads. Single-unit users are selected automatically. Multi-unit users can select an authorized unit or All in the workspace. Selection is retained while navigating the workspace and is reset on a fresh page/account context; it is not a shared browser-wide privilege.

## Products and prices

There is one company product master. `business_unit_products` controls availability; `business_unit_prices` overrides each existing selling unit independently. Unconfigured products remain available by default, preserving existing catalogs. Explicitly disabling a product prevents backend order creation/issuance as well as hiding it from POS.

Price fallback is Business Unit override → company product-unit selling price. No counter price layer existed, so this change does not introduce one. Orders copy the price into their existing immutable historical line values; changing current pricing does not reprice old orders. POS prices and company defaults are returned separately so editing a product cannot accidentally replace a company price with a unit override.

Product editing links to `/products/{id}/business-units`, where staff with product management permission configure only authorized units. Owner/Admin can also manage availability and prices from the Business Units screen. Blank override fields restore the company fallback.

## Stock, shared stores and transfers

Stock remains in the existing location/product balances and movement ledger. Movement activity retains its event-time unit. Opening/closing inventory balances use the complete company-qualified physical ledger for currently authorized locations; inherited stock is not lost when a location changes units. Closed shift stock sheets retain their frozen historical counts. Shared stock is one physical pool: consolidated balances count each location once. A location is either privately owned by one unit or explicitly shared through `business_unit_location`. Only stores may be shared; a counter has one unit.

Transfers require access to both endpoints. Both source and destination unit contexts are snapshotted, including transfers from a shared central store. Access to a shared source does not expose another unit's private destination, transfer lines or transfer audit records. Sending and receiving recheck authorized, active locations and retain existing transaction/stock protections. No inventory quantities are merged or duplicated.

Unit deactivation and location reassignment block open cashier/waiter shifts, active terminals, unresolved orders, pending/disputed handovers, confirmed next-cashier cash awaiting opening, pending stock adjustments and unfinished transfers. Location reassignment checks that specific location. Staff should complete operations and disable terminals before reorganizing ownership.

The earlier strict lifecycle's one active cashier per counter remains unchanged. The repository had no configurable `max_active_cashiers` field to extend; this work does not add overlapping drawers or alter reconciliation ownership. The current ceiling is displayed in counter settings.

## POS, waiters, shifts, My Orders and treasury

POS shows availability/prices for its active unit/counter and eligible waiters with an open matching shift. PIN confirmation remains mandatory. Multi-unit staff still need the existing operational shift at the actual counter; access to two units does not let a Bar shift authorize Restaurant issuance. Overnight behavior, waiter debt and shift close blockers are unchanged. The earlier rule of one open operational shift per employee remains: multi-unit access supports working in each authorized unit sequentially, rather than concurrent waiter shifts across units.

My Orders retains session/view/token/PIN protections and validates current unit/counter access. Visible waiter balances are scoped; lifetime accounting remains company-wide through the existing unscoped, company-qualified accounting service. Counter close blockers, drawer expected cash and physical stock reconciliation likewise use complete company-qualified sources after authorizing the counter/shift. Unit visibility cannot forgive debt or hide a physical movement. Payments inherit the receiving/refunding cashier shift unit; stock movements inherit the physical location unit, while invoice allocations and waiter liabilities retain the originating order unit. This preserves late refunds/voids after a counter changes units without rewriting old sales or frozen shift reports.

Cash handover and treasury entries retain originating unit/counter/shift through immutable context. Next-cashier recipients must be allowed at the source counter. Custodians need access to the source unit to receive its handovers. Assign Accountant staff the intended units before operational cutover. Existing treasury balances remain the same branch/custodian cash accounts; a unit is source context, not a new cash account. Unit filters must not hide debits when calculating spendable physical cash.

## UI and reporting

`/business-units` provides Owner/Admin creation/editing, activation/deactivation, location/shared-store ownership, staff unit/counter assignments and product configuration. Staff creation includes unit selection and cashier counters; staff editing includes branch/unit/counter assignments and safe activation/deactivation. Unit selection follows its own branch and cross-branch mismatches are rejected; terminal creation requires a matching counter/unit. English and Tanzanian Kiswahili labels are provided.

Workspace/report requests accept `business_unit_id` in addition to branch and counter filters. Only authorized units are offered. Existing reports inherit backend unit scope. The report modal and CSV export retain the unit filter. Business Unit Performance groups existing order, payment, stock movement and shift sources into sales, cash collected, outstanding, order count, net base-unit stock movement, and active cashier/waiter shifts. No new accounting ledger is introduced.

## Concurrency and audit

Unit management, assignments, pricing and availability use the existing company-serialized transaction lock, shared with order issuance, shift operations and stock transfers. Thus price changes, deactivation/opening, reassignment/opening, duplicate spends and transfer races serialize consistently. After acquiring the company lock, transactions recheck the actor’s active status, role and terminal binding and refresh unit/counter assignment flags; an in-flight terminal cannot continue writing with access that was revoked while it waited. Database uniqueness and tenant foreign keys provide additional enforcement.

Explicit unit edits, staff/unit and counter assignments, availability/pricing changes and transfers are audited with actor, time and before/after context. Operational audit entries inherit their source unit. Immutable context UPDATE/DELETE triggers prevent changing historical attribution. Corrections require forward records rather than rewriting old mappings.

## Migrations and rollout

- `2026_11_10_000003_create_business_units.php`: seven tables, user configuration flags, location unit/shared fields, permission grants and General migration.
- `2026_11_10_000004_snapshot_transfer_destinations.php`: append missing historical transfer destination contexts, so shared-source access cannot reveal a private destination.

Both migrations are forward-only; `down()` refuses rollback. Restore a verified backup or apply a reviewed forward correction. They depend on the earlier operational shift and custody migrations already present in this workspace.

Use a maintenance window and stop background operational writers during migration. Back up and verify the target database; use the normal migration identity with trigger privileges. Create General per existing branch and attach current locations/staff there. Do not invent Bar/Restaurant assignments. Historical financial records stay valid and receive General context without altering their monetary history. No production migration has been run by this task.

To reorganize General into Bar and Restaurant: create the units, grant intended staff and Accountant access, finish shifts/debt/pending work, deactivate affected terminals, assign each counter/private store or explicit shared store, configure product availability/prices, verify staff/counter assignments, then reactivate terminals and open new shifts. Keep General access for staff who must review legacy operations; removing it deliberately removes their access to that old history. Never move a unit between branches; create the appropriate branch unit instead.

## Files

New core files: `app/Models/BusinessUnit.php`, `app/Domain/BusinessUnitContext.php`, `app/Services/BusinessUnitService.php`, `app/Http/Controllers/BusinessUnitController.php`, `config/business_units.php`, both migrations above.

Integration: `TenantModel`, `User`, `StockLocation`, `TenantContext`-based visibility, service provider registration, catalog, workspace, orders, shifts, transfers, reports, My Orders, shift stock/history presentation, custody source presentation, request validation and route/permission configuration. Existing treasury/accounting services are extended only for originating context and recipient scope.

UI: `business-units.blade.php`, `product-business-units.blade.php`, `business-units.js`, existing workspace JavaScript and treasury ledger presentation; EN/SW UI dictionaries.

Validation: `tests/Feature/BusinessUnitTest.php`, six added MySQL races/worker actions, updated schema assertions, and `scripts/business-unit-browser-{fixtures,verify}.php`, `scripts/browser-business-unit-{smoke,review,reports,context,status,branches,locales}.mjs`. Earlier uncommitted operational-shift/treasury changes are preserved.

## Limits

No counter-level price override, new bank/payroll engine, shared cashier drawer policy or production deployment is included. Shared stores intentionally expose their own shared stock to authorized users; private destination transfers still require both endpoints. Workspace unit selection is kept during workspace navigation rather than persisted as a cross-account browser preference. Physical printer hardware was not exercised in this task. See the validation report for exact suite and browser results.
