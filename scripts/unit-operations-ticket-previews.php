<?php

use App\Domain\TenantContext;
use App\Models\Order;
use App\Models\User;
use App\Services\SalesTicketService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../tests/bootstrap-mysql.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'nightshift_treasury_browser_validation' || (int) config('database.connections.mysql_validation.port') !== 3340) {
    throw new RuntimeException('Disposable browser database only.');
}
$f = json_decode(file_get_contents('/tmp/nightshift-pos-admin-fixtures.json'), true, flags: JSON_THROW_ON_ERROR);
$prior = json_decode(file_get_contents('/tmp/pos-context-reconciliation.json'), true, flags: JSON_THROW_ON_ERROR);
$owner = User::whereKey($f['owner_id'])->firstOrFail();
app(TenantContext::class)->run($owner, function () use ($prior) {
    $order = Order::whereKey($prior['order_id'])->firstOrFail();
    $original = app(SalesTicketService::class)->data($order);
    $directory = '/tmp/nightshift-unit-operation-tickets';
    if (! is_dir($directory)) {
        mkdir($directory, 0770, true);
    }
    $cases = [];
    foreach (['simple', 'legacy'] as $mode) {
        // Render-only variants. Never update a stored ticket, order or balance.
        $ticket = $original;
        if ($mode === 'simple') {
            $ticket['show_location'] = false;
        } else {
            unset($ticket['show_location']);
        }
        foreach (['en', 'sw'] as $locale) {
            app()->setLocale($locale);
            foreach (['58mm', '80mm'] as $width) {
                $name = $mode.'-'.$locale.'-'.$width;
                file_put_contents($directory.'/'.$name.'.html', view('issue-ticket', ['ticket' => $ticket, 'copies' => ['WAITER COPY', 'CASHIER COPY'], 'reprint' => false, 'batch' => null, 'paperWidth' => $width])->render());
                $cases[] = ['name' => $name, 'mode' => $mode, 'locale' => $locale, 'width' => $width, 'unit' => $ticket['business_unit'], 'counter' => $ticket['counter']];
            }
        }
    }
    file_put_contents($directory.'/cases.json', json_encode($cases, JSON_THROW_ON_ERROR));
    echo 'PASS: eight render-only ticket previews; all stored financial/ticket data preserved.'.PHP_EOL;
});
