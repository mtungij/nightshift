<?php

namespace App\Services;

use App\Domain\Decimal as D;
use App\Domain\StaffVisibility;
use App\Domain\TenantContext;
use App\Models\Order;
use App\Models\WaiterLedgerEntry;

final class WaiterLedgerService
{
    public function balance(int $waiter, ?int $shift = null): string
    {
        $q = WaiterLedgerEntry::withoutGlobalScopes()->where('company_id', app(TenantContext::class)->companyId())->where('waiter_id', $waiter);
        if ($shift) {
            $q->where('shift_id', $shift);
        }
        $row = $q->selectRaw('COALESCE(SUM(debit),0) AS debits, COALESCE(SUM(credit),0) AS credits')->toBase()->first();

        return D::sub((string) $row->debits, (string) $row->credits);
    }

    public function visibleBalance(int $waiter, ?int $counter = null): string
    {
        $query = app(StaffVisibility::class)->ledger(WaiterLedgerEntry::where('waiter_id', $waiter), $counter);
        $row = $query->selectRaw('COALESCE(SUM(debit),0) AS debits, COALESCE(SUM(credit),0) AS credits')->toBase()->first();

        return D::sub((string) $row->debits, (string) $row->credits);
    }

    public function post(Order $order, string $type, string $debit, string $credit, string $refType, int $refId, string $description, ?int $payment = null, ?int $return = null): WaiterLedgerEntry
    {
        return WaiterLedgerEntry::create(['branch_id' => $order->branch_id, 'waiter_id' => $order->waiter_id, 'shift_id' => $order->shift_id, 'order_id' => $order->id, 'payment_id' => $payment, 'return_id' => $return, 'entry_type' => $type, 'reference_type' => $refType, 'reference_id' => $refId, 'debit' => $debit, 'credit' => $credit, 'description' => $description, 'created_by' => app(TenantContext::class)->user()->id]);
    }
}
