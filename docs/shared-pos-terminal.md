# Shared POS Terminal

A POS Terminal is a restricted workstation account. It keeps the browser signed in while each order is assigned to a selected waiter who confirms their own four-digit PIN. Individual waiter accounts and the existing cashier, manager and owner workflows remain available.

## Provisioning and use

Apply `2026_11_07_000001_add_pos_terminal_role.php` with the normal release migration process. This change has not been deployed or migrated against the normal application database.

An owner or Super Admin opens **Staff → Add team member**, selects **POS Terminal** (Swahili: **Kituo cha POS**), and enters the terminal name, username, existing staff email field, PIN, active status, branch and counter. Only active counters in the selected branch are offered. Managers retain ordinary staff creation but cannot create terminal accounts. The existing owner-only PIN reset flow works for terminals.

Terminal login always lands on `/dashboard#pos`, including when a previous intended URL points elsewhere. Navigation provides POS and this terminal's orders; the account menu provides manual logout. Profile/security management, notifications, management search and shift controls are hidden. EN/SW and theme preferences remain available. Sessions retain the existing 960-minute lifetime and have no inactivity lock.

The cart stays in the browser until **Send to Cashier**. Choose an active waiter assigned to the same branch with an open waiter shift at the assigned counter, then enter that waiter's PIN. The existing transaction immediately issues the order, deducts stock, debits the waiter and prepares the existing Waiter Copy and Cashier Copy. A terminal cannot issue through the separate cashier command, take payment, settle balances, change prices or apply discounts. It has no operational shift of its own.

## Authorization and accounting

- The new role receives only existing `create-orders` and `view-orders` permissions. No new broad permission is introduced; existing roles and custom grants are preserved by the migration.
- `users.terminal_stock_location_id` is a nullable foreign key with restricted deletion. The assigned counter determines the single allowed branch; extra branch pivot rows never broaden terminal scope.
- Web middleware defaults to denying terminal routes, including Livewire mutations, reports, settings, cashier operations and approval endpoints. Permitted commands are PIN-confirmed order send and scoped ticket reprint. Existing service gates remain in force.
- Bootstrap includes active sellable products, the assigned branch/counter, eligible waiter identity, table names and this terminal's own orders. It excludes staff, payment, ledger, inventory, report, audit, reservation and reconciliation datasets. Direct ID tampering is checked by tenant, branch, counter and order-owner scopes.
- The existing PIN service verifies the selected waiter, preserves failure counters, lockout and throttling, and finishes failed-attempt persistence before any order transaction. Failed PINs have no order, payment, stock, ledger or print-batch effects. PINs are not added to audit records.
- `created_by` and `issued_by` retain the terminal account; `waiter_id` and the waiter shift retain responsibility for the sale. The order audit includes terminal/entered-by user, waiter and counter; its existing fields retain company, branch, order reference and timestamp.
- Both native paper templates include **Entered via** for terminal orders and retain waiter identity and optional order notes. Waiter outstanding on the ticket is limited to the assigned counter, as with cashier counter visibility. Reprints retain the existing authorization and audit path.
- Stock uses the original `ISSUE` movement and waiter ledger entry. Cashier reconciliation reads the same counter movement ledger, including terminal orders. No second stock or settlement path is introduced.

## Changed files

Application: `app/Domain/{OperationalShift,StaffVisibility,TenantContext}.php`, `app/Http/Middleware/RestrictPosTerminal.php`, `app/Http/Requests/NightshiftCommandRequest.php`, `app/Http/Responses/StaffLoginResponse.php`, `app/Models/User.php`, `app/Services/{CatalogService,OrderService,SalesTicketService,WorkspaceService}.php`, `bootstrap/app.php`, `config/{business,nightshift}.php`.

UI: `resources/js/nightshift.js`, `lang/sw.json`, `lang/{en,sw}/ui.php`, `resources/views/partials/sales-tickets-{58,80}.blade.php`.

Schema: `database/migrations/2026_11_07_000001_add_pos_terminal_role.php`.

Validation: `tests/Feature/PosTerminalTest.php`, terminal fixture adaptations in `StaffCreationPinTest.php` and `StaffVisibilityTest.php`, `tests/MySql/ConcurrencyTest.php`, `scripts/shared-pos-browser-fixtures.php`, `scripts/browser-pos-terminal-smoke.mjs`, `scripts/pos-terminal-browser-verify.php`, `scripts/validate-pos-terminal-pdfs.py`, and this document.

## Validation

- Full Laravel/SQLite suite: **287 tests, 3,031 assertions passed**.
- Full MySQL **8.4.11** suite: **303 tests, 4,217 assertions passed**, including the existing concurrency tests and the new terminal simultaneous-retry test.
- Final terminal-specific checks after the last scope/fixture changes: **12 tests, 219 assertions passed on each of SQLite and MySQL**.
- PHP Pint, JavaScript formatting, PHPStan level 7 and the production frontend build passed. PHPStan needed a 512 MB limit; its optional turbo extension was unavailable, and analysis completed without it.
- Shared-terminal browser checks passed at **1440px desktop and 390px mobile** for both native paper widths, EN/SW, owner creation, restricted navigation/endpoints, selected-waiter PIN isolation, sequential multi-waiter use, notes, stock deduction and manual logout. The 80mm run passed 47 checks; the final 58mm run passed 51 checks, including explicit Swahili role and print-attribution checks (**98 terminal browser checks total**).
- Existing shared-session browser regression: **52 checks passed**, including individual waiter login and own identity protection, cashier session preservation and ledger visibility, admin/manager manual POS entry, no inactivity lock, logout and CSRF validation. No browser runtime exceptions or HTTP 5xx occurred.
- **Eight native ticket PDFs passed** paper-size, selected-waiter and terminal attribution, both copy labels, both order notes and horizontal text-boundary checks. 80mm produces two pages per batch; the existing continuous 58mm layout fits the two short fixture tickets on one page. Desktop and mobile screenshots and the narrow PDF were visually inspected.
- Final browser accounting reconciliation verified **16 terminal orders**, each with one stock issue, one matching waiter debit, one initial two-copy batch and matching terminal/waiter audit, with **zero cashier ledger mismatches**.

Artifacts: `/tmp/nightshift-terminal-80mm/result.json`, `/tmp/nightshift-terminal-58mm/result.json`, `/tmp/nightshift-existing-shared-pos/result.json`, `/tmp/nightshift-terminal-pdf-results.json`, `/tmp/nightshift-terminal-reconciliation.json`; screenshots and PDFs are beside their browser results.

MySQL validation used a disposable instance on localhost port 3340 with binary logging disabled, strict SQL mode, InnoDB and the dedicated non-root test account. The normal application database and existing MySQL service were not changed. The temporary PHP server, isolated Chrome and disposable MySQL instance were stopped after validation. Browser runs used the guarded validation router on port 8884 and an isolated headless Chrome profile on port 9224.

To repeat browser verification after database tests finish:

```sh
MYSQL_VALIDATION_PORT=3340 SMOKE_PAPER_WIDTH=80mm php scripts/shared-pos-browser-fixtures.php > /tmp/nightshift-shared-pos-fixtures.json
SMOKE_PAPER_WIDTH=80mm SMOKE_OUTPUT=/tmp/nightshift-terminal-80mm node scripts/browser-pos-terminal-smoke.mjs
MYSQL_VALIDATION_PORT=3340 SMOKE_PAPER_WIDTH=58mm php scripts/shared-pos-browser-fixtures.php > /tmp/nightshift-shared-pos-fixtures.json
SMOKE_PAPER_WIDTH=58mm SMOKE_OUTPUT=/tmp/nightshift-terminal-58mm node scripts/browser-pos-terminal-smoke.mjs
python3 scripts/validate-pos-terminal-pdfs.py
MYSQL_VALIDATION_PORT=3340 php scripts/pos-terminal-browser-verify.php
```

## Limits

One counter is supported per terminal. Terminal carts use the existing browser cart; persisted waiter drafts remain part of the individual waiter workflow. The existing staff form still requires an email address. Native 58mm/80mm browser/PDF rendering can be validated locally; physical printer, cutter and driver behavior require hardware testing.
