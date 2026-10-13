<?php

namespace App\Services\WhatsApp;

use App\Domain\Decimal as D;
use App\Domain\TenantContext;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Product;
use App\Models\ServiceTable;
use App\Models\Shift;
use App\Models\Stock;
use App\Models\StockAdjustment;
use App\Models\StockLocation;
use App\Models\User;
use App\Models\VoidRequest;
use App\Models\WhatsAppAlertState;
use App\Models\WhatsAppRecipient;
use App\Services\ReportService;
use App\Services\TransactionService;
use App\Support\QuantityFormatter;

final class OperationalAlerts
{
    /** @param array<string, mixed> $options */
    public function inspect(AuditLog $audit, array $options): void
    {
        if ($audit->action === 'SHIFT_CLOSED') {
            $shift = Shift::find($audit->reference_id);
            if ($shift && $shift->type === 'CASHIER' && $shift->status === 'CLOSED') {
                foreach ($this->recipients($shift->branch_id, 'shift_reports') as $recipient) {
                    if ($options['shift_report']) {
                        app(Outbox::class)->enqueue($recipient, 'SHIFT', (string) $shift->id, [$shift->branch_id], ['language' => $options['language']], referenceType: 'Shift', referenceId: $shift->id);
                    }
                }
                $shortage = D::sub('0', $shift->cash_difference);
                if ($options['cash_shortage'] && D::cmp($shortage, '0') > 0 && D::cmp($shortage, $options['cash_threshold']) >= 0) {
                    $this->alert($audit, 'CASH_SHORTAGE', ['Branch' => Branch::findOrFail($shift->branch_id)->name, 'Cashier' => $this->user($shift->user_id), 'Expected cash' => $this->money($shift->expected_cash), 'Actual cash' => $this->money($shift->actual_cash), 'Shortage' => $this->money($shortage)], $options);
                }
            }
        }
        if ($audit->action === 'DRINKS_ISSUED') {
            $order = Order::find($audit->reference_id);
            if ($order) {
                if ($options['large_sale'] && D::cmp($order->total, $options['large_sale_threshold']) >= 0) {
                    $this->alert($audit, 'LARGE_SALE', ['Invoice' => $order->invoice_number, 'Amount' => $this->money($order->total), 'Waiter' => $this->user($order->waiter_id), 'Table' => ServiceTable::whereKey($order->service_table_id)->first()->name ?? '—', 'Branch' => Branch::findOrFail($order->branch_id)->name], $options);
                }
                $this->waiter($audit, $order, $options);
            }
        }
        if (in_array($audit->action, ['PAYMENT_RECEIVED', 'RETURN_PROCESSED'], true)) {
            $order = $audit->action === 'PAYMENT_RECEIVED' ? Order::find($audit->reference_id) : Order::whereKey($audit->new_values['order'] ?? 0)->first();
            if ($order) {
                $this->waiter($audit, $order, $options);
            }
        }
        if ($audit->action === 'VOID_APPROVED' && $options['void']) {
            $void = VoidRequest::find($audit->reference_id);
            if ($void && $void->status === 'APPROVED') {
                $order = Order::findOrFail($void->order_id);
                $this->alert($audit, 'VOID', ['Invoice' => $order->invoice_number, 'Amount' => $this->money(D::sub(D::sub($order->subtotal, $order->discount), $order->return_total)), 'Waiter' => $this->user($order->waiter_id), 'Requested by' => $this->user($void->requested_by), 'Approved by' => $this->user($void->approved_by), 'Reason' => $void->reason], $options);
                $this->waiter($audit, $order, $options);
            }
        }
        if ($audit->action === 'STOCK_ADJUSTED') {
            $adjustment = StockAdjustment::find($audit->reference_id);
            if ($adjustment && $adjustment->status === 'APPROVED') {
                $kind = match ($adjustment->reason) {
                    'BREAKAGE' => 'breakage', 'COMPLIMENTARY' => 'complimentary', default => null
                };
                $value = D::money((string) $adjustment->items()->sum('cost'));
                if ($kind && $options[$kind] && D::cmp($value, $options[$kind.'_threshold']) >= 0) {
                    $details = ['Amount' => $this->money($value), 'Approved by' => $this->user($adjustment->approved_by), 'Reason' => $adjustment->notes];
                    foreach ($adjustment->items as $item) {
                        $details[Product::findOrFail($item->product_id)->name] = QuantityFormatter::format($item->base_quantity);
                    }
                    $this->alert($audit, strtoupper($kind), $details, $options);
                }
            }
        }
        if ($options['low_stock'] && $audit->branch_id) {
            foreach (Stock::where('branch_id', $audit->branch_id)->orderBy('id')->get() as $stock) {
                $product = Product::find($stock->product_id);
                if (! $product || ! $product->active || D::cmp($product->minimum_stock, '0', 6) <= 0) {
                    continue;
                }
                $generation = $this->threshold('stock:'.$stock->stock_location_id.':'.$stock->product_id, $stock->quantity, $product->minimum_stock, true, $options);
                if ($generation !== null) {
                    $this->alert($audit, 'LOW_STOCK', ['Branch' => Branch::findOrFail($stock->branch_id)->name, 'Location' => StockLocation::findOrFail($stock->stock_location_id)->name, 'Product' => $product->name, 'Remaining' => QuantityFormatter::format($stock->quantity).' '.$product->baseUnit->name, 'Minimum' => QuantityFormatter::format($product->minimum_stock)], $options, 'stock:'.$stock->stock_location_id.':'.$stock->product_id.':'.$generation);
                }
            }
        }
    }

    /** @param array<string, mixed> $options */
    private function waiter(AuditLog $audit, Order $order, array $options): void
    {
        if (! $options['waiter_outstanding']) {
            return;
        }
        $report = app(ReportService::class)->generate('Waiter Outstanding', ['branch_id' => $order->branch_id, 'waiter_id' => $order->waiter_id]);
        $balance = (string) ($report['rows'][0][3] ?? '0');
        $generation = $this->threshold('waiter:'.$order->branch_id.':'.$order->waiter_id, $balance, $options['waiter_threshold'], false, $options);
        if ($generation !== null) {
            $this->alert($audit, 'WAITER_OUTSTANDING', ['Waiter' => $this->user($order->waiter_id), 'Branch' => Branch::findOrFail($order->branch_id)->name, 'Outstanding' => $this->money($balance), 'Unpaid orders' => (string) Order::where('branch_id', $order->branch_id)->where('waiter_id', $order->waiter_id)->whereNotNull('issued_at')->where('balance', '>', 0)->count()], $options, 'waiter:'.$order->branch_id.':'.$order->waiter_id.':'.$generation);
        }
    }

    /** @param array<string, mixed> $options */
    private function threshold(string $key, string $value, string $limit, bool $lower, array $options): ?int
    {
        return app(TransactionService::class)->run(function () use ($key, $value, $limit, $lower, $options) {
            $state = WhatsAppAlertState::firstOrCreate(['state_key' => $key]);
            $trigger = $lower ? D::cmp($value, $limit, 6) < 0 : D::cmp($value, $limit, 6) >= 0;
            if (! $trigger) {
                $state->update(['triggered' => false, 'last_value' => $value]);

                return null;
            }
            $cooldown = ! $state->last_alert_at || $state->last_alert_at->lte(now()->subMinutes((int) $options['cooldown_minutes']));
            $drop = $lower && D::cmp($state->last_value, '0', 6) > 0 && D::cmp(D::sub($state->last_value, $value, 6), D::mul($state->last_value, bcdiv((string) $options['low_stock_drop_percent'], '100', 6), 6), 6) >= 0;
            if ($state->triggered && ! $cooldown && ! $drop) {
                return null;
            }
            $state->update(['triggered' => true, 'last_value' => $value, 'last_alert_at' => now(), 'generation' => $state->generation + 1]);

            return $state->generation;
        });
    }

    /** @return list<WhatsAppRecipient> */
    private function recipients(int $branch, string $preference): array
    {
        return array_values(WhatsAppRecipient::where('active', true)->where($preference, true)->get()->filter(fn ($r) => in_array($branch, app(RecipientService::class)->branches($r), true))->values()->all());
    }

    /** @param array<string, string> $details
     * @param  array<string, mixed>  $options
     */
    private function alert(AuditLog $audit, string $type, array $details, array $options, ?string $period = null): void
    {
        if (! $audit->branch_id) {
            return;
        }
        $titles = ['WAITER_OUTSTANDING' => ['WAITER OUTSTANDING', 'TAHADHARI YA DENI LA MHUDUMU'], 'CASH_SHORTAGE' => ['CASH SHORTAGE', 'PUNGUFU YA CASH'], 'LOW_STOCK' => ['LOW STOCK', 'STOCK NDOGO'], 'VOID' => ['VOID APPROVED', 'ODA IMEFUTWA'], 'BREAKAGE' => ['BREAKAGE', 'VILIVYOVUNJIKA'], 'COMPLIMENTARY' => ['COMPLIMENTARY', 'VYA BURE'], 'LARGE_SALE' => ['LARGE SALE', 'MAUZO MAKUBWA']];
        $sw = ['Branch' => 'Tawi', 'Cashier' => 'Keshia', 'Expected cash' => 'Cash Inayotarajiwa', 'Actual cash' => 'Cash Halisi', 'Shortage' => 'Pungufu', 'Amount' => 'Kiasi', 'Waiter' => 'Mhudumu', 'Table' => 'Meza', 'Requested by' => 'Ameomba', 'Approved by' => 'Ameidhinisha', 'Reason' => 'Sababu', 'Location' => 'Sehemu', 'Product' => 'Bidhaa', 'Remaining' => 'Zilizobaki', 'Minimum' => 'Kiwango cha Chini', 'Outstanding' => 'Deni', 'Unpaid orders' => 'Oda Zisizolipwa'];
        $lang = $options['language'];
        $lines = ['⚠️ *'.$titles[$type][$lang === 'sw' ? 1 : 0].'*', ''];
        foreach ($details as $label => $value) {
            $lines[] = ($lang === 'sw' ? ($sw[$label] ?? $label) : $label).': '.$value;
        }
        $message = implode("\n", [...$lines, '', 'Generated by Nightshift']);
        foreach ($this->recipients($audit->branch_id, 'critical_alerts') as $recipient) {
            app(Outbox::class)->enqueue($recipient, $type, $period ?? 'audit:'.$audit->id, [$audit->branch_id], ['language' => $lang], $message, $audit->reference_type, (int) $audit->reference_id);
        }
    }

    private function user(?int $id): string
    {
        return $id ? (User::where('company_id', app(TenantContext::class)->companyId())->whereKey($id)->first()->name ?? '—') : '—';
    }

    private function money(string $amount): string
    {
        return app(ReportBuilder::class)->money($amount);
    }
}
