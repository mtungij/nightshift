# Frontend integration fixes and PWA installation

No production deployment or production accounting write was performed. The temporary HTTPS proxy, PHP server, isolated Chrome and disposable MySQL instance were stopped after validation.

## Findings and fixes

### Shared POS Terminal → cashier My Orders

The public production login page references `app-CM_kl0ed.js`. Its downloaded contents matched the initial local bundle content and contained the My Orders button and handler. Using that same bundle with synthetic terminal data, the button was visible and opened the PIN dialog. The user clarified that the cashier appears in the selector and PIN verification succeeds, but the resulting orders are empty; this is a post-verification query/scope symptom, rather than an absent button. The evidence does not justify blaming stale JS or the missing icon for that data-loading problem.

A separate cashier-history defect was reproduced with a failing regression: existing payments can be linked to settled orders through `payment_allocations` while `payments.order_id` is null. Cashier visibility checked the direct payment source but omitted the allocation link, hiding legitimately owned paid history. `StaffVisibility::orders` and `canViewOrder` now recognize allocations belonging to payments received by that same cashier. Company, branch, counter, waiter-branch eligibility and all other ownership conditions remain. Another cashier's paid history and other counters remain excluded. My Orders retains its selected-person PIN, short-lived proof, browser-session identity and read-only behavior.

Production authenticated accounting data was not accessed, so the allocation defect is a proven reproducible code defect, not a claim that every missing production order has that exact history shape. Staff must still have an eligible matching open shift at the terminal counter.

### Cashier's own session → Receive Payment

The existing Waiter Accounts buttons emitted `data-action="waiter-payment"`; the delegated click handler had no matching branch. The payment modal and submit handler already existed, so clicks silently did nothing without a network request or console exception. The pre-fix browser runner reproduced this no-op.

The action now calls the original `waiterPaymentModal`, passing the waiter ID and partial/full mode. Receive Payment, Partial Payment and Full Settlement therefore use the existing form, CSRF handling, idempotency, command route and `PaymentService::receiveWaiter`. The existing zero-balance feedback remains, with explicit feedback for an invalid selection. Payment creation, allocation order, ledger posting, validation and settlement rules were not rewritten.

### Public icons

Read-only production checks returned HTTP 404 and an Apache HTML error body for both `/icons/icon-192.png` and `/icons/icon-512.png`. The production manifest referenced those exact paths. Both PNGs already exist in Git, decode correctly, have the required dimensions and use the Nightshift mark. They were reused without Laravel branding.

The 404 is a public-file delivery/release problem, not an invalid source PNG and not an explanation for the missing payment handler. The prepared release includes the manifest, worker, icons and Vite output checks. The live URLs cannot be changed without deploying/publishing the corrected public files; this task intentionally did not deploy.

## PWA behavior

`public/manifest.webmanifest` now includes stable app ID and scope `/`, name/short name Nightshift, start URL `/`, standalone display, colors, description and correctly typed 192/512 PNG icons. URLs are root-relative. Login, role landing pages and shift requirements retain their existing behavior.

Authenticated workspace users get **Install App / Sakinisha App** in the account menu only after the browser provides a usable `beforeinstallprompt`, and only outside installed/standalone mode. One prompt is consumed per event; duplicate clicks cannot prompt twice. Acceptance and `appinstalled` hide the action. Dismissal, unsupported browsers, registration failure and storage restrictions do not block POS. Installation text follows the current EN/SW catalog.

`public/sw.js` handles only an explicit list of public branding/manifest files and fingerprinted CSS, JS and fonts under `/build/assets`. It never intercepts navigations or non-GET requests, and never caches APIs, orders, payments, PIN checks, My Orders, sessions, login/logout, receipts, reports, stock or reconciliation. Dynamic business operations remain network-authoritative and fail normally offline. Cached HTML and offline transactions are not offered. Cache failures cannot hide a successful static network response. Worker updates do not reload an active POS or discard carts. Only older Nightshift static caches are removed.

## HTTPS and trusted proxies

`TrustTraefikProxies` reads `TRUSTED_PROXIES` from cached Laravel configuration. Set this to the actual Traefik IP/CIDR list for the deployment; loopback is the default. Forwarded protocol, port and client IP are trusted only from those proxies. Forwarded Host is not trusted. Traefik should pass the real Host header.

An HTTPS `APP_URL` also keeps generated URLs HTTPS on an HTTP backend connection. Keep `APP_URL` and `ASSET_URL` set to the canonical HTTPS origin. Client requests, worker registration and manifest icon/start/scope URLs are same-origin/root-relative. Apache MIME and revalidation directives are supplied for the manifest and worker.

## Build and publication checks

`npm run build` now verifies every referenced Vite entry/font, the required current frontend features, worker, manifest and PNG dimensions. It fails if `public/hot` exists. It writes `public/build/release.json` for comparing the current entry names and required public assets with rendered HTML. `npm run frontend:check` repeats the checks.

Publish the entire current `public/` tree, including `icons/`, manifest and worker, together with `public/build/`; copying only `build/` does not publish the PWA icons. The web server's document root/static-file mapping must point at that same release's public directory. Preserve Apache rewrite handling for application routes.

The requested release sequence is supported:

```sh
npm install
npm run build
php artisan optimize:clear
php artisan config:cache
php artisan view:cache
npm run frontend:check
node scripts/check-public-assets.mjs https://barledger.site
```

Run the final HTTP checker after a separately authorized deployment. It checks 200 responses, PNG MIME/signatures/dimensions, manifest/worker MIME, current entry references in login HTML and absence of HTTP resource/form URLs. Refresh long-lived browser tabs after deploying frontend changes; this is not presented as the root cause of the clarified cashier history defect.

## Changed files

- `app/Domain/StaffVisibility.php`: allocation-based cashier ownership for read queries and policy checks.
- `app/Http/Middleware/TrustTraefikProxies.php`, `config/proxy.php`, `bootstrap/app.php`, `.env.example`: configured proxy trust and HTTPS URL handling.
- `resources/js/nightshift.js`: waiter payment click wiring and conditional install action in the account menu.
- `resources/js/pwa.js`, `resources/js/app.js`: install lifecycle and safe worker registration.
- `public/sw.js`, `public/manifest.webmanifest`, `public/.htaccess`, `resources/views/partials/brand-icons.blade.php`: public PWA shell and metadata. Existing tracked `public/icons/icon-192.png` and `icon-512.png` are retained and validated.
- `lang/en/ui.php`, `lang/sw/ui.php`: install/error strings.
- `package.json`, `scripts/verify-frontend-build.mjs`, `scripts/check-public-assets.mjs`: build/release and HTTP asset validation.
- `tests/Feature/FrontendDeliveryTest.php`, `tests/Feature/MyOrdersTest.php`, `tests/Frontend/pwa.test.mjs`: delivery/proxy/validation, historical cashier ownership and install/cache regressions.
- `scripts/my-orders-browser-fixtures.php`, `scripts/browser-my-orders-smoke.mjs`, `scripts/browser-frontend-pwa-smoke.mjs`, `scripts/verify-frontend-payments.php`: isolated browser, historical payment and accounting checks.
- `docs/frontend-pwa.md`: this report.

No financial migration, new role permission, price/stock logic or production deployment is included.

## Validation

- Before-fix reproduction: production-identical JS shows and opens My Orders; own-session Receive Payment does not open a modal.
- Cashier allocation-history regression failed before the read fix and passed afterward, including denial for another cashier and consistent receipt authorization.
- Targeted PHP tests: **32 passed, 429 assertions**.
- Full Laravel/SQLite suite: **309 passed, 3,339 assertions**.
- PHPStan passed; PHP/JS formatting, localization and production build checks passed.
- Six frontend install/worker behavior tests passed, including duplicate prompts, localization, unsupported browsers, dynamic-data exclusion, redirect/HTML exclusion and unavailable storage.
- `npm install`, production build, `optimize:clear`, `config:cache`, and `view:cache` were checked using the disposable test profile and temporary config-cache paths. The normal application config cache was not repointed to the test database.
- Full MySQL **8.4.11** suite: **325 passed, 4,526 assertions**, including concurrency and authorization regressions.
- Prepared-release HTTPS public-asset check passed: manifest, 192/512 PNGs, worker and current rendered Vite entries all return 200 with valid MIME/content. No mixed HTTP resource or form URLs were found.
- My Orders HTTPS desktop/mobile EN/SW regression: **99 checks passed**, including cashier allocated history after selected-person PIN verification, other-cashier/counter denial, temporary identity, individual login, filtering, notes and unchanged accounting.
- Cashier payment/PWA HTTPS desktop/mobile run: **38 checks passed**. Receive/partial/full actions open the existing form; an overpayment is rejected with no balance change; valid payments submit to the original HTTPS endpoint with CSRF. Chrome reports no manifest errors and no installability errors on either viewport. Install labels, single-prompt handling, `appinstalled`, static-only caches, business-response bypass and offline network authority passed. **Zero browser runtime exceptions or HTTP 5xx**.
- The two synthetic TSh 1,000 payments produced **two payment rows totaling TSh 2,000**, matched by TSh 2,000 in allocation and ledger-credit deltas. No orders or stock movements were created, stock balances were unchanged, and cashier reconciliation reported **zero ledger mismatches**.
- My Orders alone left all 10 orders, 71 stock movements, 14 ledger entries, 5 payments, 5 allocations, zero print batches, stock quantities and ledger totals unchanged.
- Final screenshots of the payment modal and localized install menu were visually reviewed at 1440px and 390px; six layout/runtime checks passed. Both PNGs additionally passed full decode/integrity validation.

Artifacts: `/tmp/nightshift-frontend-before/baseline.json`, `/tmp/nightshift-pwa-https-assets.json`, `/tmp/nightshift-my-orders-pwa-regression/result.json`, `/tmp/nightshift-frontend-pwa-browser/result.json`, `/tmp/nightshift-pwa-my-orders-reconciliation.json`, `/tmp/nightshift-pwa-payment-reconciliation.json`. Screenshots are alongside the browser results, including `390-sw-payment-modal.png` and `390-sw-install-menu.png`. The final visual checks are in `/tmp/nightshift-frontend-pwa-browser/visual-result.json`.

## Browser and deployment limits

The original authenticated production failure could not be exercised without a production session, and no live financial request was submitted. Prepared-release browser validation uses synthetic accounts and a local HTTPS reverse proxy forwarding to an HTTP backend. Actual OS-level installation UI is browser-managed; headless checks validate manifest installability and test the event lifecycle without installing an application on the user's desktop. Safari/iOS versions without `beforeinstallprompt` use their own Share/Add to Home Screen flow, and the custom prompt action stays hidden. Offline accounting is deliberately unsupported. Production's icon 404 will remain until the corrected public assets/static mapping are published.
