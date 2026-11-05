# Nightshift sales ticket printing

Paper widths: **58mm and 80mm**, with 80mm preserved as the default. See [58mm layout, validation and printer setup](sales-ticket-paper-widths.md).

Sending an order to the cashier still uses the existing PIN, idempotency, stock, waiter ledger and shift services. `OrderService` and the financial transaction services are unchanged. The controller registers an `afterCommit` callback after a successful send. Printing uses its own transaction; a preparation or audit failure cannot roll back the sale or turn its successful response into a failed sale.

## Company preferences

Workspace Settings → Sales ticket printing requires the existing `manage-company` permission. Preferences live under `companies.settings.sales_print`; other company settings are preserved.

| Preference | Default |
| --- | --- |
| Auto Print After Send to Cashier | On |
| Print Waiter Copy | On |
| Print Cashier Copy | On |
| Number of Copies | 2 |
| Paper Width | 80mm |

The number is the total tickets per batch, from 1 to 10. Enabled types alternate, beginning with the waiter copy. The count must be at least the number of enabled types, so enabling both types cannot silently omit the cashier copy. Disabling both types suppresses printing. Manual reprinting remains available when automatic printing is off, using the enabled types and count.

## Tickets and reprinting

The waiter copy includes subtotal, discount, total and the waiter's outstanding immediately after this order. The cashier copy includes item prices, line totals and the issued/unpaid status. Both include company, branch, original invoice number, local date/time, waiter, table and counter. Zero-value orders retain their settled status.

The outstanding is calculated through the order's ISSUE ledger entry, so subsequent orders and settlements do not change the historical figure. Existing staff/branch/counter visibility applies when displaying it. The original ISSUE debit supplies the original ticket total even if a legacy order has since been returned. These are issue tickets; use the existing receipt for current payment information.

Authorized users can open an issued order and select **Reprint sales tickets**. This creates a separate batch marked **REPRINT**, preserving the original invoice number. It never calls issue, payment or ledger posting services. Existing order access permissions apply, including waiter ownership, branch, counter and tenant restrictions.

Initial batches are unique per company/order. Reprint retries are unique per company/request UUID and cannot reuse the key for another order or user. A locked server-side dispatch claim allows one browser print request per batch, including across tabs. Repeated page reads do not mutate accounting or create new print events. To recover from a cancelled dialog, select Reprint again.

Audit events record the order, actor, timestamp, copy type, copy number, batch token and reprint flag. `SALES_TICKET_PRINT` / `SALES_TICKET_REPRINT` record batch requests; `SALES_TICKET_DISPATCH` records the browser dispatch claim. These events do not claim that a physical printer completed printing.

## Layout and browser behavior

Tickets use independent white/black styling with compact text and no graphics or external fonts. The 80mm option retains its 72mm printable area and 4mm page margins; the dedicated 58mm option uses stacked content in a 48mm safe area. Each copy starts on a separate print page. Valid CSS page dimensions are fitted to the longest ticket. English and Kiswahili labels follow the existing language preference.

Automatic printing opens the browser/system print dialog after the committed ticket loads. Browser policy, mobile OS support, printer selection, driver paper size, paper cutting and physical output depend on the workstation and printer. Silent printing and physical completion cannot be guaranteed by a web page. Select the matching 58mm or 80mm paper and one copy in the driver: the document already contains the configured copies. See the paper-width guide for scale and margin settings.

## Validation

The new feature tests use real commits rather than wrapping every test in an uncommitted transaction. MySQL concurrency tests use independent worker connections. The browser runner uses the guarded disposable MySQL server and an isolated Chrome profile; it captures desktop/mobile screenshots and 80mm PDFs while counting print calls instead of opening a physical printer dialog.

```sh
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
php artisan test --compact
vendor/bin/phpunit --configuration phpunit.mysql.xml
npm run build
php scripts/audit-mysql-schema.php
```

For browser validation, first finish database tests, migrate the disposable MySQL profile and run `php scripts/sales-ticket-browser-fixtures.php`. Start the guarded server using `scripts/mysql-validation-server.php`, launch an isolated headless Chrome on debug port 9224, then run `node scripts/browser-sales-tickets-smoke.mjs`. Artifacts now default to `/tmp/nightshift-80mm-ticket-browser` or `/tmp/nightshift-58mm-ticket-browser`, according to `SMOKE_PAPER_WIDTH`; the original validation artifacts below retain their historical paths.

No production deployment is included. The new `sales_ticket_batches` migration must be applied through the normal release process before using the feature.

## Changed files

- `app/Http/Controllers/NightshiftController.php`: after-commit printing, reprint and dispatch endpoints.
- `app/Http/Requests/NightshiftCommandRequest.php`: print settings and reprint validation.
- `app/Models/SalesTicketBatch.php`: tenant/branch-scoped print batches.
- `app/Services/SalesTicketService.php`: settings, copy selection, original ticket data, historical balances, audit and dispatch claims.
- `app/Services/WorkspaceService.php`: company print preferences in workspace data.
- `database/migrations/2026_11_03_000001_create_sales_ticket_batches.php`: print batch storage with tenant foreign keys.
- `lang/en/ui.php`, `lang/sw/ui.php`: ticket and settings translations.
- `resources/js/nightshift.js`: settings controls, automatic printing and order reprint action.
- `resources/views/issue-ticket.blade.php`: two-copy 80mm layout, labels, print sizing and dispatch.
- `routes/web.php`: authenticated ticket routes.
- `tests/Feature/SalesTicketPrintingTest.php`: commit, failure, settings, authorization, reprint and accounting regression coverage.
- `tests/MySql/ConcurrencyTest.php`, `tests/MySql/transaction-worker.php`: simultaneous send/reprint/dispatch checks.
- `tests/Unit/MySqlSchemaCompatibilityTest.php`: includes the two print-batch JSON columns in the existing schema audit.
- `scripts/sales-ticket-browser-fixtures.php`: guarded disposable MySQL browser fixtures.
- `scripts/browser-sales-tickets-smoke.mjs`: desktop/mobile/theme/language and print-PDF validation.
- `docs/sales-ticket-printing.md`: implementation and validation notes.

## Validation results — 3 November 2026

- Laravel/SQLite: **232 tests, 2,422 assertions passed**.
- MySQL 8.4.11: **245 tests, 3,513 assertions passed**, including independent-process send, reprint and dispatch races and the existing immutable-history trigger checks.
- After the final print-spacing adjustment, the 19 ticket feature tests passed again with 145 assertions.
- PHPStan level 7: **0 errors**. Pint and JavaScript formatting checks passed. Production frontend build passed.
- Offline MySQL schema audit: 18 migrations, 48 tables, 134 foreign keys; no errors.

The existing validation server rejected creation of the existing history triggers with MySQL error 1419 because binary logging was enabled and the dedicated account lacked the required server privilege. Validation therefore used a separate temporary MySQL 8.4.11 instance on port 3340, with binary logging disabled only there. The test account remained non-root, and all original history triggers and strict InnoDB checks remained enabled. No existing server settings/accounts or `.env.testing.mysql` values were changed. The command was `MYSQL_VALIDATION_PORT=3340 vendor/bin/phpunit --configuration phpunit.mysql.xml`.

Final Chrome validation passed **79 checks** at 1440px desktop and 390px mobile widths, covering both themes and English/Kiswahili. The final run verified one stock issue/ledger debit per send, no ticket after failed PIN, two correct copy labels, one dispatch per batch, no wrapped monetary amounts, authorized REPRINT labels, and zero browser exceptions or HTTP 5xx responses.

Nine generated PDFs passed inspection: **18 pages total**, exactly two pages per document, 80mm CSS paper width (Chrome reports approximately 80.09mm after pixel rounding), correct labels on the respective pages, no waiter outstanding on cashier pages, no printed control buttons, and all text within page boundaries. The final reprint PDF and Kiswahili mobile screenshot were visually reviewed. Physical printer output was not tested.

Post-browser MySQL reconciliation passed with **zero negative stock rows, zero stock/movement discrepancies and zero waiter-ledger/invoice-balance discrepancies**.

Local review artifacts:

- [Browser check results](/tmp/nightshift-ticket-browser/result.json)
- [PDF validation results](/tmp/nightshift-ticket-browser/print-validation.json)
- [MySQL reconciliation](/tmp/nightshift-ticket-browser/mysql-reconciliation.json)
- [English ticket PDF](/tmp/nightshift-ticket-browser/1440-light-en.pdf)
- [Kiswahili ticket PDF](/tmp/nightshift-ticket-browser/390-dark-sw.pdf)
- [Reprint PDF](/tmp/nightshift-ticket-browser/reprint.pdf)

Cleanup: the temporary web server and isolated Chrome were stopped. The temporary MySQL instance remains bound to `127.0.0.1:3340` (PID 190822): a normal termination signal was denied, and `sudo -n` requires interactive authentication. It can be stopped with `sudo kill -TERM 190822`. The existing MySQL service is a separate process and was not stopped.
