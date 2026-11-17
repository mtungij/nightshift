# Legacy Business Unit retirement

Migration `2026_11_18_000001_retire_legacy_business_units.php` retires the old `GENERAL` code. It also recognizes records named `General` without a creator and orphaned locations named `General Stock`. The old migrations remain intact for installations upgrading across them.

The migration removes legacy staff, counter, product, price, and shared-location mappings. It deletes an unreferenced system-managed legacy stock location and clears its legacy default pointer. Referenced units and locations, along with manually created locations, are marked inactive and archived in place. Existing foreign keys, posted totals, stock quantities, and immutable attribution are retained. Company and branch history and authorized closed-shift reports can still read their original context. Operational screens exclude archived units and locations.

## Applying the migration

1. Back up the target database and stop web, queue, and scheduler writers for the maintenance window.
2. Run `php artisan migrate --force`. The migration checks for unresolved work before changing the schema or legacy data. It reports the IDs that need attention if it stops.
3. Reconcile any reported items on the previous release. Close affected shifts, settle waiter balances, collect pending tickets, complete handovers, and resolve unfinished orders, adjustments, and transfers. Reconfigure or deactivate affected terminals through the existing workflow. When the actual stock allocation is known, use the existing supervised stock cutover with explicit receiving units and quantities. Rename an explicit unit or location that collides with a retired name after confirming its identity.
4. Retry the migration after reconciliation. Verify stock and financial totals, operational unit options, and any scoped WhatsApp recipients or schedules that referenced the retired unit before resuming writers. Historical WhatsApp payloads are retained.

Stock diagnostics list `location ID/product ID`. Inspect the recorded quantities and supporting movement history for those IDs before approving a transfer. The migration never transfers inventory or reassigns historical orders to another unit. Deploy the corresponding code and the migration together. Rollback uses a verified database backup.
