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
use App\Services\ReportService;
use App\Services\ReturnService;
use App\Services\ShiftService;
use App\Services\StaffPinService;
use App\Services\StockTransferService;
use App\Services\TransactionService;
use App\Services\VoidService;
use App\Services\WaiterLedgerService;
use App\Services\WorkspaceService;
use App\Support\UiText;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
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
            return response()->json(app(OrderService::class)->send($data, $key));
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
        return response()->json($reports->generate($name, $this->filters($request, true)));
    }

    public function exportReport(Request $request, string $name, ReportService $reports): StreamedResponse
    {
        $filters = $this->filters($request, true);
        $first = $reports->generate($name, [...$filters, 'page' => 1]);

        return response()->streamDownload(function () use ($name, $reports, $filters, $first) {
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
        }, 'Nightshift-'.preg_replace('/[^A-Za-z0-9-]/', '-', $name).'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
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
        $actor = auth()->user();
        abort_unless($order->issued_at !== null, 409, 'The order has not been issued.');

        return view('issue-ticket', ['order' => $order->load('items'), 'company' => $actor->company, 'branch' => Branch::findOrFail($order->branch_id), 'waiter' => User::where('company_id', $order->company_id)->findOrFail($order->waiter_id), 'counter' => StockLocation::findOrFail($order->stock_location_id), 'outstanding' => app(WaiterLedgerService::class)->visibleBalance($order->waiter_id, app(StaffVisibility::class)->settlementOnly() ? $order->stock_location_id : null)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request, bool $report = false): array
    {
        return $request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:100000'], 'branch_id' => ['nullable', 'integer', 'min:1'], 'stock_location_id' => ['nullable', 'integer', 'min:1'], 'waiter_id' => ['nullable', 'integer', 'min:1'], 'issued_by' => ['nullable', 'integer', 'min:1'], 'category_id' => ['nullable', 'integer', 'min:1'], 'product_id' => ['nullable', 'integer', 'min:1'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'period' => ['nullable', 'in:Today,Yesterday,This week'], 'all_branches' => ['nullable', 'boolean'], 'search' => ['nullable', 'string', 'max:100']]);
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
