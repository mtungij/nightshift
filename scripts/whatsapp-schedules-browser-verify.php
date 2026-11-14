<?php

use App\Domain\TenantContext;
use App\Models\User;
use App\Models\WhatsAppDeliveryLog;
use App\Models\WhatsAppReportSchedule;
use App\Services\OperationalPeriodReportService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../tests/bootstrap-mysql.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'nightshift_treasury_browser_validation') {
    throw new RuntimeException('Disposable database only');
}
Http::preventStrayRequests();
$f = json_decode(file_get_contents('/tmp/nightshift-whatsapp-schedules-fixtures.json'), true, 512, JSON_THROW_ON_ERROR);
$baseline = json_decode(file_get_contents('/tmp/nightshift-whatsapp-schedules-baseline.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($baseline as $table => $before) {
    $rows = DB::table($table)->where('id', '<=', $before['max_id'])->orderBy('id')->get();
    if (hash('sha256', $rows->toJson()) !== $before['hash']) {
        throw new RuntimeException('Historical rows changed: '.$table);
    }
}
$owner = User::where('username', $f['owner'])->firstOrFail();
$result = app(TenantContext::class)->run($owner, function () use ($f) {
    $logs = WhatsAppDeliveryLog::where('schedule_id', $f['schedule_id'])->get();
    if ($logs->count() !== 2 || $logs->contains(fn ($log) => $log->status !== 'SENT' || ! $log->attachment_message_id)) {
        throw new RuntimeException('Scheduled delivery mismatch');
    }
    $tests = WhatsAppDeliveryLog::where('message_type', 'TEST')->get();
    if ($tests->isEmpty() || $tests->contains(fn ($log) => $log->status !== 'QUEUED')) {
        throw new RuntimeException('Browser test request not idempotent/queued');
    }
    $schedule = WhatsAppReportSchedule::where('name', 'Browser weekly digest')->where('report_type', 'WEEKLY')->where('send_time', '06:15')->first();
    if (! $schedule) {
        throw new RuntimeException('Browser schedule was not saved');
    }
    $data = app(OperationalPeriodReportService::class)->build(['period_start' => '2026-11-10T05:00:00+03:00', 'period_end' => '2026-11-11T05:00:00+03:00', 'branch_ids' => [$f['branch_id']], 'business_unit_ids' => [$f['unit_id']]]);
    if ((float) $data['metrics']['sales'] !== 6000.0 || (float) $data['metrics']['waiter_outstanding'] !== 6000.0 || $data['metrics']['open_shifts'] !== 2) {
        throw new RuntimeException('Report/ledger reconciliation mismatch');
    }
    $physical = DB::table('stocks')->where('company_id', $f['company_id'])->where('stock_location_id', $f['counter_id'])->sum('quantity');
    $ledger = DB::table('stock_movements')->where('company_id', $f['company_id'])->where('stock_location_id', $f['counter_id'])->sum('quantity');
    if ((float) $physical !== 8.0 || (float) $ledger !== 8.0) {
        throw new RuntimeException('Stock/ledger mismatch');
    }

    return ['scheduled_deliveries' => 2, 'queued_test_requests' => $tests->count(), 'sales' => '6000.00', 'waiter_outstanding' => '6000.00', 'open_shifts' => 2, 'physical_stock' => '8.000000', 'movement_ledger_stock' => '8.000000', 'browser_schedule_saved' => true];
});
Http::assertNothingSent();
echo json_encode(['result' => 'PASS', 'historical_tables_unchanged' => count($baseline), 'reconciliation' => $result], JSON_PRETTY_PRINT).PHP_EOL;
