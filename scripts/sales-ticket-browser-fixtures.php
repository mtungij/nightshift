<?php

use App\Domain\TenantContext;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\User;
use App\Services\SalesTicketService;
use App\Services\StaffPinService;
use Database\Seeders\NightshiftDemoSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../tests/bootstrap-mysql.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'mysql_validation' || DB::connection()->getDatabaseName() !== 'nightshift_validation') {
    throw new RuntimeException('Ticket browser fixtures require the disposable MySQL validation database.');
}
$app->make(Kernel::class)->call('migrate', ['--force' => true]);
$app->make(Kernel::class)->call('db:seed', ['--class' => NightshiftDemoSeeder::class, '--force' => true]);
$owner = User::where('email', 'admin@nightshift.test')->firstOrFail();
$waiter = User::where('email', 'asha@nightshift.test')->firstOrFail();
app(TenantContext::class)->run($owner, function () use ($owner, $waiter) {
    if (in_array('--narrow-stress', $_SERVER['argv'] ?? [], true)) {
        $product = Product::where('sku', 'BR-001')->firstOrFail();
        $product->update(['name' => 'Serengeti Lite - Long narrow-printer product name '.str_repeat('ExtraLongProductName', 4)]);
        ProductUnit::where('product_id', $product->id)->update(['selling_price' => '1234567.89']);
        // Render-only edge cases: no financial rows are changed for these previews.
        $ticket = app(SalesTicketService::class)->data(Order::whereNotNull('issued_at')->firstOrFail());
        $ticket['items'] = [
            ['product_name' => $product->name, 'quantity' => '4.000000', 'unit_name' => 'Piece', 'unit_price' => '3000.00', 'total' => '12000.00'],
            ['product_name' => $product->name, 'quantity' => '1.500000', 'unit_name' => 'Piece', 'unit_price' => '3000.00', 'total' => '4500.00'],
            ['product_name' => $product->name, 'quantity' => '0.750000', 'unit_name' => 'Piece', 'unit_price' => '9999999999.99', 'total' => '7499999999.99'],
        ];
        $ticket['subtotal'] = $ticket['total'] = '7500016499.99';
        $ticket['discount'] = '0.00';
        $ticket['outstanding'] = '9999999999.99';
        $directory = sys_get_temp_dir().'/nightshift-58mm-layout-fixtures';
        if (! is_dir($directory)) {
            mkdir($directory, 0770, true);
        }
        foreach (['en', 'sw'] as $locale) {
            app()->setLocale($locale);
            file_put_contents($directory.'/'.$locale.'.html', view('issue-ticket', ['ticket' => $ticket, 'copies' => ['WAITER COPY', 'CASHIER COPY'], 'reprint' => true, 'batch' => null, 'paperWidth' => '58mm'])->render());
        }
        app()->setLocale('en');
    }
    app(StaffPinService::class)->set($owner->id, '0123');
    app(StaffPinService::class)->set($waiter->id, '0123');
});
echo json_encode(['owner' => $owner->username, 'waiter' => $waiter->username], JSON_THROW_ON_ERROR)."\n";
