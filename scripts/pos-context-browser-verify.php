<?php

use App\Domain\TenantContext;
use App\Models\Order;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WaiterLedgerEntry;
use App\Services\PaymentService;
use App\Services\SalesTicketService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require __DIR__.'/../tests/bootstrap-mysql.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'nightshift_treasury_browser_validation' || (int) config('database.connections.mysql_validation.port') !== 3340) {
    throw new RuntimeException('Disposable browser database only.');
}
Http::preventStrayRequests();
Queue::fake();
$f = json_decode(file_get_contents('/tmp/nightshift-pos-context-fixtures.json'), true, flags: JSON_THROW_ON_ERROR);
$result = app(TenantContext::class)->run(User::findOrFail($f['owner_id']), function () use ($f) {
    $order = Order::where('created_by', $f['bar']['terminal_id'])->whereHas('items', fn ($q) => $q->where('product_id', $f['products']['bar']['id']))->latest('id')->firstOrFail();
    if ($order->business_unit_id !== $f['bar']['unit_id'] || $order->total !== '5000.00' || $order->items->first()->unit_price !== '5000.00') {
        throw new RuntimeException('Order attribution/price mismatch.');
    }
    if ($order->status === 'ISSUED') {
        app(TenantContext::class)->run(User::findOrFail($f['bar']['cashier_id']), fn () => app(PaymentService::class)->receive($order->id, [['method' => 'Cash', 'amount' => '5000']]));
    }
    $order->refresh();
    $stock = Stock::where('stock_location_id', $f['bar']['counter_id'])->where('product_id', $f['products']['bar']['id'])->value('quantity');
    $movement = number_format((float) StockMovement::where('stock_location_id', $f['bar']['counter_id'])->where('product_id', $f['products']['bar']['id'])->sum('quantity'), 6, '.', '');
    $ledger = WaiterLedgerEntry::where('order_id', $order->id)->selectRaw('SUM(debit) as debit, SUM(credit) as credit')->first();
    if ($stock !== '9.000000' || $movement !== '9.000000' || $order->status !== 'PAID' || $order->amount_paid !== '5000.00' || (float) $ledger->debit !== 5000.0 || (float) $ledger->credit !== 5000.0) {
        throw new RuntimeException('Stock/payment/ledger reconciliation failed.');
    }
    foreach ($f['baseline'] as $table => $baseline) {
        $rows = DB::table($table)->where('id', '<=', $baseline['max_id'])->orderBy('id')->get();
        if (hash('sha256', $rows->toJson()) !== $baseline['hash']) {
            throw new RuntimeException('Pre-existing history changed: '.$table);
        }
    }
    $directory = '/tmp/nightshift-pos-context-tickets';
    if (! is_dir($directory)) {
        mkdir($directory, 0770, true);
    }
    foreach (['en', 'sw'] as $locale) {
        app()->setLocale($locale);
        foreach (['58mm', '80mm'] as $width) {
            file_put_contents($directory.'/'.$locale.'-'.$width.'.html', view('issue-ticket', ['ticket' => app(SalesTicketService::class)->data($order), 'copies' => ['WAITER COPY', 'CASHIER COPY'], 'reprint' => false, 'batch' => null, 'paperWidth' => $width])->render());
        }
    }

    return ['result' => 'PASS', 'order_id' => $order->id, 'business_unit_id' => $order->business_unit_id, 'price' => $order->items->first()->unit_price, 'total' => $order->total, 'paid' => $order->amount_paid, 'stock' => $stock, 'movement_stock' => $movement, 'ledger_debit' => (string) $ledger->debit, 'ledger_credit' => (string) $ledger->credit, 'historical_tables_unchanged' => count($f['baseline']), 'ticket_directory' => $directory];
});
file_put_contents('/tmp/pos-context-reconciliation.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode($result).PHP_EOL;
