# Explicit product availability and pricing

The product master remains company-wide. Operational availability is a positive row in `business_unit_products` with `available=true`. Missing rows mean unavailable. The former missing-row allowance and the product-manager bypass in scoped inventory caused products to appear in unrelated units. Add/Edit Product now edits this configuration directly.

## Add and edit

The existing Nightshift product modal displays authorized Business Units as responsive checkboxes and optional prices for each selling unit. Add Product preselects only the current Inventory Business Unit. With All Business Units selected, nothing is preselected; the user must select at least one unit. General is never added implicitly by this workflow. Edit reads raw company prices from a permission-protected endpoint, avoiding accidental copying of a POS override into the company default. Unchecked units stay unchecked after save.

Product commands require a `business_units` array. Entries contain `business_unit_id`, boolean `available`, and `prices` entries keyed by physical `unit_id` with nullable decimal-string `selling_price`. Existing rows outside the submitted authorized scope are preserved. Invalid prices, duplicate unit assignments, foreign companies, unauthorized branches/units, or unknown selling units roll back the entire product update. Existing manage-products, company, branch, unit and terminal permissions are retained.

## General and cutover

Migration `2026_11_11_000005_make_product_availability_explicit` is forward-only. It maps products with **no availability rows at all** to the company's existing General units. Any explicit configuration, including all-disabled rows, is preserved. It does not modify products, prices, receipts, stock, orders, payments, posted line prices or operational/reporting contexts. It is idempotent and does not enable Bar or Restaurant.

Internal legacy seed/import code that creates a Product without the new Catalog payload receives General-only mappings through the Product creation hook. The interactive Catalog workflow disables that initializer and saves only explicit choices in its existing company transaction. Integrations using the HTTP product command must send the new array. New branches do not automatically receive old products; their availability is configured explicitly.

## Inventory, POS and prices

A selected Inventory unit filters products in SQL before search and the existing 200-product workspace limit. Stock locations use the existing unit scope: private locations for that unit and explicitly mapped shared stores. Owners with company-wide product management can use All Business Units to manage the full master, including products disabled everywhere. This is necessary to re-enable them.

POS cards use the same positive assignments supplied by the backend, further restricted to the actual selected counter ID. Counter changes reload the workspace and clear the cart. Terminals are restricted server-side to their assigned company/branch/unit/counter. Backend order validation independently rejects unavailable products. Availability does not depend on positive stock; existing stock validation still blocks overselling.

Prices resolve Business Unit override → company selling-unit price. Blank overrides remove the override. Company/default values remain separately available for editing. No counter price layer is introduced. Current availability and prices are never joined into historical order lines or report classification.

## Receiving and physical stock

Receive Goods derives scope from the selected receiving location and displays its Business Unit or “Shared across Business Units” helper. Its product selector and backend receipt validation use the same positive availability data. A shared store accepts products enabled in any of its explicitly mapped units, without assigning the store to a single unit or granting POS access elsewhere. Authorized shared-store users can handle that shared pool; the helper does not enumerate other units.

Transfers and adjustments can still operate on existing positive physical stock of a disabled product, so configuration changes cannot strand stock. These selectors use location availability plus existing positive stock. Receipt authorization, ledger writes, conversion quantities, costs and balance rules remain in the existing services.

## Audit and validation

Semantic audits record PRODUCT_UNIT_ENABLED, PRODUCT_UNIT_DISABLED and BUSINESS_UNIT_PRICE_CHANGED with actor, product reference, branch/unit, previous/new values and timestamps. The existing configuration audit is retained. No secrets are included.

`tests/Feature/ProductAvailabilityTest.php` covers strict missing/General behavior, unit pricing, edit defaults, cutover, scoped search/locations, receipt rejection and expected stock movement, company/branch tampering, atomic rollback and permission denial. Existing BusinessUnitTest, workflow, terminal and MySQL concurrency tests cover posted-price/report history and backend sale/access races.

`scripts/browser-product-availability.mjs` is fail-closed to the disposable browser validation database. It exercises real product form saves, edit defaults, scoped search, receiving and assigned terminals, with English/Kiswahili, light/dark and 390/412/820/1440/1920px form checks. Screenshots and JSON evidence are written under `/tmp/nightshift-product-browser`.

The workspace still has its existing 200-product snapshot limit; receiving selectors return the authorized location's full matching set. This change introduces neither new pagination nor a new product design system.

No production deployment is part of this task.

## Files in this focused change

- `app/Models/Product.php`: safe General initializer for legacy internal creation.
- `app/Services/BusinessUnitService.php`: positive availability queries, atomic Catalog configuration, shared-location scope and semantic audits.
- `app/Services/CatalogService.php`: explicit configuration in the existing product transaction.
- `app/Services/WorkspaceService.php`: scoped inventory/search, POS availability metadata, counter/unit pricing and separate company defaults.
- `app/Services/GoodsReceiptService.php`: receiving availability enforcement before any ledger writes.
- `app/Http/Controllers/ProductConfigurationController.php`, `routes/web.php`: authorized product configuration and location-product endpoints.
- `app/Http/Requests/NightshiftCommandRequest.php`: explicit assignments and decimal-price validation.
- `app/Services/AuditTrailService.php`: readable labels for semantic availability/price events.
- `database/migrations/2026_11_11_000005_make_product_availability_explicit.php`: safe legacy General cutover.
- `resources/js/product-availability.js`, `resources/js/nightshift.js`, `resources/css/nightshift.css`: responsive integrated modal, counter filtering and receiving controls.
- `resources/views/product-business-units.blade.php`, `resources/views/business-units.blade.php`: strict missing-assignment display in existing editors.
- `lang/en/ui.php`, `lang/sw/ui.php`: integrated availability, validation and receiving labels.
- `tests/Feature/ProductAvailabilityTest.php`, `tests/Feature/BusinessUnitTest.php`, `tests/Feature/NightshiftWorkflowTest.php`: focused coverage and explicit test fixture configuration.
- `scripts/browser-product-availability.mjs`, `scripts/browser-product-availability-final.mjs`: isolated browser evidence.
- This document: cutover, behavior, files and validation evidence.

Earlier shift, cash custody, administration and PDF work remains in the working tree and is outside this focused change.

## Validation evidence (2026-11-11)

- Product-focused Laravel tests: 12 passed, 60 assertions.
- Browser matrix: 36 checks passed; final real mobile-edit/receiving flow: 11 checks passed. Both runs recorded zero runtime/server errors. Screenshots were visually reviewed for the 390px Kiswahili dark form and the desktop English light form.
- Production frontend build and build-artifact verification passed. Existing frontend Node test passed. Pint, focused JavaScript formatting and `git diff --check` passed. PHPStan passed with zero errors using `/usr/bin/php8.5`; the Herd static runtime cannot load PHPStan's worker extension.
- `scripts/product-availability-browser-verify.php` temporarily changed a currently sold product's Restaurant override to 9999 and disabled its availability inside a transaction that was rolled back. Posted line data, Daily Sales and Business Unit Performance stayed identical. All 13 baseline financial/stock/shift fingerprints matched afterward. Evidence: `/tmp/product-history-reconciliation.json`.
- Existing Bar/Restaurant paid orders still total 4500.00 at their original 2000.00/2500.00 prices. Both waiter ledgers remain settled, saved unit contexts remain intact, payments/treasury are unchanged, and location stock movement sums reconcile to 98.000000 units. Evidence: `/tmp/product-stock-reconciliation.json`.
- The positive-receipt integration test receives two units for 2000.00, verifies the exact stock/GRN movement, and proves order/payment counts do not change. The negative receipt test proves unrelated-unit receiving changes no stock or ledger rows.
- Full Laravel and MySQL runs are divided into bounded groups because the execution environment terminates long commands. MySQL groups use two separate disposable schemas; no concurrent test processes share a schema. Browser validation uses its own third schema.

The staff lifetime-accounting fixture now explicitly enables its shared product in its newly created branch's General unit. It no longer relies on missing-assignment fallback; its accounting assertions are retained.

Full Laravel coverage completed in seven groups: **407 tests passed, 3,997 assertions**. Group 6 was rerun after explicitly configuring its new-branch accounting fixture. The finalized run includes all product-specific and existing feature/unit tests.

Additional changed validation files: `tests/Feature/StaffDataAuthorizationTest.php` (explicit new-branch availability fixture) and `scripts/product-availability-browser-verify.php` (historical-price/report and financial fingerprint verification).

The MySQL server is version 8.4.11. The isolated instance briefly used `innodb_flush_log_at_trx_commit=2` during later feature tests to reduce validation disk overhead; its original value of 1 was restored before the database/concurrency group. Strict session settings and transaction isolation were retained. These checks do not simulate a host power failure.

## Final results

| Check | Result |
| --- | --- |
| Full Laravel feature/unit coverage | 407 passed, 3,997 assertions; matches PHPUnit's full discovery list |
| Full MySQL 8.4 application/database coverage | 443 passed, 5,689 assertions |
| MySQL concurrency | All 33 scenarios passed; included in the MySQL total |
| Product-focused feature tests | 12 passed, 60 assertions; included in full totals |
| Browser validation | 55 checks passed (36 matrix + 11 mobile edit/receiving + 8 custom-conversion checks) |
| English/Kiswahili, light/dark | Passed at 390, 412, 820, 1440 and 1920px for availability controls |
| Formatting, PHPStan, frontend regression checks, production build | Passed |
| Financial, historical-price/report and stock reconciliation | Passed; 13 original financial/stock/shift tables unchanged |

The final frontend review also preserves all existing selling-unit conversions when saving availability or company prices. Optional Bucket/Crate labels show the actual saved conversion instead of suggesting a standard conversion for custom products. New products continue to use the existing standard 1/6/24 defaults. A real 390px Edit save preserved custom 2-unit and 12-unit conversions, with an additional 412px Kiswahili dark-theme check.

Additional files for that protection: `resources/js/product-selling-units.js`, `tests/Frontend/ProductSellingUnits.test.mjs`, and `scripts/browser-product-conversions.mjs`.

Consolidated evidence is `/tmp/product-validation-summary.json`; individual test group JSON and JUnit results use `/tmp/product-{sqlite,mysql}-group*`. Browser evidence is `/tmp/nightshift-product-browser/{result,final-result,conversion-result}.json` with screenshots in the same directory. Test groups were isolated by schema; the slow database tests ran in four nine-test batches. All original financial rows and the single company product master were retained.

Remaining boundaries: the existing 200-product workspace snapshot limit remains; HTTP product integrations must provide explicit assignments; legacy internal model creation remains General-only as documented above. No production deployment was performed.
