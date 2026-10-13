<?php

use App\Domain\TenantContext;
use App\Models\Shift;
use App\Models\StockLocation;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../tests/bootstrap-mysql.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'mysql_validation' || DB::connection()->getDatabaseName() !== 'nightshift_validation') {
    throw new RuntimeException('Observations require the authorized nightshift_validation database.');
}
$identity = DB::selectOne('SELECT VERSION() AS version, DATABASE() AS db, CURRENT_USER() AS authenticated_account');
$engines = DB::select('SELECT ENGINE AS engine, COUNT(*) AS table_count FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() GROUP BY ENGINE');
$columns = DB::select("SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name, DATA_TYPE AS data_type, NUMERIC_PRECISION AS precision_value, NUMERIC_SCALE AS scale_value FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME = 'stocks' AND COLUMN_NAME = 'quantity') OR (TABLE_NAME = 'orders' AND COLUMN_NAME = 'total') OR (TABLE_NAME = 'audit_logs' AND COLUMN_NAME = 'new_values'))");
$semantics = DB::selectOne("SELECT (_utf8mb4'A' COLLATE utf8mb4_unicode_ci = _utf8mb4'a') AS case_equal, (_utf8mb4'é' COLLATE utf8mb4_unicode_ci = _utf8mb4'e') AS accent_equal, CAST('0.10' AS DECIMAL(16,2)) + CAST('0.20' AS DECIMAL(16,2)) AS decimal_sum, JSON_TYPE(JSON_EXTRACT(CAST('{\"validated\":true}' AS JSON), '$.validated')) AS json_boolean_type");
$negative = DB::table('stocks')->where('quantity', '<', 0)->count();
$stockDifferences = DB::select('SELECT s.company_id, s.stock_location_id, s.product_id, s.quantity, COALESCE(m.quantity,0) AS movement_quantity FROM stocks s LEFT JOIN (SELECT company_id, stock_location_id, product_id, SUM(quantity) AS quantity FROM stock_movements GROUP BY company_id, stock_location_id, product_id) m ON m.company_id=s.company_id AND m.stock_location_id=s.stock_location_id AND m.product_id=s.product_id WHERE s.quantity <> COALESCE(m.quantity,0)');
$ledgerDifferences = DB::select('SELECT l.company_id, l.waiter_id, l.balance AS ledger_balance, COALESCE(o.balance,0) AS invoice_balance FROM (SELECT company_id, waiter_id, SUM(debit)-SUM(credit) AS balance FROM waiter_ledger_entries GROUP BY company_id, waiter_id) l LEFT JOIN (SELECT company_id, waiter_id, SUM(balance) AS balance FROM orders WHERE issued_at IS NOT NULL GROUP BY company_id, waiter_id) o ON o.company_id=l.company_id AND o.waiter_id=l.waiter_id WHERE l.balance <> COALESCE(o.balance,0)');
$selection = app(TenantContext::class)->run(User::where('email', 'admin@nightshift.test')->firstOrFail(), function () {
    $locations = StockLocation::where('branch_id', 1);
    $cashier = Shift::where('user_id', 1)->where('stock_location_id', 4)->where('type', 'CASHIER')->where('status', 'OPEN')->lockForUpdate()->limit(1);

    return ['locations_sql' => $locations->toSql(), 'locations_bindings' => $locations->getBindings(), 'locations_returned_order' => $locations->get()->pluck('id')->all(), 'cashier_sql' => $cashier->toSql(), 'cashier_bindings' => $cashier->getBindings()];
});
$result = [
    'result' => $negative === 0 && $stockDifferences === [] && $ledgerDifferences === [] ? 'PASS' : 'FAIL',
    'identity' => $identity,
    'counter_selection_diagnostic' => $selection,
    'fixture_shifts' => DB::table('shifts')->get(['id', 'company_id', 'user_id', 'stock_location_id', 'type', 'status'])->all(),
    'fixture_orders' => DB::table('orders')->get(['id', 'company_id', 'waiter_id', 'stock_location_id', 'status'])->all(),
    'engines' => $engines,
    'columns' => $columns,
    'semantics' => $semantics,
    'negative_stock_rows' => $negative,
    'stock_vs_movement_discrepancies' => $stockDifferences,
    'ledger_vs_invoice_discrepancies' => $ledgerDifferences,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
exit($result['result'] === 'PASS' ? 0 : 1);
