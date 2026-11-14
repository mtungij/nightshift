<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../tests/bootstrap-mysql.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'nightshift_treasury_browser_validation' || (int) config('database.connections.mysql_validation.port') !== 3340) {
    throw new RuntimeException('Use isolated browser validation database.');
}
$f = json_decode(file_get_contents('/tmp/nightshift-business-unit-fixtures.json'), true, 512, JSON_THROW_ON_ERROR);
$r = json_decode(file_get_contents('/tmp/nightshift-business-unit-browser/result.json'), true, 512, JSON_THROW_ON_ERROR);
$company = $f['company_id'];
$orders = DB::table('orders')->where('company_id', $company)->whereIn('id', $r['issued'])->get();
$general = DB::table('business_units')->where('company_id', $company)->where('branch_id', $f['branch_id'])->where('code', 'GENERAL')->value('id');
$legacy = DB::table('orders')->where('company_id', $company)->orderBy('id')->limit($f['legacy_before']['orders'])->pluck('id');
$checks = ['legacy_cutover_unchanged' => $f['legacy_cutover_unchanged'], 'two_paid_orders' => $orders->count() === 2 && $orders->every(fn ($o) => $o->status === 'PAID'), 'sales_total' => bccomp((string) $orders->sum('total'), '4500', 2) === 0, 'payments_total' => bccomp((string) DB::table('payments')->where('company_id', $company)->sum('amount'), bcadd($f['legacy_before']['payment_total'], '4500', 2), 2) === 0, 'treasury_unchanged' => DB::table('treasury_entries')->count() === $f['legacy_before']['treasury'], 'single_product_master' => DB::table('products')->where('id', $f['product_id'])->count() === 1];
$checks['legacy_orders_remain_general'] = DB::table('operational_unit_contexts')->where('company_id', $company)->where('record_type', 'orders')->whereIn('record_id', $legacy)->where('business_unit_id', $general)->count() === $legacy->count();
foreach ($f['cases'] as $kind => $case) {
    $order = $orders->firstWhere('id', $r['issued'][$kind]);
    $context = DB::table('operational_unit_contexts')->where('company_id', $company)->where('record_type', 'orders')->where('record_id', $order->id)->first();
    $checks[$kind.'_context'] = $context->business_unit_id === $case['unit_id'] && $context->stock_location_id === $case['counter_id'];
    $debit = DB::table('waiter_ledger_entries')->where('company_id', $company)->where('order_id', $order->id)->sum('debit');
    $credit = DB::table('waiter_ledger_entries')->where('company_id', $company)->where('order_id', $order->id)->sum('credit');
    $checks[$kind.'_ledger_settled'] = bccomp((string) $debit, (string) $credit, 2) === 0;
}
foreach (DB::table('stocks')->where('company_id', $company)->where('product_id', $f['product_id'])->get() as $stock) {
    $sum = DB::table('stock_movements')->where('company_id', $company)->where('stock_location_id', $stock->stock_location_id)->where('product_id', $stock->product_id)->sum('quantity');
    $checks['stock_'.$stock->stock_location_id] = bccomp((string) $sum, (string) $stock->quantity, 6) === 0;
}
$result = ['result' => in_array(false, $checks, true) ? 'FAIL' : 'PASS', 'checks' => $checks, 'sales' => '4500.00', 'bar_price' => '2000.00', 'restaurant_price' => '2500.00', 'stock_total' => (string) DB::table('stocks')->where('company_id', $company)->where('product_id', $f['product_id'])->sum('quantity')];
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
if ($result['result'] !== 'PASS') {
    exit(1);
}
