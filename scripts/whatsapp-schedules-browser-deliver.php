<?php

use App\Domain\TenantContext;
use App\Jobs\WhatsApp\SendWhatsAppMessageJob;
use App\Models\User;
use App\Models\WhatsAppDeliveryLog;
use App\Models\WhatsAppReportSchedule;
use App\Services\WhatsApp\CompanyContext;
use App\Services\WhatsApp\ScheduleService;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/../tests/bootstrap-mysql.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'nightshift_treasury_browser_validation') {
    throw new RuntimeException('Disposable database only');
}
$f = json_decode(file_get_contents('/tmp/nightshift-whatsapp-schedules-fixtures.json'), true, 512, JSON_THROW_ON_ERROR);
config(['gowa.password' => 'BROWSER-FAKE-PASSWORD', 'gowa.username' => 'BROWSER-FAKE-USER', 'report_pdf.no_sandbox' => true]);
Queue::fake();
Http::preventStrayRequests();
Http::fake(['notify.buildcore.site/devices/*/status' => Http::response(['code' => 'SUCCESS', 'results' => ['state' => 'logged_in']]), 'notify.buildcore.site/send/message' => Http::response(['code' => 'SUCCESS', 'results' => ['message_id' => 'BROWSER-MOCK-TEXT']]), 'notify.buildcore.site/send/file' => Http::response(['code' => 'SUCCESS', 'results' => ['message_id' => 'BROWSER-MOCK-PDF']])]);
Carbon::setTestNow('2026-11-11 06:05:00');
$owner = User::where('username', $f['owner'])->firstOrFail();
$result = app(TenantContext::class)->run($owner, function () use ($f) {
    $schedule = WhatsAppReportSchedule::findOrFail($f['schedule_id']);
    $service = app(ScheduleService::class);
    $period = $service->due($schedule);
    $service->enqueue($schedule, $period);
    $service->enqueue($schedule, $period);
    $logs = WhatsAppDeliveryLog::where('schedule_id', $schedule->id)->get();
    $results = [];
    foreach ($logs as $log) {
        (new SendWhatsAppMessageJob($log->company_id, $log->id))->handle(app(CompanyContext::class));
        $log->refresh();
        if ($log->status !== 'SENT') {
            throw new RuntimeException($log->error_message ?? 'Delivery failed');
        }if ((float) $log->report_data['metrics']['sales'] !== 6000.0) {
            throw new RuntimeException('Sales mismatch');
        }$path = '/tmp/nightshift-whatsapp-'.$log->recipient_id.'.pdf';
        file_put_contents($path, Storage::disk('local')->get($log->attachment_path));
        $results[] = ['id' => $log->id, 'recipient_id' => $log->recipient_id, 'language' => $log->payload['language'], 'status' => $log->status, 'sales' => $log->report_data['metrics']['sales'], 'open_shifts' => $log->report_data['metrics']['open_shifts'], 'source_rows' => array_sum(array_map(fn ($s) => count($s['rows']), $log->report_data['source_reports'])), 'pdf' => $path, 'message' => $log->message];
    }

    return $results;
});
$baseline = json_decode(file_get_contents('/tmp/nightshift-whatsapp-schedules-baseline.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($baseline as $table => $before) {
    $rows = DB::table($table)->where('id', '<=', $before['max_id'])->orderBy('id')->get();
    if (hash('sha256', $rows->toJson()) !== $before['hash']) {
        throw new RuntimeException('History changed: '.$table);
    }
}
echo json_encode(['result' => 'PASS', 'mocked_provider_requests' => Http::recorded()->count(), 'deliveries' => $result, 'historical_tables_unchanged' => count($baseline)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
