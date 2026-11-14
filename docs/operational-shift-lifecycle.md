# Operational shifts

Cashier shifts are continuous counter operations. Their operating date is the
opening timestamp in Africa/Dar_es_Salaam; midnight has no lifecycle effect.
A counter cannot open its next cashier shift until the previous one is closed
and there are no remaining open waiter sessions or counter debts.
Shift ownership never changes during handover. Managers with the existing
`open-shifts` and `manage-users` permissions can open for an authorized cashier
within their accessible branches. `opened_by` and the opening audit distinguish
the actor from the cashier; stock snapshots retain the actor attribution.

Waiter sessions link to the current cashier operation through `cashier_shift_id`.
Many waiters may work concurrently and rotate independently. Existing management
permissions to administer waiter sessions remain; a waiter cannot act for someone
else. Opening requires no open session, no positive lifetime ledger balance, no
pending previous orders/voids, and an active cashier operation at the counter.
Neither logout nor a date or cashier change clears the ledger. Managers cannot
close indebted waiter sessions with a reason alone. Existing audited payments,
returns and approved voids remain the methods for settling/correcting debt.

Cashier close requires all counter waiter sessions closed and all counter ledger
debt settled, including historical overrides. The closing page lists names,
open status and outstanding amounts. Cash must be entered before saving a full
physical count. A stock-ledger mismatch, stale/incomplete count, missing variance
approval or unexplained cash difference prevents final close. Changing recorded
cash requires saving counts again. Waiter session and receipt changes invalidate
counts as well as stock movements. Counts do not adjust stock.

Orders keep `shift_id` as their waiter session (the single existing waiter ledger
path). `issued_shift_id` and issue stock movements reference the counter's active
cashier operation, including immediate waiter/PIN and POS Terminal issuance. Counter stock additions
and adjustments without an explicit historical shift also inherit the active
cashier shift, while audited reversals retain their original reference links.
POS Terminal remains a shared workstation with no shift ownership or close rights.
Authorized managers receiving payments use the current counter cashier operation;
received-by attribution and permission checks remain independent of ownership.

The closing stock snapshot freezes cash, stock, product metadata, operational
context and waiter participation. Closed shift models reject mutation and deletion;
snapshots already have database immutability triggers. The existing audited
correction paths remain available and do not rewrite frozen reports. Shift report
and CSV queries accept `shift_id` independently of the date range, subject to
existing view and tenant permissions. Date ranges select shift openings, not parts
of shifts. Daily sales reports retain their existing calendar semantics.

All service mutations use TransactionService's InnoDB company-row lock, acquired
before any operation data is read. This serializes competing cashier opens,
manager opens, closes, settlements, waiter orders, counts, stock postings and
handover. A close retry with the same command idempotency key reuses its result;
a different key after closure is rejected. No new accounting or terminal path is
introduced. All application writers must continue using TransactionService.

## Forward-only migration and supervised cutover

Do not deploy automatically. The migration adds nullable `cashier_shift_id` and
`opened_by`, with company-composite foreign keys, plus a counter-operation lookup
index. It updates no shifts, orders, ledgers, timestamps or snapshots. Its rollback
is intentionally empty; rollback requires restoring the pre-release backup.

Before enabling the lifecycle in an existing installation, inspect open shifts
per company/counter, outstanding waiter debt and opening snapshot availability.
Existing installations may contain overlapping cashier shifts: resolve them under
supervision using real receipts, physical counts and attributed reconciliation.
Do not change owners or merge their histories. Immediate issuance, unattributed stock postings and manager
settlements reject an ambiguous counter with multiple legacy cashier operations
instead of choosing an arbitrary owner. Do not attach old waiter sessions
or orders to a guessed cashier shift. Existing open waiter sessions still block
counter close and can settle against the active operation. Their past activity
is only reported where an explicit existing order link supports it.

A legacy shift without an opening snapshot returns HTTP 409 from stock
reconciliation. There is deliberately no invented opening snapshot or automatic
close escape hatch. Such shifts require a supervised, documented cutover using
verified historical evidence and the installation's audited correction process
before this release can be enabled. This release does not provide a generic
legacy-history repair command. Preserve unresolved operations and defer cutover
if their opening evidence cannot be established.

Do not run the demo seeder as a production cutover. Demo fixtures now use one
cashier at Main Counter and a separate owner operation at VIP Counter.
