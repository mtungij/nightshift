<?php

namespace App\Services;

use App\Domain\Decimal as D;
use App\Domain\StaffVisibility;
use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Order;
use App\Models\SalesTicketBatch;
use App\Models\ServiceTable;
use App\Models\StockLocation;
use App\Models\User;
use App\Models\WaiterLedgerEntry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class SalesTicketService
{
    /** @return array<string, mixed> */
    public function preferences(?Company $company = null): array
    {
        $company ??= Company::findOrFail(app(TenantContext::class)->companyId());

        return array_replace(['auto_print' => true, 'waiter_copy' => true, 'cashier_copy' => true, 'number_of_copies' => 2, 'paper_width' => '80mm'], $company->settings['sales_print'] ?? []);
    }

    /** Number of copies is the total tickets per batch; enabled types alternate.
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    public function copies(array $settings): array
    {
        $types = [];
        if ($settings['waiter_copy']) {
            $types[] = 'WAITER COPY';
        }
        if ($settings['cashier_copy']) {
            $types[] = 'CASHIER COPY';
        }
        $copies = [];
        for ($i = 0; $types && $i < $settings['number_of_copies']; $i++) {
            $copies[] = $types[$i % count($types)];
        }

        return $copies;
    }

    public function prepare(int $id, string $key, bool $reprint = false): ?SalesTicketBatch
    {
        // Called after order commit. Its independent transaction cannot roll back a sale.
        $order = app(OrderService::class)->accessible($id);
        abort_unless($order->issued_at !== null, 409, 'The order has not been issued.');
        $settings = $this->preferences();
        if (! $reprint && ! $settings['auto_print']) {
            return null;
        }
        $copies = $this->copies($settings);
        if (! $copies) {
            return null;
        }

        return app(TransactionService::class)->run(function () use ($order, $key, $reprint, $copies) {
            $requestKey = $reprint ? 'reprint:'.$key : 'initial:'.$order->id;
            $existing = SalesTicketBatch::where('request_key', $requestKey)->first();
            if ($existing) {
                abort_unless($existing->order_id === $order->id && (! $reprint || $existing->user_id === auth()->id()), 409);

                return $existing;
            }
            $original = SalesTicketBatch::where('order_id', $order->id)->where('reprint', false)->first();
            $batch = SalesTicketBatch::create(['branch_id' => $order->branch_id, 'order_id' => $order->id, 'user_id' => auth()->id(), 'token' => (string) Str::uuid(), 'request_key' => $requestKey, 'reprint' => $reprint, 'ticket' => $original->ticket ?? $this->data($order), 'copies' => $copies]);
            foreach ($copies as $index => $copy) {
                app(AuditService::class)->record($reprint ? 'SALES_TICKET_REPRINT' : 'SALES_TICKET_PRINT', 'Orders', 'Order', $order->id, [], ['batch' => $batch->token, 'copy_type' => $copy, 'copy_number' => $index + 1, 'reprint' => $reprint, 'event' => 'PRINT_REQUESTED'], $order->branch_id);
            }

            return $batch;
        });
    }

    /** @return array<string, mixed> */
    public function data(Order $order): array
    {
        Gate::authorize('view', $order);
        $company = app(TenantContext::class)->user()->company;
        $issue = WaiterLedgerEntry::where('order_id', $order->id)->where('entry_type', 'ISSUE')->first();
        $issuedTotal = $issue->debit ?? $order->total;

        return ['order_id' => $order->id, 'business' => $company->trading_name ?: $company->name, 'branch' => Branch::findOrFail($order->branch_id)->name, 'invoice' => $order->invoice_number, 'order_note' => $order->order_note,
            'issued_at' => $order->issued_at?->copy()->timezone($company->timezone ?: 'Africa/Dar_es_Salaam')->format('d M Y H:i'),
            'waiter' => User::where('company_id', $order->company_id)->findOrFail($order->waiter_id)->name,
            'table' => ServiceTable::find($order->service_table_id)->name ?? '—', 'counter' => StockLocation::findOrFail($order->stock_location_id)->name,
            'items' => $order->items->map(fn ($item) => $item->only(['product_name', 'quantity', 'unit_name', 'unit_price', 'total']))->all(),
            'subtotal' => $order->subtotal, 'discount' => $order->discount, 'total' => $issuedTotal, 'currency' => $company->currency,
            'outstanding' => $this->outstanding($order),
            'status' => bccomp($issuedTotal, '0', 2) > 0 ? 'UNPAID' : 'SETTLED'];
    }

    private function outstanding(Order $order): string
    {
        $issueId = WaiterLedgerEntry::where('order_id', $order->id)->where('entry_type', 'ISSUE')->value('id');
        $visibility = app(StaffVisibility::class);
        $ledger = $visibility->ledger(WaiterLedgerEntry::where('waiter_id', $order->waiter_id), $visibility->settlementOnly() ? $order->stock_location_id : null)->where('id', '<=', $issueId ?? 0);
        $sums = $ledger->selectRaw('COALESCE(SUM(debit),0) AS debits, COALESCE(SUM(credit),0) AS credits')->toBase()->first();

        return D::sub((string) $sums->debits, (string) $sums->credits);
    }

    /** Apply the current viewer's ledger visibility even when another actor made the batch.
     * @return array<string, mixed>
     */
    public function visibleTicket(SalesTicketBatch $batch): array
    {
        $order = app(OrderService::class)->accessible($batch->order_id);

        return array_replace($batch->ticket, ['outstanding' => $this->outstanding($order)]);
    }

    public function dispatch(string $token): bool
    {
        $batch = $this->accessible($token);

        return app(TransactionService::class)->run(function () use ($batch) {
            $batch = SalesTicketBatch::lockForUpdate()->findOrFail($batch->id);
            if ($batch->dispatched_at !== null) {
                return false;
            }
            $batch->update(['dispatched_at' => now()]);
            foreach ($batch->copies as $index => $copy) {
                app(AuditService::class)->record('SALES_TICKET_DISPATCH', 'Orders', 'Order', $batch->order_id, [], ['batch' => $batch->token, 'copy_type' => $copy, 'copy_number' => $index + 1, 'reprint' => $batch->reprint, 'event' => 'BROWSER_PRINT_REQUESTED'], $batch->branch_id);
            }

            return true;
        });
    }

    public function accessible(string $token): SalesTicketBatch
    {
        $batch = SalesTicketBatch::where('token', $token)->firstOrFail();
        app(OrderService::class)->accessible($batch->order_id);

        return $batch;
    }

    /** @return array<string, mixed> */
    public function response(?SalesTicketBatch $batch): array
    {
        return ['auto_print' => $batch !== null, 'print_url' => $batch ? route('nightshift.sales-ticket', $batch->token, false) : null, 'print_batch' => $batch?->token, 'copies' => $batch->copies ?? []];
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function savePreferences(array $data): array
    {
        Gate::authorize('manage-company');
        $company = Company::findOrFail(app(TenantContext::class)->companyId());
        $settings = $company->settings ?? [];
        $old = $this->preferences($company);
        $settings['sales_print'] = $data;
        $company->update(['settings' => $settings]);
        app(AuditService::class)->record('SALES_PRINT_SETTINGS_SAVED', 'Settings', 'Company', $company->id, $old, $data);

        return ['saved' => true];
    }
}
