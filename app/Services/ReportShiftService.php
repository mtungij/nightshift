<?php

namespace App\Services;

use App\Domain\StaffVisibility;
use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\BusinessUnit;
use App\Models\CashHandover;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\ShiftStockSnapshot;
use App\Models\StockLocation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

/** Read-only operational attribution; never creates or repairs historical links. */
final class ReportShiftService
{
    public function supports(string $report): bool
    {
        return in_array($report, ['Audit trail', 'Stock Reconciliation'], true) || (in_array($report, config('report_pdf.sales_reports'), true) || array_key_exists($report, config('report_pdf.filters'))) && in_array('shift_id', config('report_pdf.filters.'.$report, config('report_pdf.default_filters')), true);
    }

    /** @return Builder<Shift> */
    public function visible(string $report): Builder
    {
        $actor = app(TenantContext::class)->user();
        abort_unless($actor->active && $actor->company->active && ! $actor->isPosTerminal(), 403);
        $q = Shift::query();
        if ($actor->counters_configured) {
            $q->whereIn('stock_location_id', DB::table('counter_user')->where('company_id', $actor->company_id)->where('user_id', $actor->id)->select('stock_location_id'));
        }
        if (in_array($report, ['Cashier Handover', 'Cash Received from Cashiers'], true)) {
            if ($report === 'Cashier Handover') {
                abort_unless($actor->hasPermission('review-cash-handovers') || $actor->hasPermission('view-treasury') || ($actor->hasPermission('issue-drinks') && $actor->hasPermission('close-shifts')), 403);
                if (! $actor->hasPermission('review-cash-handovers') && ! $actor->hasPermission('oversee-treasury')) {
                    $q->where(fn ($q) => $q->where('user_id', $actor->id)->orWhereIn('id', CashHandover::where('to_user_id', $actor->id)->select('source_shift_id')));
                }
            } else {
                Gate::authorize('view-treasury');
                $q->whereIn('id', CashHandover::whereIn('id', app(TreasuryService::class)->query([])->where('transaction_type', 'CASHIER_RECEIPT')->select('handover_id'))->select('source_shift_id'));
            }
            $q->where('type', 'CASHIER');
        } elseif ($report === 'Stock Reconciliation') {
            Gate::authorize('open-shifts');
            $q = app(StaffVisibility::class)->shifts($q)->where('type', 'CASHIER');
        } else {
            Gate::authorize($report === 'Audit trail' ? 'view-audit' : 'view-reports');
            $visibility = app(StaffVisibility::class);
            if (in_array($report, ['Cashier Shift', 'Cash Shortage / Excess'], true)) {
                abort_unless($visibility->broad() || $visibility->settlementOnly(), 403);
            }
            if ($report === 'Waiter Settlement') {
                abort_if($visibility->settlementOnly(), 403);
            }
            $q = $visibility->shifts($q);
        }
        if (in_array($report, ['Cashier Shift', 'Cash Shortage / Excess', 'Opening Stock', 'Closing Stock'], true)) {
            $q->where('type', 'CASHIER');
        }
        if ($report === 'Waiter Shift') {
            $q->where('type', 'WAITER');
        }

        return $q;
    }

    /** @param array<string,mixed> $filters */
    public function resolve(array $filters, string $report): ?Shift
    {
        if (empty($filters['shift_id'])) {
            return null;
        }
        abort_unless($this->supports($report), 422, __('ui.This filter is not available for this report.'));
        $shift = $this->visible($report)->whereKey((int) $filters['shift_id'])->firstOrFail();
        foreach (['branch_id' => 'branch_id', 'business_unit_id' => 'business_unit_id', 'stock_location_id' => 'stock_location_id'] as $filter => $attribute) {
            abort_if(! empty($filters[$filter]) && (int) $filters[$filter] !== $shift->$attribute, 422, __('ui.Selected shift does not match the report location.'));
        }
        $actor = app(TenantContext::class)->user();
        abort_unless($actor->canAccessBranch($shift->branch_id) && $actor->canAccessBusinessUnit($shift->business_unit_id), 403);

        return $shift;
    }

    /** @return array<string,mixed> */
    public function context(Shift $shift): array
    {
        $frozen = ShiftStockSnapshot::where('shift_id', $shift->id)->where('kind', 'CLOSING')->first()->data ?? [];
        $counter = $frozen['counter'] ?? StockLocation::withoutGlobalScopes()->where('company_id', $shift->company_id)->whereKey($shift->stock_location_id)->value('name');

        return ['id' => $shift->id, 'number' => $shift->number, 'type' => $shift->type, 'employee' => User::where('company_id', $shift->company_id)->findOrFail($shift->user_id)->name,
            'branch_id' => $shift->branch_id, 'branch' => $frozen['branch'] ?? Branch::withoutGlobalScopes()->where('company_id', $shift->company_id)->whereKey($shift->branch_id)->value('name'),
            'business_unit_id' => $shift->business_unit_id, 'business_unit' => BusinessUnit::withoutGlobalScopes()->where('company_id', $shift->company_id)->whereKey($shift->business_unit_id)->value('name'),
            'show_location' => $frozen['show_location'] ?? app(UnitOperationalService::class)->showLocation($shift->company_id, $shift->business_unit_id, $shift->stock_location_id), 'counter_id' => $shift->stock_location_id, 'counter' => $counter, 'opened_at' => $shift->opened_at->toDateTimeString(), 'closed_at' => $shift->closed_at?->toDateTimeString(),
            'status' => $shift->status === 'OPEN' ? 'Open / Live' : 'Closed', 'generated_at' => now()->toDateTimeString()];
    }

    /** @param Builder<Order> $q
     * @return Builder<Order>
     */
    public function orders(Builder $q, Shift $shift): Builder
    {
        if ($shift->status === 'CLOSED') {
            $this->historicalOrders($q, $shift);
        }

        return $q->where($shift->type === 'CASHIER' ? 'orders.issued_shift_id' : 'orders.shift_id', $shift->id)->whereNotNull('orders.issued_at')->where('orders.issued_at', '<=', $shift->closed_at ?? now());
    }

    /** Restore mutable order projections as of final close from immutable posted records.
     * @param  Builder<Order>  $q
     */
    private function historicalOrders(Builder $q, Shift $shift): void
    {
        $cutoff = $shift->closed_at;
        $returns = DB::table('returns')->where('company_id', $shift->company_id)->where('created_at', '<=', $cutoff)->selectRaw('order_id,SUM(total) AS returned')->groupBy('order_id');
        $allocated = DB::table('payment_allocations')->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')->where('payment_allocations.company_id', $shift->company_id)->where('payments.created_at', '<=', $cutoff)->where('payments.is_credit', false)->selectRaw('payment_allocations.order_id,payment_allocations.amount');
        $direct = DB::table('payments')->where('company_id', $shift->company_id)->where('created_at', '<=', $cutoff)->where('is_credit', false)->whereNotNull('order_id')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('payment_allocations')->whereColumn('payment_allocations.payment_id', 'payments.id'))->selectRaw('order_id,amount');
        $paid = DB::query()->fromSub($allocated->unionAll($direct), 'paid_history')->selectRaw('order_id,SUM(amount) AS paid')->groupBy('order_id');
        $columns = array_values(array_diff(Schema::getColumnListing('orders'), ['total', 'amount_paid', 'balance', 'status', 'return_total']));
        $total = 'saved_orders.subtotal - saved_orders.discount - COALESCE(returned_totals.returned,0)';
        $balance = '('.$total.') - COALESCE(paid_totals.paid,0)';
        $sub = DB::table('orders as saved_orders')->where('saved_orders.company_id', $shift->company_id)->leftJoinSub($returns, 'returned_totals', 'returned_totals.order_id', '=', 'saved_orders.id')->leftJoinSub($paid, 'paid_totals', 'paid_totals.order_id', '=', 'saved_orders.id')
            ->select(array_map(fn ($column) => 'saved_orders.'.$column, $columns))->selectRaw($total.' AS total,COALESCE(returned_totals.returned,0) AS return_total,COALESCE(paid_totals.paid,0) AS amount_paid,'.$balance.' AS balance')
            ->selectRaw('CASE WHEN ('.$total.")=0 AND COALESCE(returned_totals.returned,0)>0 THEN 'RETURNED' WHEN (".$balance.")<=0 THEN 'PAID' WHEN COALESCE(paid_totals.paid,0)>0 THEN 'PARTIALLY_PAID' ELSE 'ISSUED' END AS status")
            ->whereNotExists(fn ($v) => $v->selectRaw('1')->from('void_requests')->whereColumn('void_requests.order_id', 'saved_orders.id')->where('void_requests.company_id', $shift->company_id)->where('void_requests.status', 'APPROVED')->where('void_requests.decided_at', '<=', $cutoff));
        $q->fromSub($sub, 'orders');
    }

    /** @param Builder<Payment> $q
     * @return Builder<Payment>
     */
    public function payments(Builder $q, Shift $shift): Builder
    {
        if ($shift->type === 'CASHIER') {
            return $q->where('payments.shift_id', $shift->id)->where('payments.created_at', '<=', $shift->closed_at ?? now());
        }
        $orders = Order::where('shift_id', $shift->id)->select('id');
        $allocated = DB::table('payment_allocations')->where('company_id', $shift->company_id)->whereIn('order_id', clone $orders)->selectRaw('payment_id,SUM(amount) AS amount')->groupBy('payment_id');
        $columns = array_values(array_diff(Schema::getColumnListing('payments'), ['amount']));
        $sub = DB::table('payments as saved_payments')->where('saved_payments.company_id', $shift->company_id)->leftJoinSub($allocated, 'shift_allocations', 'shift_allocations.payment_id', '=', 'saved_payments.id')->select(array_map(fn ($c) => 'saved_payments.'.$c, $columns))
            ->selectRaw('CASE WHEN EXISTS (SELECT 1 FROM payment_allocations pa WHERE pa.payment_id=saved_payments.id AND pa.company_id=saved_payments.company_id) THEN COALESCE(shift_allocations.amount,0) ELSE saved_payments.amount END AS amount');

        return $q->fromSub($sub, 'payments')->where(fn ($q) => $q->whereIn('payments.order_id', clone $orders)->orWhereIn('payments.id', DB::table('payment_allocations')->where('company_id', $shift->company_id)->whereIn('order_id', clone $orders)->select('payment_id')))->where('payments.created_at', '<=', $shift->closed_at ?? now());
    }
}
