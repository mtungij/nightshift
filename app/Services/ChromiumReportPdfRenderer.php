<?php

namespace App\Services;

use App\Contracts\ReportPdfRenderer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

final class ChromiumReportPdfRenderer implements ReportPdfRenderer
{
    public function render(string $html, string $orientation): string
    {
        $directory = storage_path('app/private/report-pdf/'.Str::uuid());
        File::ensureDirectoryExists($directory, 0700);
        try {
            File::put($directory.'/input.html', $html);
            $process = new Process([(string) config('report_pdf.node'), base_path('scripts/render-report-pdf.mjs'), $directory, (string) config('report_pdf.chrome'), config('report_pdf.no_sandbox') ? '1' : '0', app()->getLocale(), (string) config('report_pdf.timeout')]);
            $process->setTimeout((float) config('report_pdf.timeout'));
            $process->run();
            abort_unless($process->isSuccessful() && is_file($directory.'/output.pdf'), 503, __('ui.PDF generation is unavailable. Please try again.'));
            $pdf = File::get($directory.'/output.pdf');
            abort_unless(str_starts_with($pdf, '%PDF-') && strlen($pdf) <= (int) config('report_pdf.max_bytes'), 503, __('ui.PDF generation is unavailable. Please try again.'));

            return $pdf;
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
