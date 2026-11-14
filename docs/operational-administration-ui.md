# Nightshift operational administration UI

Implemented locally. No production deployment or migrations were performed. Existing monetary calculations, immutable financial/history records, role permissions, operational shift guards and tenant boundaries remain in force.

## Root causes

Treasury, Cash Handovers and treasury reports rendered standalone document wrappers instead of mounting the existing JavaScript workspace shell. Staff forms used native multi-select lists without branch → unit → counter dependencies. Audit Trail embedded the latest raw audit JSON in every privileged workspace bootstrap, with client-only search, technical action labels and no proper history pagination.

## Shared workspace and navigation

Business Units, Treasury, Cash Handovers, treasury report screens and Audit Trail now use the existing `nightshift.js` shell. The sidebar, company/branch identity, topbar, EN/SW, theme control, user menu and mobile drawer use the same rendering and handlers as Dashboard. `partials/workspace-mount` supplies the common mount points, not a second admin layout.

Navigation groups are Business, Management, Finance and Administration. Visibility uses the existing permissions, including the existing combination of custody permissions for Cash Handovers. The active item has the normal styling and `aria-current`; only the sidebar's scroll position changes to reveal it. Legacy `#audit` navigation resolves to `/audit-trail`.

A small permission-checked `/api/workspace-shell?page=…` endpoint returns navigation metadata rather than financial/operational datasets. This allows a closed cashier to revisit Cash Handovers while the existing operational bootstrap and commands continue to require the proper shift. Dedicated print output stays free of navigation; interactive treasury reports use the shared shell.

## Staff scope controls

Add Staff/Waiter uses styled expandable checklists. Changing branch hides and clears units from other branches; selecting units combines their authorized counters. Counter labels identify the unit. Selected names wrap, and an empty state explains when no counters are available. Cashiers select explicit counters. Terminals select exactly one unit and one matching counter; waiter/manager/accountant behavior continues to follow existing role rules.

Staff assignment editing on Business Units uses the same checklist styling and dependency handling. Owner/Super Admin implicit scope is shown as All Business Units rather than editable grants. All candidate records come from the current actor's authorized scope. Branch and unit additions can be selected and saved together, with existing backend validation and open-work safeguards. Clearing assignments remains an explicit option. Existing terminal counter bindings are displayed read-only; this task does not introduce terminal rebinding or role/PIN changes.

The assignment-options endpoint is permission-checked and independent of the current POS filter; it still returns only authorized company/branch/unit records. Backend validation remains authoritative for tampered requests.

## Finance screens

Treasury uses the standard heading/actions, responsive metric cards, branch/unit/date controls and source context on the ledger. **Unit filtering applies only to source activity. Available cash and summary totals keep their existing full custody scope**, as the page explicitly states. It does not create unit cash accounts or omit debits when calculating available physical cash.

Cash Handovers retains the existing receipt/dispute/approval/cancel/replace-recipient actions and custody event history. Unit context is visible beside the counter. It is paginated at 25 handovers and adapts from a desktop table to labelled mobile cards. Treasury ledger/report tables also adapt to cards on narrow screens. Existing bounded treasury lists and paginated report services remain unchanged in accounting meaning.

## Audit presentation and security

`AuditTrailService` is read-only. No audit rows or history triggers are rewritten. Actual internal actions map to friendly EN/SW descriptions, including `BUSINESS_UNIT_SAVED`, `LOGIN`, `SHIFT_OPENED`, `SHIFT_CLOSED`, `SHIFT_STOCK_OPENING`, `USER_SAVED`, assignment/status changes, `PAYMENT_RECEIVED`, `DRINKS_ISSUED`, `CASH_HANDOVER_PENDING/RECEIVED`, and actual `TREASURY_*` codes. Business Unit create/update labels distinguish an empty previous record from an update. Unmapped actions have a readable title fallback, with the original code retained in details.

The main table/cards show Time, User, Action, Record, Summary and View details. A native accessible dialog shows technical code, module, actor, timestamp, reference and friendly changes. Equal values and record metadata are omitted from the friendly diff; nested associative changes are compared field by field. Pretty-printed raw payloads appear only in the optional details section after server redaction.

Name resolution batches permitted branches, units, counters, users and supported reference models. Missing or inaccessible IDs receive a safe unavailable-record fallback. No cross-company/unassigned-unit names are resolved. The old branch-restricted outstanding-value redaction is retained. The query-count regression compares one and 25 presented records.

Sensitive keys are recursively redacted before HTML or CSV: PIN/operational PIN, password/confirmation, remember/access tokens, CSRF, secrets, API keys, authorization and bearer credentials, including camel-case/hyphenated keys and nested JSON strings. Recovery codes, private keys and session/cookie keys are also redacted. CSV also escapes formula-leading cells. Redaction never changes the stored audit payload.

Audit pages use 25-row simple pagination. Filtered CSV streams 100-row batches. Search covers safe actor/action/module/reference metadata, localized friendly action labels, actual shift/invoice references, authorized branch/counter/unit names and assignment unit IDs. It does not search arbitrary raw payloads or secrets. Filters include dates, user, module, action, branch and unit; the backend also accepts an authorized counter filter. Historical raw audit data is no longer included in the general workspace bootstrap.

## Responsive and localization behavior

Existing Poppins, colors, panels, borders, buttons, fields, badges and light/dark tokens are reused. Desktop content has a sensible maximum width; mobile controls and header actions wrap or stack. Checklists scroll within their own small groups, the existing staff modal scrolls within the viewport, and audit dialogs constrain both width and height. No separate visual system was added.

Treasury is labelled “Hazina ya Cash”, Cash Handovers “Makabidhiano ya Cash”, and Finance “Fedha”. Business Units retains the requested Business/Biashara breadcrumb and EN/SW description.

## Files

New:

- `app/Http/Controllers/AuditTrailController.php`
- `app/Http/Controllers/WorkspaceShellController.php`
- `app/Services/AuditTrailService.php`
- `resources/views/audit-trail.blade.php`
- `resources/views/partials/workspace-mount.blade.php`
- `resources/js/staff-scopes.js`, `resources/js/admin-pages.js`
- `tests/Feature/AdministrationUiTest.php`
- `scripts/browser-administration-ui.mjs`, `scripts/browser-administration-staff.mjs`, `scripts/browser-administration-final.mjs`
- `scripts/administration-ui-browser-fixtures.php`, `scripts/administration-ui-browser-verify.php`

Integrated:

- `BusinessUnitController`, `CashCustodyController`, `WorkspaceService`, `TreasuryService` (read/presentation paths)
- `routes/web.php`
- Business Units, Treasury, Cash Handovers, treasury report and ledger/handover partials
- `resources/views/partials/language.blade.php`
- `resources/js/nightshift.js`, `business-units.js`, `cash-custody.js`, `app.js`
- `resources/css/nightshift.css`, `lang/en/ui.php`, `lang/sw/ui.php`

Earlier uncommitted operational lifecycle, custody and Business Unit implementation is preserved. No migration was added or changed for this cleanup.

## Validation

- Laravel full suite: **385 passed, 3,856 assertions**.
- Relevant MySQL **8.4.11** suite: **71 passed, 1,757 assertions** (administration, Business Units, custody and actual schema compatibility). 
- Final supplementary rendering/security checks: **10 passed, 194 assertions** on SQLite; final credential-redaction check on MySQL: **1 passed, 11 assertions**. These follow the complete suites above and include recovery/private-key redaction and final rendering refinements.
- PHPStan: **zero errors**. Pint, `git diff --check`, production build/asset verification, frontend tests and all seven localization checks passed.
- Main browser run: **179 checks passed** across Business Units, Treasury, Cash Handovers, Audit Trail and Staff modal at **390, 412, 820, 1440 and 1920px**, in English/Kiswahili and light/dark. Includes drawer behavior, bounds/overflow, audit mobile cards/details/CSV redaction, report shell, role-specific staff options and direct-URL denials. No runtime exceptions or HTTP 5xx.
- Mobile staff save/edit: **15 checks passed**, including actual multi-unit/counter creation, immediate new-branch unit selection, atomic branch/unit edit and restoration, empty states and explicit clearing. Final record-card/dialog/PDF checks: **23 passed**. Total browser checks: **217**, with no runtime exceptions or HTTP 5xx.
- Treasury report PDF: **2 A4 landscape pages, 320 words inside page bounds**, with required ledger headers/amounts present (`/tmp/admin-pdf-final.json`).
- Financial fingerprint: **all 13 checked tables unchanged**—orders/items, payments/allocations, waiter ledger, stocks/movements, treasury entries, handovers/events, shifts and stock snapshots/counts. Staff/audit administration adds only expected non-financial records.

Temporary evidence is in `/tmp/admin-{complete,mysql-complete,static-last,pint-verified,build-final,frontend,localization,final-checks,mysql-security-final}.log`, `/tmp/admin-financial-final.json`, `/tmp/nightshift-admin-ui-browser/` and `/tmp/nightshift-admin-ui-staff/` and `/tmp/nightshift-admin-ui-final/` (including the treasury report PDF).

## Limits

Existing terminal bindings remain fixed in the assignment editor. Unknown legacy audit actions use the readable fallback; unavailable references are deliberately not resolved outside authorization. Treasury available-cash calculations remain full-history branch/custodian balances, not unit balances. Existing treasury list limits remain; report pagination and audit/handovers pagination are retained. Physical printer hardware was not exercised.

Temporary validation web, browser and MySQL services were stopped after these checks.
