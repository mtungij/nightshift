# My Orders / Oda Zangu

My Orders is a read-only POS dialog for a PIN-verified waiter or cashier. A shared POS Terminal remains the authenticated browser account throughout the workflow. The original terminal, individual waiter/cashier, payment, shift, printing and session permissions are retained.

## Using My Orders

Open **POS → My Orders** (**Oda Zangu**). Select an eligible waiter or cashier, enter that person's four-digit PIN, and choose **View My Orders** (**Ona Oda Zangu**). Only active staff with a configured PIN, relevant order permissions, branch membership and the correct open waiter/cashier shift at this counter appear. Individual waiter/cashier sessions offer only their own identity and still require their PIN.

The verified dialog shows invoice, date/time, table, counter, optional Order Note, total, amount paid, balance and existing order status. Cashiers also see the responsible waiter. Notes and names are escaped as business data. **All / Unpaid / Paid** become **Zote / Hazijalipwa / Zimelipwa**. Unpaid includes issued orders with a positive balance, including partial payments and outstanding void requests; Paid uses the existing `PAID` state. All preserves other states such as drafts and voids. No payment status is stored or recalculated by this feature.

**Close My Orders / Funga Oda Zangu**, the dialog's close button, Cancel, Escape and the backdrop clear the private dialog and its verification. The POS cart remains intact. Refresh and logout also clear verification. A five-minute fixed verification lifetime closes the private view without logging out or locking the POS session. This does not change the existing 960-minute browser session lifetime.

## Authorization and privacy

The dedicated endpoints are under `/api/nightshift/my-orders`: staff eligibility, PIN verification, paginated order list, individual order read and explicit close. Every protected read requires the current short-lived proof in a request header. There is no authenticated identity or staff ID accepted from a filter as an authorization source.

Opening a view generates a random challenge tied to the existing Laravel session, browser actor, company and counter. Successful PIN verification rotates a separate random access proof. Only proof hashes, identity/scope IDs, shift ID, expiry and a fingerprint of the existing PIN hash are stored server-side. Plaintext PINs are neither stored nor audited. The browser keeps proofs only in memory, never in URLs, local storage or session storage.

The server revalidates the authenticated actor, company, branch, active counter, eligible staff role/permissions/assignment, open shift and current PIN fingerprint for every order read. Waiter orders are constrained by `waiter_id`. Cashier reads reuse `StaffVisibility::orders`, with an additional restriction to the verified counter and branch. This retains permitted unpaid settlement activity and the cashier's own created/issued/received-payment history; it does not grant another cashier's restricted counter or paid history.

`TenantContext::withUserScope` changes only the Eloquent tenant/visibility scope within a `try/finally` block. It does not call Laravel login, switch the authentication guard, dispatch a login event, or persist the selected identity as the browser user. Existing order, print, payment and management endpoints do not accept the My Orders proof and retain their original authorization.

One temporary view is active per Laravel session. A new view replaces the previous view. Session blocking serializes view operations and dashboard refreshes. A delayed close from an older view cannot revoke a newer view. Reads never extend the expiry. PIN resets, staff deactivation, branch removal, changed shifts or lost permissions prevent subsequent reads. Responses use `private, no-store`, and the client ignores late responses after close or replacement. Page exit clears the DOM and sends a close beacon, including for back/forward cache navigation.

Security audit events `MY_ORDERS_VERIFIED` and `MY_ORDERS_PIN_FAILED` retain the selected staff ID, browser/terminal actor ID, counter, branch and existing audit timestamp. The original PIN verifier retains persistent failure counters, lockout and rate limiting. Viewing orders creates no orders, payments, allocations, stock movements, ledger entries or print batches.

## Files changed for this feature

New application files:

- `app/Services/MyOrdersService.php`
- `app/Http/Controllers/MyOrdersController.php`

Existing application files:

- `app/Domain/TenantContext.php`: scoped reads without switching Laravel authentication.
- `app/Livewire/Workspace.php`: clear the temporary view on dashboard refresh.
- `app/Http/Middleware/RestrictPosTerminal.php`: allow only the dedicated My Orders read/verify/close endpoints in addition to the existing terminal routes.
- `routes/web.php`: authenticated routes, verification throttling and session blocking.
- `resources/js/nightshift.js`: POS action, compact PIN form, private dialog, filtering, pagination, close/expiry/refresh privacy behavior.
- `resources/css/nightshift.css`: responsive order cards and amounts.
- `lang/en/ui.php`, `lang/sw/ui.php`: English and Swahili presentation strings.

Validation files:

- `tests/Feature/MyOrdersTest.php`
- `scripts/my-orders-browser-fixtures.php`
- `scripts/browser-my-orders-smoke.mjs`
- `scripts/my-orders-browser-verify.php`
- `docs/my-orders.md`

No migration or new role permission is needed for My Orders. The earlier Shared POS Terminal implementation remains in place. Nothing has been deployed to production.

## Validation

- My Orders feature tests: **15 passed, 253 assertions**.
- Full Laravel/SQLite suite: **302 passed, 3,284 assertions**.
- PHPStan level 7, PHP Pint, JavaScript formatting, localization checks and the production frontend build passed.
- Full MySQL **8.4.11** suite: **318 passed, 4,471 assertions**, including concurrency tests.
- Browser workflow: **99 checks passed**, covering 1440px desktop and 390px mobile in EN/SW; shared-terminal waiter/cashier selection and PIN isolation; individual waiter/cashier login; filters and partial payments; note escaping; direct-ID/counter denial; close and refresh revocation; cart preservation; and view timer expiry without logging out the browser account. **Zero browser exceptions and zero HTTP 5xx**.
- Final visual/layout checks: **6 checks passed** after tightening note spacing and applying existing status colors. Desktop and mobile screenshots were visually reviewed; both fit without horizontal overflow.
- Accounting comparison passed: **9 orders, 70 stock movements, 12 ledger entries, 4 payments, 4 allocations and 0 print batches remained unchanged**. Every stock balance and total ledger debits/credits also remained unchanged. Existing cashier reconciliation reported **zero ledger mismatches**.
- Security audits recorded successful and failed selected-staff PIN verifications with the browser actor and counter, without PIN or access-proof values.

Artifacts: `/tmp/nightshift-my-orders-browser/result.json`, `/tmp/nightshift-my-orders-browser/layout-result.json`, screenshots in the same directory, and `/tmp/nightshift-my-orders-reconciliation.json`. Final compact-layout screenshots are `1440-en-cashier-final.png` and `390-sw-cashier-final.png`.

Validation used the disposable MySQL 8.4.11 instance on localhost:3340, the guarded router on localhost:8884 and an isolated headless Chrome profile on port 9224. The existing MySQL service and normal application database were not used for these tests. Local stale route/config caches were cleared so the new endpoints could be exercised. The temporary PHP server, isolated Chrome and disposable MySQL instance were stopped after verification.

The unit/feature tests cover eligible waiter/cashier selection; inactive, foreign-company, wrong-branch and wrong-counter exclusions; selected-person PIN matching and lockout; waiter ownership and direct-ID/filter tampering; cashier counter/paid-history restrictions; partial payment and status filters; notes; no accounting/print side effects; session/actor binding; stale proofs; close, refresh, expiry and logout; PIN/shift/staff revocation; individual login behavior; security audits and credential secrecy.

Browser fixtures and the browser verifier fail closed unless they target the disposable `nightshift_validation` MySQL database on port 3340. Run after the full MySQL suite finishes, with the existing guarded local router on port 8884 and isolated Chrome debug port 9224:

```sh
MYSQL_VALIDATION_PORT=3340 php scripts/my-orders-browser-fixtures.php > /tmp/nightshift-my-orders-fixtures.json
node scripts/browser-my-orders-smoke.mjs
MYSQL_VALIDATION_PORT=3340 php scripts/my-orders-browser-verify.php
```

## Limits

A selected staff member must have an open matching operational shift at the current counter. Historical orders outside that verified counter remain outside this view. The dialog is read-only; existing settlement and printing remain in their original workflows. There is one temporary verification per browser session, so opening another view invalidates older proofs. Browser validation uses synthetic accounts and emulated desktop/mobile viewports.
