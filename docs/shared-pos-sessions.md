# Shared POS sessions

Nightshift no longer locks staff workspaces after 15 minutes of inactivity. Existing authenticated sessions follow Laravel's unchanged idle session lifetime: `SESSION_LIFETIME=960` (16 hours). Normal authenticated requests renew that lifetime. Manual logout invalidates the session and regenerates its CSRF token. An expired session returns to login; there is no new heartbeat to keep an abandoned session alive indefinitely.

The repository `.env`, `.env.example` and configuration fallback all retain 960 minutes. Production was not accessed or deployed; its effective environment/config cache must still contain that value when deployment is performed.

## Removed behavior

- GET/POST `/quick-lock` and POST `/quick-lock/unlock`, their controller, middleware and Blade page.
- The workspace lock button, action handler, lock-only CSS, English/Kiswahili labels and references in PIN guidance.
- Browser inactivity tracking, cross-tab activity channel, automatic lock redirects and lock-response handling in workspace, language and WhatsApp JavaScript.
- The lock-only `/api/nightshift/activity` heartbeat, bootstrap `quickLock` payload and 15-minute configuration.
- Obsolete lock/unlock tests and steps in the existing browser scripts.

No database migration or column deletion is required. `operational_pin`, `pin_failed_attempts` and `pin_locked_until` remain because login and per-order confirmation share them. Existing session-bag keys `staff_locked` and `staff_last_activity` are ignored and disappear with normal session expiry/logout. PIN lockout after failed attempts remains a separate, unchanged security behavior.

## Waiter accountability

A shared cashier session can select a serving waiter, but sending the order still verifies that selected waiter's own four-digit PIN. Another waiter's PIN fails without issuing stock, creating an order or charging the ledger. A waiter role cannot submit as a different waiter. Successful confirmation attributes the order and ledger debit to the selected waiter while preserving the cashier's browser session. Existing shift, tenancy, permission, idempotency, payment and printing checks remain in place.

Order, PIN, waiter-ledger, shift and ticket service files were hash-checked against the task-start versions and are unchanged. Printing still follows commit, produces the configured two copies, suppresses duplicate claims and isolates reprints.

## Validation

See the validation results recorded below. The automated suite exercises persistent database sessions before and after their 960-minute expiry, stale lock flags, session invalidation and CSRF. Existing suites cover role/tenant boundaries, PIN lockouts, order failures, stock, ledgers, payments, print batches and reprints. The browser runner uses isolated MySQL fixtures, desktop/mobile viewports and a simulated 21-minute client-clock advance; it does not claim a 16-hour real-time browser soak.

Only negative regression assertions, this removal record and historical validation material reference the removed feature. Application routes, source UI, translations and current operational instructions contain no lock-page link or behavior. No deployment was performed.

## Files changed

Removed: `app/Http/Controllers/QuickLockController.php`, `app/Http/Middleware/StaffQuickLock.php`, `resources/views/quick-lock.blade.php`.

Runtime/configuration/UI: `app/Services/WorkspaceService.php`, `bootstrap/app.php`, `config/session.php`, `routes/web.php`, `resources/css/app.css`, `resources/js/app.js`, `resources/js/nightshift.js`, `resources/js/whatsapp.js`, `lang/en/ui.php`, `lang/sw/ui.php`, `lang/sw.json`.

Tests: `tests/Feature/SharedPosSessionTest.php` (new), `tests/Feature/WaiterPinWorkflowTest.php`, `tests/Feature/UsernamePinAuthenticationTest.php`, `tests/Feature/StaffCreationPinTest.php`, `tests/Feature/AuthBrandingTest.php`.

Browser tooling: `scripts/browser-shared-pos-smoke.mjs` and `scripts/shared-pos-browser-fixtures.php` (new), `scripts/browser-auth-branding-smoke.mjs`, `scripts/browser-pin-workflow-smoke.mjs`, `scripts/browser-staff-data-smoke.mjs`, `scripts/browser-username-pin-smoke.mjs`.

Documentation: `README.md`, `docs/auth-branding-cleanup.md`, `docs/shared-pos-sessions.md`.

### Results

- Laravel suite: **248 tests / 2,585 assertions passed**.
- MySQL **8.4.11** suite: **261 tests / 3,676 assertions passed**, including concurrency, accounting, print claim and reprint coverage. Isolated database: `nightshift_validation`, port 3340; production was untouched.
- Language checks after removing the final obsolete labels: **8 tests / 64 assertions passed**.
- Pint formatting and frontend formatting checks passed; PHPStan passed with **0 errors**; production frontend build passed; `git diff --check` passed.
- Shared-POS Chrome smoke: **28 checks passed**, desktop 1440px and mobile 390px, zero runtime exceptions or HTTP 5xx. Wrong cross-waiter PIN produced no order/stock change/print; correct PIN produced one issue, the selected waiter's debit and two ticket copies; operator identity persisted; simulated 21-minute inactivity did not redirect; logout and CSRF token fallback passed.

Browser evidence: `/tmp/nightshift-shared-pos-browser/result.json`, `/tmp/nightshift-shared-pos-browser/1440-shared-pos.png`, `/tmp/nightshift-shared-pos-browser/390-shared-pos.png`. Printing is instrumented at the browser print call; no physical printer test or production access was performed.

The existing authentication/branding browser matrix also passed: **139 checks**, including Owner/Admin, Manager, Cashier, Waiter and Store Keeper PIN login on desktop/mobile; English/Kiswahili; light/dark; settings, onboarding/recovery, and icons. Result: `/tmp/nightshift-shared-pos-auth-browser/result.json`. Combined browser validation: **167 passing checks**, zero runtime errors or HTTP 5xx.
