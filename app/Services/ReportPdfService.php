<?php

namespace App\Services;

use App\Contracts\ReportPdfRenderer;
use App\Domain\StaffVisibility;
use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\BusinessUnit;
use App\Models\Category;
use App\Models\Product;
use App\Models\Shift;
use App\Models\StockLocation;
use App\Models\User;
use App\Support\UiText;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ReportPdfService
{
    public function __construct(private ReportPdfRenderer $renderer) {}

    /** @param callable(int):array<string,mixed> $page
     * @return array<string,mixed>
     */
    public function all(callable $page): array
    {
        return DB::transaction(function () use ($page) {
            $result = $page(1);
            $rows = $result['rows'];
            $number = 1;
            while ($result['has_more'] ?? false) {
                $result = $page(++$number);
                $rows = [...$rows, ...$result['rows']];
                $this->checkLimit(count($rows));
            }
            $this->checkLimit(count($rows));

            return [...$result, 'rows' => $rows, 'page' => 1, 'has_more' => false];
        });
    }

    public function checkLimit(int $rows): void
    {
        if ($rows > (int) config('report_pdf.max_rows')) {
            throw ValidationException::withMessages(['pdf' => __('ui.Report is too large for PDF. Narrow the filters and try again.')]);
        }
    }

    /** @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function metadata(string $title, array $filters): array
    {
        $actor = app(TenantContext::class)->user();
        if (request()->filled('company_id')) {
            abort_unless((int) request('company_id') === $actor->company_id, 403);
        }
        foreach (['branch_id', 'business_unit_id', 'stock_location_id', 'product_id', 'category_id', 'shift_id', 'waiter_id', 'issued_by', 'custodian_id', 'user_id', 'status', 'method', 'payment_method', 'transaction_type', 'action', 'module', 'search', 'from', 'to'] as $key) {
            if (! request()->filled($key)) {
                continue;
            }
            if (in_array($title, ['Cash Custody Balance', 'Waiter Outstanding'], true) && in_array($key, ['from', 'to'], true)) {
                continue;
            }
            if (! array_key_exists($key, $filters) || (string) request($key) !== (string) $filters[$key]) {
                throw ValidationException::withMessages([$key => __('ui.This filter is not available for this report.')]);
            }
        }

        $summary = [];
        $models = ['branch_id' => [Branch::class, 'Branch', 'name'], 'business_unit_id' => [BusinessUnit::class, 'Business Unit', 'name'], 'stock_location_id' => [StockLocation::class, 'Counter', 'name'], 'product_id' => [Product::class, 'Product', 'name'], 'category_id' => [Category::class, 'Category', 'name'], 'shift_id' => [Shift::class, 'Shift', 'number']];
        foreach ($filters as $key => $value) {
            if ($value === null || $value === '' || in_array($key, ['page', 'all_branches', 'period'], true)) {
                continue;
            }
            if (! empty($filters['shift_id']) && in_array($key, ['from', 'to'], true)) {
                continue;
            }
            if (isset($models[$key])) {
                [$class,$label,$column] = $models[$key];
                $value = $class::findOrFail((int) $value)->getAttribute($column);
            } elseif (in_array($key, ['waiter_id', 'issued_by', 'custodian_id', 'user_id'], true)) {
                $label = ['waiter_id' => 'Waiter', 'issued_by' => 'Cashier', 'custodian_id' => 'Custodian', 'user_id' => 'User'][$key];
                $users = User::where('company_id', $actor->company_id);
                if ((int) $value !== $actor->id) {
                    $users = app(StaffVisibility::class)->unitUsers($users);
                }
                $value = $users->findOrFail((int) $value)->name;
            } else {
                $label = ['from' => 'From', 'to' => 'To', 'search' => 'Search', 'action' => 'Action', 'module' => 'Module', 'status' => 'Status', 'transaction_type' => 'Transaction type'][$key] ?? ucfirst(str_replace('_', ' ', $key));
            }
            $summary[UiText::get($label)] = (string) $value;
        }

        if (! empty($filters['shift_id'])) {
            $shift = app(ReportShiftService::class)->resolve($filters, $title);
            $context = app(ReportShiftService::class)->context($shift);
            foreach (['branch' => 'Branch', 'employee' => 'Employee', 'business_unit' => 'Business Unit', 'counter' => 'Counter', 'opened_at' => 'Opened', 'closed_at' => 'Closed', 'status' => 'Status'] as $field => $label) {
                $summary[UiText::get($label)] = $field === 'status' ? UiText::get($context[$field]) : (string) ($context[$field] ?? '—');
            }
            $summary[UiText::get('Date scope')] = UiText::get('Selected shift; date range is ignored.');
        }

        return ['title' => UiText::get($title), 'company' => $actor->company->name, 'currency' => $actor->company->currency === 'TZS' ? 'TSh' : $actor->company->currency, 'generated_at' => now()->timezone($actor->company->timezone)->format('Y-m-d H:i:s T'), 'generated_by' => $actor->name, 'filters' => $summary, 'logo' => 'data:image/svg+xml;base64,'.base64_encode(File::get(public_path('nightshift.svg')))];
    }

    /** @param array<string,mixed> $filters
     * @param  list<array<string,mixed>>  $sections
     */
    public function download(string $title, array $filters, array $sections): Response
    {
        if ($sections === []) {
            throw new \InvalidArgumentException('At least one PDF section is required.');
        }
        $columns = max(array_map(fn ($section) => count($section['headers']), $sections));
        $orientation = $columns > 7 ? 'landscape' : 'portrait';
        $metadata = $this->metadata($title, $filters);
        if (in_array($title, ['Cash Custody Balance', 'Waiter Outstanding'], true)) {
            foreach ($sections as &$section) {
                $section['note'] = $title === 'Cash Custody Balance' ? 'Current balance includes the full custody history.' : 'Current balance includes the full ledger history.';
            }
        }
        unset($section);

        $html = view('reports.pdf', ['metadata' => $metadata, 'sections' => $sections, 'orientation' => $orientation])->render();

        return $this->response($this->renderer->render($html, $orientation), $title, $filters);
    }

    /** @param array<string,mixed> $filters */
    public function response(
        string $pdf,
        string $title,
        array $filters,
        bool $inline = false
    ): Response
    {
        $branch = ! empty($filters['branch_id']) ? Branch::findOrFail((int) $filters['branch_id'])->name : '';
        if (! empty($filters['shift_id'])) {
            $shift = Shift::whereKey((int) $filters['shift_id'])->firstOrFail();
            $filename = 'nightshift-'.Str::slug($title).'-'.preg_replace('/[^A-Za-z0-9_-]/', '', $shift->number).'.pdf';
        } else {
            $filename = 'nightshift-'.substr(Str::slug($title.($branch ? ' '.$branch : '')), 0, 130).'-'.($filters['from'] ?? now()->toDateString()).(! empty($filters['to']) && ($filters['to'] !== ($filters['from'] ?? null)) ? '-to-'.$filters['to'] : '').'.pdf';

        }

        return response($pdf)->withHeaders([
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment')
                . '; filename="'
                . substr(pathinfo($filename, PATHINFO_FILENAME), 0, 180)
                . '.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
