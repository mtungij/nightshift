<?php

namespace App\Http\Controllers;

use App\Domain\StaffVisibility;
use App\Http\Requests\NightshiftCommandRequest;
use App\Models\Branch;
use App\Models\BusinessNotification;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Product;
use App\Models\ServiceTable;
use App\Models\StockLocation;
use App\Models\User;
use App\Services\AdjustmentService;
use App\Services\CatalogService;
use App\Services\GoodsReceiptService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ReportFilterService;
use App\Services\ReportPdfService;
use App\Services\ReportService;
use App\Services\ReturnService;
use App\Services\SalesTicketService;
use App\Services\ShiftService;
use App\Services\ShiftStockService;
use App\Services\StaffPinService;
use App\Services\StockTransferService;
use App\Services\TransactionService;
use App\Services\VoidService;
use App\Services\WorkspaceService;
use App\Support\CounterLocationPresenter;
use App\Support\UiText;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class NightshiftController extends Controller
{
    public function snapshot(Request $request, WorkspaceService $workspace): JsonResponse
    {
        return response()->json($workspace->snapshot($this->filters($request)));
    }

    public function command(NightshiftCommandRequest $request, string $command, TransactionService $tx): JsonResponse
    {
        $data = $request->validated();
        $key = $data['idempotency_key'];
        unset($data['idempotency_key']);
        if ($command === 'order-send') {
            $result = app(OrderService::class)->send($data, $key);
            $result += app(SalesTicketService::class)->response(null);
            DB::afterCommit(function () use (&$result, $key) {
                try {
                    $result = array_replace($result, app(SalesTicketService::class)->response(app(SalesTicketService::class)->prepare($result['id'], $key)));
                } catch (\Throwable $error) {
                    report($error);
                    $result['print_error'] = true;
                }
            });

            return response()->json($result);
        }
        if ($command === 'ticket-reprint') {
            return response()->json(app(SalesTicketService::class)->response(app(SalesTicketService::class)->prepare($data['id'], $key, true)));
        }
        $fingerprint = $data;
        if (($data['image'] ?? null) instanceof UploadedFile) {
            $fingerprint['image'] = hash_file('sha256', $data['image']->getRealPath());
        }
        if (array_key_exists('pin', $fingerprint)) {
            $fingerprint['pin'] = hash_hmac('sha256', $fingerprint['pin'] ?? '', config('app.key'));
        }
        $result = $tx->idempotent($key, $command, $fingerprint, function () use ($command, $data) {
            $result = match ($command) {
                'shift-stock-count' => app(ShiftStockService::class)->count($data['id'], $data),
                'shift-stock-approve' => app(ShiftStockService::class)->approve($data['id'], $data['basis'], $data['revision'], $data['note']),
                'print-settings' => app(SalesTicketService::class)->savePreferences($data),
                'staff-pin' => app(StaffPinService::class)->set($data['id'], $data['pin'] ?? null),
                'waiter-payment' => app(PaymentService::class)->receiveWaiter($data['waiter_id'], $data['stock_location_id'], $data['amount'], $data['method']),
                'order-create' => app(OrderService::class)->create($data),'order-issue' => app(OrderService::class)->issue($data['id']),'order-submit' => app(OrderService::class)->submit($data['id']),'order-cancel' => app(OrderService::class)->cancel($data['id']),
                'payment' => app(PaymentService::class)->receive($data['id'], $data['lines']), 'shift-open' => app(ShiftService::class)->open($data),'shift-close' => app(ShiftService::class)->close($data['id'], $data),
                'grn' => app(GoodsReceiptService::class)->post($data),'transfer-create' => app(StockTransferService::class)->create($data),'transfer-send' => app(StockTransferService::class)->send($data['id']),'transfer-receive' => app(StockTransferService::class)->receive($data['id']),
                'adjustment-request' => app(AdjustmentService::class)->request($data),'adjustment-decide' => app(AdjustmentService::class)->decide($data['id'], $data['approved'], $data['reason']),
                'void-request' => app(VoidService::class)->request($data['id'], $data['reason']),'void-decide' => app(VoidService::class)->decide($data['id'], $data['approved'], $data['reason']), 'return' => app(ReturnService::class)->process($data['id'], $data),
                'product' => app(CatalogService::class)->product($data),'notifications-read' => $this->readNotifications(),default => app(CatalogService::class)->save(substr($command, 8), $data)
            };

            return is_array($result) ? $result : ['id' => $result->id, 'status' => $result->status ?? null];
        });

        if ($command === 'catalog-cashier-limit') {
            $result['location'] = app(CounterLocationPresenter::class)->present(StockLocation::whereKey($data['id'])->firstOrFail());
        }

        return response()->json($result);
    }

    public function productImage(int $id): StreamedResponse
    {
        $product = Product::whereKey($id)->firstOrFail();
        $path = $product->image_path;
        abort_unless($path && str_starts_with($path, 'product-images/'.$product->company_id.'/') && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, no-cache', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function report(Request $request, string $name, ReportService $reports): JsonResponse
    {
        $this->authorizeReport($request, $name);

        return response()->json($reports->generate($name, app(ReportFilterService::class)->validate($request, $name)));
    }

    public function pdfReport(Request $request, string $name, ReportService $reports, ReportPdfService $pdf): Response
    {
        $this->authorizeReport($request, $name);
        $filters = app(ReportFilterService::class)->validate($request, $name);
        if (! in_array($name, ['Cash Custody Balance', 'Waiter Outstanding'], true)) {
            $filters += ['from' => now()->toDateString(), 'to' => now()->toDateString()];
        }
        $data = $pdf->all(fn (int $page) => $reports->generate($name, [...$filters, 'page' => $page]));

        return $pdf->download($name, $filters, [$data]);
    }

    public function exportReport(Request $request, string $name, ReportService $reports): StreamedResponse
    {
        $this->authorizeReport($request, $name);
        $filters = app(ReportFilterService::class)->validate($request, $name);
        $first = $reports->generate($name, [...$filters, 'page' => 1]);

        return response()->streamDownload(function () use ($name, $reports, $filters) {
            DB::transaction(function () use ($name, $reports, $filters) {
                $first = $reports->generate($name, [...$filters, 'page' => 1]);
                $out = fopen('php://output', 'w');
                if ($out === false) {
                    throw new \RuntimeException('Cannot open export output.');
                }
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, array_map(UiText::get(...), $first['headers']), ',', '"', '');
                $result = $first;
                do {
                    foreach ($result['rows'] as $row) {
                        $safe = array_map(function ($value) {
                            $text = (string) ($value ?? '');

                            return preg_match('/^[=+@\-\t\r]/', $text) && ! is_numeric($text) ? "'".$text : $text;
                        }, $row);
                        fputcsv($out, $safe, ',', '"', '');
                    }
                    if (! $result['has_more']) {
                        break;
                    }
                    $result = $reports->generate($name, [...$filters, 'page' => $result['page'] + 1]);
                } while (true);
                fclose($out);
            });
        }, 'Nightshift-'.preg_replace('/[^A-Za-z0-9-]/', '-', $name).(! empty($first['shift_context']) ? '-'.$first['shift_context']['number'] : '').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function receipt(int $id): View
    {
        $o = app(OrderService::class)->accessible($id);

        return view('receipt', ['order' => $o->load('items'), 'company' => auth()->user()->company, 'branch' => Branch::findOrFail($o->branch_id), 'waiter' => User::where('company_id', $o->company_id)->findOrFail($o->waiter_id), 'cashier' => User::where('company_id', $o->company_id)->find($o->issued_by), 'table' => ServiceTable::find($o->service_table_id), 'payments' => PaymentAllocation::where('order_id', $o->id)->get()->map(fn ($a) => Payment::findOrFail($a->payment_id))]);
    }

    public function issueTicket(int $id): View
    {
        Gate::authorize('view-orders');
        $order = app(OrderService::class)->accessible($id);
        abort_unless($order->issued_at !== null, 409, 'The order has not been issued.');
        $tickets = app(SalesTicketService::class);

        return view('issue-ticket', ['ticket' => $tickets->data($order), 'copies' => $tickets->copies($tickets->preferences()), 'reprint' => true, 'batch' => null, 'paperWidth' => $tickets->preferences()['paper_width']]);
    }

    public function salesTicket(string $token): View
    {
        $batch = app(SalesTicketService::class)->accessible($token);

        return view('issue-ticket', ['ticket' => app(SalesTicketService::class)->visibleTicket($batch), 'copies' => $batch->copies, 'reprint' => $batch->reprint, 'batch' => $batch, 'paperWidth' => app(SalesTicketService::class)->preferences()['paper_width']]);
    }

    public function dispatchTicket(string $token): JsonResponse
    {
        return response()->json(['print' => app(SalesTicketService::class)->dispatch($token)]);
    }

    private function authorizeReport(Request $request, string $name): void
    {
        $name = $name === 'Salaries/Wages' ? 'Salaries and Wages' : $name;
        if (! in_array($name, config('treasury.reports'), true)) {
            Gate::authorize('view-reports');

            return;
        }
        $actor = $request->user();
        abort_unless($actor->active && $actor->company->active && ! $actor->isPosTerminal(), 403);
        if ($name === 'Cashier Handover') {
            abort_unless($actor->hasPermission('review-cash-handovers') || $actor->hasPermission('view-treasury') || ($actor->hasPermission('issue-drinks') && $actor->hasPermission('close-shifts')), 403);
        } else {
            Gate::authorize('view-treasury');
        }
    }

    /** @return array<string,mixed> */
    private function filters(Request $request, bool $report = false): array
    {
        return $request->validate(['unit_context' => ['nullable', 'boolean'], 'pos_context' => ['nullable', 'boolean'], 'business_unit_id' => ['nullable', 'integer', 'min:1'], 'custodian_id' => ['nullable', 'integer', 'min:1'], 'shift_id' => ['nullable', 'integer', 'min:1'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000'], 'branch_id' => ['nullable', 'integer', 'min:1'], 'stock_location_id' => ['nullable', 'integer', 'min:1'], 'waiter_id' => ['nullable', 'integer', 'min:1'], 'issued_by' => ['nullable', 'integer', 'min:1'], 'category_id' => ['nullable', 'integer', 'min:1'], 'product_id' => ['nullable', 'integer', 'min:1'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'period' => ['nullable', 'in:Today,Yesterday,This week'], 'all_branches' => ['nullable', 'boolean'], 'search' => ['nullable', 'string', 'max:100']]);
    }

    /**
     * @return array<string, mixed>
     */
    private function readNotifications(): array
    {
        foreach (app(StaffVisibility::class)->notifications(BusinessNotification::query())->latest()->limit(100)->get() as $n) {
            $n->update(['read_by' => array_values(array_unique([...($n->read_by ?? []), auth()->id()]))]);
        }

        return ['read' => true];
    }
}
