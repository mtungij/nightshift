<?php

use App\Contracts\ReportPdfRenderer;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
    throw new RuntimeException('Use testing with in-memory SQLite; this layout fixture does not access a database.');
}
config(['report_pdf.no_sandbox' => true]);
$output = '/tmp/nightshift-report-pdf-layout';
if (! is_dir($output)) {
    mkdir($output, 0700, true);
}
foreach (['en', 'sw'] as $locale) {
    app()->setLocale($locale);
    $rows = [];
    for ($i = 0; $i < 500; $i++) {
        $rows[] = ['REF-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), '2026-11-11 12:30:00', 'Synthetic custodian', 'Validation branch', 'Restaurant', 'EXPENSE', 'OUT', '1250.00', 'Transport', 'Synthetic employee', 'November 2026', 'Validation bank', 'GRN-000125', $i === 0 ? str_repeat('Long-description-words ', 90) : 'Synthetic layout fixture — no accounting transaction'];
    }
    $metadata = ['title' => 'Wide / long report validation', 'company' => 'Nightshift validation', 'currency' => 'TSh', 'generated_at' => '2026-11-11 12:30:00 EAT', 'generated_by' => 'Validation runner', 'filters' => ['Branch' => 'Validation branch'], 'logo' => 'data:image/svg+xml;base64,'.base64_encode(file_get_contents(public_path('nightshift.svg')))];
    $html = view('reports.pdf', ['metadata' => $metadata, 'orientation' => 'landscape', 'sections' => [['headers' => ['Reference', 'Date and time', 'Custodian', 'Branch', 'Business Unit', 'Transaction type', 'Direction', 'Amount', 'Category', 'Employee', 'Payment period', 'Bank', 'Posted purchase', 'Description'], 'rows' => $rows]]])->render();
    file_put_contents($output.'/'.$locale.'-wide-500.pdf', app(ReportPdfRenderer::class)->render($html, 'landscape'));
}
echo json_encode(['result' => 'PASS', 'files' => 2, 'rows_per_file' => 500]).PHP_EOL;
