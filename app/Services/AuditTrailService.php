<?php

namespace App\Services;

use App\Domain\StaffVisibility;
use App\Domain\TenantContext;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BusinessUnit;
use App\Models\CashHandover;
use App\Models\GoodsReceipt;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shift;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\TreasuryEntry;
use App\Models\User;
use App\Support\UiText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Read-only presentation. Stored audit history is never changed. */
final class AuditTrailService
{
    public const ACTIONS = [
        'WHATSAPP_DEVICE_UPDATED' => 'WhatsApp Device Updated',
        'WHATSAPP_DEVICE_SAVED' => 'WhatsApp Device Updated',
        'WHATSAPP_CONNECTION_TEST_REQUESTED' => 'WhatsApp Connection Test Requested',
        'WHATSAPP_CONNECTION_STATUS_CHANGED' => 'WhatsApp Connection Status Changed',
        'CASHIER_LIMIT_UPDATED' => 'Cashier Limit Updated',
        'BUSINESS_UNIT_SAVED' => 'Business Unit Created / Updated', 'LOGIN' => 'User Logged In',
        'SHIFT_OPENED' => 'Shift Opened', 'SHIFT_CLOSED' => 'Shift Closed', 'SHIFT_STOCK_OPENING' => 'Opening Stock Captured',
        'USER_SAVED' => 'Staff Member Updated', 'USER_UNITS_ASSIGNED' => 'Staff Business Units Updated',
        'USER_COUNTERS_ASSIGNED' => 'Staff Counters Updated', 'USER_BRANCHES_ASSIGNED' => 'Staff Branches Updated',
        'USER_STATUS_CHANGED' => 'Staff Status Updated', 'STAFF_PIN_RESET' => 'Staff PIN Reset',
        'PAYMENT_RECEIVED' => 'Payment Received', 'DRINKS_ISSUED' => 'Order Issued', 'ORDER_CREATED' => 'Order Created', 'ORDER_UPDATED' => 'Order Updated',
        'CASH_HANDOVER_PENDING' => 'Cash Handover Created', 'CASH_HANDOVER_RECEIVED' => 'Cash Handover Received',
        'CASH_HANDOVER_DISPUTED' => 'Cash Handover Disputed', 'CASH_HANDOVER_APPROVED' => 'Cash Handover Approved',
        'CASH_HANDOVER_CANCELLED' => 'Cash Handover Cancelled', 'TREASURY_EXPENSE' => 'Treasury Expense Recorded',
        'TREASURY_PURCHASE' => 'Treasury Purchase Recorded', 'TREASURY_SALARY' => 'Treasury Wages Recorded',
        'TREASURY_BANK_DEPOSIT' => 'Treasury Bank Deposit Recorded', 'TREASURY_REVERSAL' => 'Treasury Reversal Recorded', 'TREASURY_REVERSED' => 'Treasury Reversal Recorded', 'TREASURY_RECEIPT' => 'Treasury Cash Received',
        'LOCATION_UNIT_ASSIGNED' => 'Location Business Unit Updated', 'PRODUCT_UNIT_ENABLED' => 'Product Enabled in Business Unit', 'PRODUCT_UNIT_DISABLED' => 'Product Disabled in Business Unit', 'BUSINESS_UNIT_PRICE_CHANGED' => 'Business Unit Price Changed', 'PRODUCT_UNIT_CONFIGURATION' => 'Product Unit Availability / Price Updated',
        'TRANSFER_SENT' => 'Stock Transfer Sent', 'TRANSFER_RECEIVED' => 'Stock Transfer Received',
    ];

    private const MODELS = ['Branch' => Branch::class, 'BusinessUnit' => BusinessUnit::class, 'StockLocation' => StockLocation::class,
        'User' => User::class, 'Shift' => Shift::class, 'Order' => Order::class, 'Product' => Product::class,
        'CashHandover' => CashHandover::class, 'TreasuryEntry' => TreasuryEntry::class, 'GoodsReceipt' => GoodsReceipt::class, 'StockTransfer' => StockTransfer::class];

    private const FIELDS = ['branch_id' => 'Branch', 'branch_ids' => 'Branch', 'business_unit_id' => 'BusinessUnit', 'business_unit_ids' => 'BusinessUnit', 'unit_ids' => 'BusinessUnit',
        'stock_location_id' => 'StockLocation', 'counter_id' => 'StockLocation', 'counter_ids' => 'StockLocation', 'stock_location_ids' => 'StockLocation',
        'user_id' => 'User', 'from_user_id' => 'User', 'to_user_id' => 'User', 'custodian_id' => 'User', 'employee_id' => 'User', 'shift_id' => 'Shift', 'source_shift_id' => 'Shift', 'source_shift' => 'Shift', 'related_shift' => 'Shift', 'from' => 'User', 'to' => 'User', 'custodian' => 'User'];

    /** @param array<string, mixed> $filters
     * @return Builder<AuditLog>
     */
    public function query(array $filters): Builder
    {
        app(StaffVisibility::class)->assertFilters($filters);
        $q = AuditLog::query();
        if (! app(TenantContext::class)->user()->hasPermission('all-branches')) {
            $q->whereIn('branch_id', app(TenantContext::class)->branches() ?? []);
        }
        foreach (['branch_id', 'user_id', 'module', 'action'] as $field) {
            if (! empty($filters[$field])) {
                $q->where($field, $filters[$field]);
            }
        }
        $shift = app(ReportShiftService::class)->resolve($filters, 'Audit trail');
        if ($shift) {
            $q->where(function ($q) use ($shift) {
                $q->where(fn ($q) => $q->where('reference_type', 'Shift')->where('reference_id', (string) $shift->id));
                foreach (['Order' => Order::class, 'Payment' => Payment::class, 'CashHandover' => CashHandover::class, 'TreasuryEntry' => TreasuryEntry::class, 'StockMovement' => StockMovement::class] as $type => $model) {
                    $ids = $model::query();
                    if ($type === 'Order') {
                        $ids->where($shift->type === 'CASHIER' ? 'issued_shift_id' : 'shift_id', $shift->id);
                    } elseif ($type === 'TreasuryEntry') {
                        $ids->whereIn('handover_id', CashHandover::where('source_shift_id', $shift->id)->select('id'))->where('transaction_type', 'CASHIER_RECEIPT');
                    } else {
                        $ids->where($type === 'CashHandover' ? 'source_shift_id' : 'shift_id', $shift->id);
                    }
                    $q->orWhere(fn ($q) => $q->where('reference_type', $type)->whereIn('reference_id', $ids->select('id')));
                }
            });
        }
        foreach (['from' => '>=', 'to' => '<='] as $field => $operator) {
            if (! $shift && ! empty($filters[$field])) {
                $q->whereDate('created_at', $operator, $filters[$field]);
            }
        }
        foreach (['business_unit_id', 'stock_location_id'] as $field) {
            if (! empty($filters[$field])) {
                if ($field === 'stock_location_id') {
                    StockLocation::findOrFail((int) $filters[$field]);
                }
                $q->whereIn('id', DB::table('operational_unit_contexts')->where('company_id', app(TenantContext::class)->companyId())->where('record_type', 'audit_logs')->where($field, $filters[$field])->select('record_id'));
            }
        }
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            // Search only safe metadata and scoped names, never the raw JSON payload.
            $actions = array_keys(array_filter(self::ACTIONS, fn ($label, $code) => str_contains(mb_strtolower(UiText::get($label).' '.$code), mb_strtolower($search)), ARRAY_FILTER_USE_BOTH));
            $q->where(function ($q) use ($search, $actions) {
                foreach (['user_name', 'action', 'module', 'reference_type', 'reference_id'] as $field) {
                    $q->orWhere($field, 'like', '%'.$search.'%');
                }
                $q->orWhereIn('action', $actions);
                foreach (['Branch', 'BusinessUnit', 'StockLocation'] as $type) {
                    $ids = self::MODELS[$type]::where('name', 'like', '%'.$search.'%')->pluck('id');
                    $field = ['Branch' => 'branch_id', 'BusinessUnit' => 'business_unit_id', 'StockLocation' => 'stock_location_id'][$type];
                    $q->orWhereIn('id', DB::table('operational_unit_contexts')->where('company_id', app(TenantContext::class)->companyId())->where('record_type', 'audit_logs')->whereIn($field, $ids)->select('record_id'));
                    $q->orWhere(fn ($q) => $q->where('reference_type', $type)->whereIn('reference_id', $ids));
                    if ($type === 'BusinessUnit') {
                        foreach ($ids as $id) {
                            $q->orWhereJsonContains('old_values->unit_ids', (int) $id)->orWhereJsonContains('new_values->unit_ids', (int) $id);
                        }
                    }

                }
                foreach (['Shift' => 'number', 'Order' => 'invoice_number', 'Product' => 'name'] as $type => $column) {
                    $ids = self::MODELS[$type]::where($column, 'like', '%'.$search.'%')->select('id');
                    $q->orWhere(fn ($q) => $q->where('reference_type', $type)->whereIn('reference_id', $ids));
                }
            });
        }

        return $q->latest('id');
    }

    public function label(AuditLog $row): string
    {
        if ($row->action === 'BUSINESS_UNIT_SAVED') {
            return UiText::get(empty($row->old_values) ? 'Business Unit Created' : 'Business Unit Updated');
        }

        return UiText::get(self::ACTIONS[$row->action] ?? ucwords(strtolower(str_replace('_', ' ', $row->action))));
    }

    public function redact(mixed $value): mixed
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $this->redact($decoded);
            }

            return preg_replace('/Bearer\s+[^\s,;]+/i', 'Bearer [REDACTED]', $value);
        }
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $normalized = strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1_$2', (string) $key));
            $normalized = (string) preg_replace('/[^a-z0-9]+/', '_', $normalized);
            $value[$key] = preg_match('/(^|[^a-z])(pin|operational_pin|password|password_confirmation|remember_token|token|csrf|secret|api_key|authorization|bearer|recovery_codes|private_key|session_id|cookie)([^a-z]|$)/i', $normalized) ? '[REDACTED]' : $this->redact($item);
        }

        return $value;
    }

    /** Batch-resolve only records visible to the actor; one query per referenced type.
     * @param  Collection<int, AuditLog>  $rows
     * @return list<array<string, mixed>>
     */
    public function present(Collection $rows): array
    {
        $ids = [];
        $names = [];
        $collect = function (mixed $values) use (&$collect, &$ids): void {
            if (! is_array($values)) {
                return;
            }
            foreach ($values as $key => $value) {
                if (isset(self::FIELDS[$key])) {
                    foreach (is_array($value) ? $value : [$value] as $id) {
                        if (is_numeric($id)) {
                            $ids[self::FIELDS[$key]][] = (int) $id;
                        }
                    }
                }
                if (is_array($value)) {
                    $collect($value);
                }
            }
        };
        foreach ($rows as $row) {
            $collect($this->redact($row->old_values));
            $collect($this->redact($row->new_values));
            if (isset(self::MODELS[$row->reference_type]) && ctype_digit($row->reference_id)) {
                $ids[$row->reference_type][] = (int) $row->reference_id;
            }
            if ($row->branch_id) {
                $ids['Branch'][] = $row->branch_id;
            }
        }
        foreach ($ids as $type => $values) {
            $q = self::MODELS[$type]::query()->whereIn('id', array_unique($values));
            if ($type === 'User') {
                $q = app(StaffVisibility::class)->unitUsers(User::query()->whereIn('id', array_unique($values)))->where('company_id', app(TenantContext::class)->companyId());
            }
            $column = $type === 'Order' ? 'invoice_number' : (in_array($type, ['Shift', 'GoodsReceipt', 'StockTransfer'], true) ? 'number' : ($type === 'CashHandover' || $type === 'TreasuryEntry' ? 'id' : 'name'));
            $names[$type] = $q->pluck($column, 'id')->all();
        }
        $resolve = function (string $field, mixed $value) use ($names): string {
            if (isset(self::FIELDS[$field])) {
                $type = self::FIELDS[$field];

                return implode(', ', array_map(fn ($id) => (string) ($names[$type][$id] ?? UiText::get('Deleted or unavailable record').' #'.(is_scalar($id) ? $id : '?')), is_array($value) ? $value : ($value === null ? [] : [$value])));
            }
            if (is_bool($value)) {
                return UiText::get($value ? 'Yes' : 'No');
            }
            if ($value === null) {
                return '—';
            }

            return is_array($value) ? json_encode($this->redact($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) : (string) $value;
        };

        $allBranches = app(TenantContext::class)->user()->hasPermission('all-branches');

        return array_values($rows->map(function (AuditLog $row) use ($names, $resolve, $allBranches) {
            $old = $this->redact($row->old_values ?? []);
            $new = $this->redact($row->new_values ?? []);
            if (! $allBranches) {
                unset($old['outstanding'], $new['outstanding']);
            }
            $changes = [];
            $labels = ['max_active_cashiers' => 'Maximum Active Cashiers', 'name' => 'Name', 'active' => 'Status', 'business_unit_id' => 'Business Unit', 'unit_ids' => 'Business Units', 'counter_ids' => 'Counters', 'branch_id' => 'Branch', 'branch_ids' => 'Branch', 'stock_location_id' => 'Counter', 'source_shift' => 'Shift', 'from' => 'From', 'to' => 'Destination'];
            $diff = function (array $before, array $after, string $prefix = '') use (&$diff, &$changes, $labels, $resolve): void {
                foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
                    $field = (string) $key;
                    if (in_array($field, ['id', 'company_id', 'created_at', 'updated_at'], true) || ($before[$key] ?? null) === ($after[$key] ?? null)) {
                        continue;
                    }
                    $left = $before[$key] ?? null;
                    $right = $after[$key] ?? null;
                    $title = $prefix.UiText::get($labels[$field] ?? ucfirst(str_replace('_', ' ', $field)));
                    if (! isset(self::FIELDS[$field]) && is_array($left) && is_array($right) && ! array_is_list($left) && ! array_is_list($right)) {
                        $diff($left, $right, $title.' / ');

                        continue;
                    }
                    $changes[] = ['field' => $title, 'previous' => $resolve($field, $left), 'next' => $resolve($field, $right)];
                }
            };
            $diff($old, $new);

            return ['id' => $row->id, 'time' => $row->created_at?->format('d M Y H:i:s'), 'user' => $row->user_name,
                'action' => $this->label($row), 'code' => $row->action, 'module' => $row->module,
                'reference' => $names[$row->reference_type][$row->reference_id] ?? UiText::get($row->reference_type).' #'.$row->reference_id,
                'summary' => implode(' · ', array_filter([$names['Branch'][$row->branch_id] ?? null, $changes[0]['field'] ?? null])),
                'changes' => $changes, 'previous' => $old, 'next' => $new];
        })->all());
    }
}
