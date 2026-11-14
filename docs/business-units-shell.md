# Business Units dashboard shell

## Cause and implementation

`business-units.blade.php` previously rendered directly inside the plain `layouts.nightshift` document, with an auth toolbar and reconciliation-page wrapper. The operational dashboard uses `nightshift.js` to render the sidebar, topbar, workspace identity, navigation and mobile drawer; the standalone page never mounted that shell.

Business Units now mounts its server-rendered forms in the existing workspace's `#main` content area. There is no second administration layout. The same shell renderer and event handlers supply the sidebar, user menu, language/theme controls, mobile navigation and workspace identity. The form DOM is retained when the shell refreshes, preserving form bindings and entered values. Language changes reload the server-rendered labels using the saved preference.

The administration navigation includes a permission-controlled Business Units entry with the normal active state. Links back to workspace screens use `/dashboard#screen`; the Dashboard link and browser back restore the expected page. The route's existing authorization is unchanged.

## Responsive content

Content is limited to 1,120px and uses existing Poppins, panel, border, input, button, badge and theme tokens. Desktop unit rows use compact labelled columns. Tablet forms wrap into a grid; mobile forms stack in one column with full-width controls and Save buttons. Unit rows become stacked editable cards without a horizontal table. Location assignment has its own section showing branch, current unit/shared-store state and the existing cashier ceiling. Labelled checkbox groups replace native multi-selects for location/staff assignments while keeping their field names and request values unchanged.

No service, permission, accounting, pricing, stock, report or migration behavior changed.

## Files in this change

- `resources/views/business-units.blade.php`: shell mount, page heading, responsive form sections, shared-store labels and checkbox groups.
- `resources/js/nightshift.js`: reuse existing workspace shell, administration navigation/active state, external-page navigation.
- `resources/js/business-units.js`: reload translated server-rendered labels after language preference changes.
- `resources/views/partials/language.blade.php`: full workspace translation catalog on this page.
- `resources/css/nightshift.css`: scoped Business Units grids, widths, cards and mobile controls.
- `lang/en/ui.php`, `lang/sw/ui.php`: heading and current/shared-unit labels.
- `tests/Feature/BusinessUnitTest.php`: authenticated mount, both languages/themes, form structure and forbidden access regression.
- `scripts/browser-business-unit-shell.mjs`: actual browser layout/navigation/create/permission checks.
- Existing context/branch/language browser runners were updated for checkbox assignments and shell language controls.
- This validation record.

## Validation

- Focused Business Unit suite: **30 passed, 149 assertions**.
- Full Laravel suite: **376 passed, 3,706 assertions**.
- PHPStan: **zero errors**.
- Pint, `git diff --check`, frontend tests, seven localization checks, production frontend build and asset verification passed.
- Browser matrix: **390×844, 412×915, 820×1180, 1440×1000, 1920×1080**, each in English/Kiswahili and light/dark. Checks cover content/control bounds, no horizontal overflow, mobile field stacking, drawer opening/Escape closing and desktop sidebar visibility.
- Additional real browser checks: Dashboard → Business Units, active navigation, mobile existing-unit/location controls, mobile create/save/reload, Dashboard link, browser back, sidebar → Orders, unauthorized direct request 403 and hidden management navigation; no runtime exceptions or HTTP 5xx.
- Shell browser matrix: **67 checks passed**, with no runtime exceptions or HTTP 5xx responses. The updated existing language/context/branch browser runners also passed **27 checks** (7 + 12 + 8), including staff grant/restoration and cross-branch rejection.

Local evidence: `/tmp/bu-shell-{focused,full,phpstan,pint,build,frontend,localization,browser}.log` and `/tmp/nightshift-business-unit-shell/` (JSON results and screenshots).

Post-label-update rendering tests also passed (2 tests, 50 assertions). Reconciliation passed after all assignment/transfer checks: legacy financial totals unchanged, TSh 4,500 new sales/payments, settled waiter balances and 98 physical stock units. Evidence: `/tmp/bu-shell-labels.log`, `/tmp/bu-shell-reconciliation.json`, `/tmp/bu-shell-legacy-{locales,context,branches}.log`.

Only disposable local validation data was used. No production deployment or migration was performed.
