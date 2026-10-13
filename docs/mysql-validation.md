# MySQL 8.4 validation preparation

Status on 9 October 2026: **MySQL 8.4.11 validation PASS** against `nightshift_validation` using `nightshift_user`. All nine migrations, all seeders, 88 tests / 1,652 assertions, four independent-process concurrency tests, 79 desktop/mobile browser checks and final stock/ledger reconciliation passed. The corresponding SQLite suite passes 81 tests / 712 assertions. See the [validation report](mysql-validation-result.json) and [evidence](mysql-validation-evidence/). No migration definitions, MySQL accounts, root authentication or global server settings were changed by the agent. The administrator enabled trigger creation after the initial binary-log policy failure.

Browser testing exposed an unordered MySQL location query choosing a counter without the actor's open shift. Workspace selection now orders locations by ID, prefers the actor's open-shift counter and returns canonical numeric location IDs. The original `.env` and `phpunit.xml` remain unchanged. Early browser attempts reached the development SQLite database because `artisan serve` dropped the validation environment in its child process; the two added SQLite test orders were retained at the user's explicit request. The final browser PASS comes exclusively from the guarded MySQL server below.

## Audit findings

The reproducible offline audit compiles the **actual nine migration files** with Laravel's MySQL grammar, including the MySQL-only CHECK constraints and history triggers. It records earlier columns and foreign keys so conditional follow-up migrations follow the same path as a fresh schema. Its PDO factory throws if anything tries to connect. This validates generated DDL structure and bounds; it is not a MySQL server syntax/execution test.

```bash
php scripts/audit-mysql-schema.php
php scripts/audit-mysql-schema.php --sql
php artisan test --filter=MySqlSchemaCompatibilityTest
```

Reviewable generated artifacts: [schema audit](mysql-schema-audit.json), [MySQL DDL](mysql-schema.sql). Regenerate them after changing migrations.

| Check | Result |
| --- | --- |
| Migration files / tables / DDL statements | 9 / 43 / 274 |
| Foreign keys | 121; referenced columns match an exact primary or unique key, with matching types and signedness |
| Declared indexes | 129; utf8mb4 key widths fit the standard 16KiB InnoDB page limit |
| Identifier lengths | Maximum 48 characters: `branch_user_company_id_branch_id_user_id_primary`; all table, column, index, FK, CHECK, and trigger identifiers fit 64 characters |
| Foreign-key names | No duplicate names across the schema |
| Monetary storage | All 29 monetary columns are `DECIMAL(16,2)`; no FLOAT or DOUBLE columns |
| Quantities / conversions | `DECIMAL(18,6)` |
| Status fields | VARCHAR with service/Form Request validation; no database ENUM dependency |
| JSON fields | Seven native JSON columns; Laravel array casts or explicit JSON encode/decode; no indexed JSON values or unsupported JSON defaults |
| Timestamps | Explicit nullable/default semantics; ordinary MySQL TIMESTAMP/DATE columns; validation session uses `+03:00` to match the application |
| Uniqueness | Company-scoped invoice/document numbers, SKU, units, stock location/product, supplier invoice, payment allocation, and idempotency keys; tenant `(company_id,id)` keys are explicitly UNIQUE |
| CHECK constraints / immutable triggers | Three CHECK constraints and fourteen triggers use MySQL 8.4 syntax; runtime enforcement remains pending |
| Application SQL | Standard SUM/COALESCE/arithmetic/grouping, joins, and Laravel-generated SQL; no PRAGMA, ROWID, strftime, SQLite JSON functions, or SQLite-only conflict clauses |

The audit assumes InnoDB DYNAMIC row format with pages of at least 16KiB, matching the normal MySQL 8.4 configuration. The preflight checks the actual server rather than changing it. The largest index estimate is conservative: timestamp keys are counted as eight bytes. Every grouped report selects only grouped values and aggregates, compatible with ONLY_FULL_GROUP_BY. `insertOrIgnore` for document sequences is compiled by Laravel for each engine; the company/sequence lock and unique constraint provide serialization. MySQL INSERT IGNORE can downgrade some errors, so it must remain limited to that controlled sequence initialization.

MySQL limits: [identifier lengths](https://dev.mysql.com/doc/refman/8.4/en/identifier-length.html), [InnoDB key limits](https://dev.mysql.com/doc/refman/8.4/en/innodb-limits.html), [foreign keys](https://dev.mysql.com/doc/refman/8.4/en/create-table-foreign-keys.html), [DECIMAL](https://dev.mysql.com/doc/refman/8.4/en/fixed-point-types.html).

## InnoDB transaction review

`TransactionService::run()` opens a retryable transaction and locks the company's primary-key row with `SELECT ... FOR UPDATE` before invoking the operation. The company row is an intentional serialization point for all application mutations within that tenant. Different companies use different locks. Locks last until the outermost transaction commits or rolls back; nested service transactions do not release them. Laravel retries recognized concurrency failures up to five times at the outer transaction boundary.

| Workflow | Locking and atomic posting |
| --- | --- |
| Stock issue | Company → order → cashier shift → waiter shift → stock rows. Order status is reread under a lock. Product deductions are ordered by product ID. All item movements, issue actor/shift metadata, status and waiter liability commit together. Repeated issue returns the issued document without another deduction. |
| Payment | Company → order → cashier shift. Positive amounts and total allocations are checked against the locked balance; immutable payments, allocations and ledger entries commit with the updated invoice. UUID retries return the stored result under the same company lock. |
| Transfer send / receipt | Company → transfer → relevant stock rows ordered by product ID. Send debits the source once; receipt credits the destination once. No uncommitted transit state or half-posted movement can be observed as committed data. |
| Return | Company → order → cashier shift → order items → stock rows. Remaining returnable quantity and monetary value are validated against locked items. Return documents, stock, item return counters, invoice values and ledger credit post together. |
| Void / refund | Company → void request → order → stock rows, plus cashier shift for paid refunds. Only a pending approval posts reversals. Original stock/payment/ledger records remain immutable; refunds and liability reversals are new records. |
| Shift closure | Company → shift. Ledger and cash totals are read after the company serialization lock; competing issue/payment mutations cannot alter them until closure commits. Overrides retain debt and record the reason/actor. |
| Numbering | Company → company/type sequence, plus a UNIQUE(company_id,type) key. The document and number advance together in the same transaction. |

All these paths reach stock mutation through `StockService`, which requires an active transaction, locks the balance row and rejects a negative result. First creation of a stock row is also inside the company-serialized transaction. No SKIP LOCKED or NOWAIT behavior is used: a competing cashier waits or receives a retried transaction failure. Application code should not bypass the services with direct model writes. Company serialization limits per-tenant write throughput; assess contention during validation before changing this policy.

Under InnoDB REPEATABLE READ, locking reads see current records. The first company lock precedes business reads inside the command transaction, preventing an earlier tenant mutation from being validated against a stale pre-lock snapshot. The dedicated test profile explicitly uses this isolation level. See [locking reads](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html) and [deadlocks](https://dev.mysql.com/doc/refman/8.4/en/innodb-deadlocks.html).

## Separate validation environment

Default SQLite development and `php artisan test` continue to use the existing configuration. MySQL uses the new `mysql_validation` connection and **separate credentials**, with no DB_URL, root username, or SQLite fallback. The validation connection explicitly selects InnoDB, strict SQL mode, REPEATABLE READ and session timezone `+03:00`. Session settings do not modify global server settings. The original normal `mysql` connection is unchanged; carry the validated engine/timezone/isolation settings into the deployment configuration when cutting over.

After the dedicated account is supplied:

1. Copy `.env.testing.mysql.example` to the gitignored `.env.testing.mysql` and fill in `MYSQL_VALIDATION_HOST`, port, database, username and password. Use a disposable database ending in `_test` or `_validation`. Never point this profile at live business data: PHPUnit's migration traits rebuild it.
2. Generate the **separate** environment key and run the read-only preflight:

```bash
php artisan key:generate --env=testing.mysql
php scripts/mysql-validation-preflight.php
```

3. The provisioned account needs SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES and TRIGGER privileges within this dedicated database. Validation does not create databases, users, grants, or change authentication. Immutable trigger creation can be restricted by binary-log policy; if migrations encounter that restriction, report it to the account provisioner. Do not disable history triggers or change global settings to make the tests pass. See [CREATE TRIGGER privileges](https://dev.mysql.com/doc/refman/8.4/en/create-trigger.html).

`phpunit.mysql.xml` loads `tests/bootstrap-mysql.php`, forces the dedicated connection, and uses a separate config-cache path. The bootstrap rejects missing credentials, root and database names without the disposable suffix. It runs the original Unit/Feature tests plus MySQL-specific tests. Run it serially: worker processes in the concurrency tests deliberately share committed fixtures in the same test database.

## Validation sequence once credentials are available

This sequence was executed successfully on 9 October 2026. Use only the dedicated disposable validation database when repeating it.

```bash
php scripts/mysql-validation-preflight.php
php artisan migrate:fresh --env=testing.mysql
php artisan db:seed --env=testing.mysql
vendor/bin/phpunit --configuration phpunit.mysql.xml
```

The full suite includes existing POS/payment, stock, tenancy/branch isolation, return/void, transfer, ledger and shift settlement tests. MySQL-only tests additionally inspect actual DECIMAL precision, InnoDB table engines, strict SQL mode, timezone, native JSON round trips, CHECK constraints and immutable triggers. Concurrency tests use **two independent PHP/database connections** released by a barrier, with committed fixtures instead of transaction-hidden fixtures. They cover:

- Two cashiers issuing the same invoice: one stock deduction, one ISSUE ledger entry, complete cashier/shift trace.
- Two invoices competing for one remaining drink: exactly one issue succeeds; the other fails without a partial posting, and stock ends at zero.
- Concurrent payment retries using the same UUID: one allocation and one payment ledger credit.
- Two different full-payment UUIDs competing on the same invoice: one settlement succeeds and the other cannot overpay.

The migration test traits rebuild/rollback fixtures. After the full suite, **rebuild and reseed the disposable MySQL database** for browser validation:

```bash
php artisan migrate:fresh --env=testing.mysql
php artisan db:seed --env=testing.mysql
php -S 127.0.0.1:8884 -t public scripts/mysql-validation-server.php
```

The guarded router verifies the actual MySQL connection, `nightshift_validation` database and authenticated `nightshift_user` before serving requests. Do not substitute ordinary `artisan serve --env=testing.mysql`: its reload mode can discard DB environment variables when creating the web process. The browser runner checks the router's database-identity headers and refuses to count a SQLite environment as a MySQL pass. With a separate Chrome debugging session on port 9224, run `node scripts/browser-mysql-smoke.mjs`; it posts only to port 8884. After it finishes, `php scripts/mysql-validation-observations.php` verifies stock against movements and waiter ledgers against issued-invoice balances.

Use the development accounts from the README to check login, all workspace screens, real order creation/reload, issue, partial/split payment, waiter debt and shift settlement, transfers/returns/voids, real reports/CSV exports, receipt printing and 390px mobile overflow. Run browser tests against **port 8884**, keeping the SQLite server on its current port. Browser posting changes only the disposable validation fixture. Record failures and compare the same workflows with SQLite before implementing any fixes.

## Engine differences to verify and report

| Difference | Consequence / validation |
| --- | --- |
| SQLite numeric affinity vs native MySQL DECIMAL | BCMath and string casts handle application calculations; exercise fractional quantities, cent discounts, repeated returns and aggregated reports. MySQL supplies native fixed-point storage and exact DECIMAL aggregation. |
| SQLite database writer serialization vs InnoDB row/gap locks | Sequential double-submit tests alone do not prove simultaneous MySQL behavior; run the independent-process races above, then examine lock waits/deadlocks and rollback results. |
| MySQL-only CHECK constraints and history triggers | SQLite model/service guards remain as before. MySQL raw-write tests must prove database enforcement. |
| SQLite text JSON vs native MySQL JSON | Confirm decoded objects/arrays, empty arrays and boolean values, including audit metadata and notifications. Do not compare raw JSON string formatting or object-key ordering. |
| SQLite string comparison vs utf8mb4_unicode_ci | MySQL uniqueness/search can equate case or accent variants that SQLite treats differently. Inspect names, email, SKU, supplier invoice and UUID collisions; resolve intended identifier normalization before production if a mismatch appears. |
| MySQL TIMESTAMP timezone conversion and 2038 bound | The explicit +03:00 session preserves current application wall times without timezone-table installation. Check midnight/report boundaries and stored/retrieved timestamps. The existing TIMESTAMP schema retains MySQL's 2038 upper bound; archive/future-date requirements need a separate schema decision. |
| Strict SQL mode and bounded VARCHAR/DECIMAL | MySQL rejects truncation, invalid dates and out-of-range values that SQLite may accept. Test request limits, aggregate precision and maximum reference lengths. |
| DDL implicitly commits on MySQL | Migrations and fresh-test setup run outside business transactions. A failed migration can leave partially created tables; recover only in the disposable validation database. |

The requested MySQL application validation is complete and passing. Production deployment and backup/restore verification remain separate work; this validation did not deploy the application or change production accounts/configuration.
