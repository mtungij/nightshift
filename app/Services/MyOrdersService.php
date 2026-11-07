<?php

namespace App\Services;

use App\Domain\OperationalShift;
use App\Domain\StaffVisibility;
use App\Domain\TenantContext;
use App\Models\Branch;
use App\Models\Order;
use App\Models\ServiceTable;
use App\Models\Shift;
use App\Models\StockLocation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class MyOrdersService
{
    public const SESSION_KEY = 'my_orders_view';

    public const LIFETIME_SECONDS = 300;

    public function __construct(private TenantContext $context, private StaffVisibility $visibility, private StaffPinService $pins, private AuditService $audit) {}

    /** @return array<string, mixed> */
    public function open(Request $request, int $counterId): array
    {
        $request->session()->forget(self::SESSION_KEY);
        $counter = $this->counter($request, $counterId);
        $view = Str::random(64);
        $request->session()->put(self::SESSION_KEY, ['view_hash' => hash('sha256', $view), 'actor_id' => $request->user()->id, 'company_id' => $request->user()->company_id, 'branch_id' => $counter->branch_id, 'counter_id' => $counter->id, 'expires_at' => now()->addSeconds(self::LIFETIME_SECONDS)->getTimestamp()]);

        return ['view' => $view, 'staff' => $this->eligible($request, $counter)->get()->filter(fn (User $user) => $this->operational($user))->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'role' => $user->role->name])->values()->all()];
    }

    /** @return array<string, mixed> */
    public function verify(Request $request, int $staffId, #[\SensitiveParameter] string $pin): array
    {
        $state = $this->state($request);
        abort_unless(hash_equals($state['view_hash'], hash('sha256', (string) $request->header('X-My-Orders-View'))), 403);
        // A new attempt revokes the previous identity, including when the PIN fails.
        unset($state['token_hash'], $state['staff_id'], $state['pin_fingerprint'], $state['shift_id']);
        $request->session()->put(self::SESSION_KEY, $state);
        $counter = $this->counter($request, $state['counter_id']);
        $staff = $this->staff($request, $counter, $staffId);
        try {
            $verified = $this->pins->verify($staff->id, $pin);
        } catch (ValidationException|HttpException $error) {
            $this->securityEvent('MY_ORDERS_PIN_FAILED', $request, $staff, $counter);
            throw $error;
        }
        $staff = $this->staff($request, $counter, $verified->id);
        abort_unless($staff->operational_pin === $verified->operational_pin, 403);
        $shift = $this->shift($staff, $counter);
        $token = Str::random(64);
        $state += ['token_hash' => hash('sha256', $token), 'staff_id' => $staff->id, 'shift_id' => $shift->id, 'pin_fingerprint' => $this->fingerprint($staff)];
        $state['expires_at'] = now()->addSeconds(self::LIFETIME_SECONDS)->getTimestamp();
        $request->session()->put(self::SESSION_KEY, $state);
        $this->securityEvent('MY_ORDERS_VERIFIED', $request, $staff, $counter);

        return ['token' => $token, 'expires_at' => $state['expires_at'], 'staff' => ['id' => $staff->id, 'name' => $staff->name, 'role' => $staff->role->name]];
    }

    public function close(Request $request): void
    {
        $state = $request->session()->get(self::SESSION_KEY);
        // A delayed close from an old tab must not revoke a newer view.
        $view = $request->header('X-My-Orders-View') ?? $request->input('view', '');
        if (is_array($state) && is_string($view) && hash_equals($state['view_hash'], hash('sha256', $view))) {
            $request->session()->forget(self::SESSION_KEY);
        }
    }

    /** @return array<string, mixed> */
    public function orders(Request $request, string $filter, int $page, ?int $orderId = null): array
    {
        $state = $this->state($request);
        abort_unless(isset($state['token_hash'], $state['staff_id']) && hash_equals($state['token_hash'], hash('sha256', (string) $request->header('X-My-Orders-Token'))), 403, 'Verify your PIN to view My Orders.');
        $counter = $this->counter($request, $state['counter_id']);
        $staff = $this->staff($request, $counter, $state['staff_id']);
        if ($staff->pin_locked_until?->isFuture() || ! hash_equals($state['pin_fingerprint'], $this->fingerprint($staff)) || $this->shift($staff, $counter)->id !== $state['shift_id']) {
            $request->session()->forget(self::SESSION_KEY);
            abort(403, 'Verify your PIN to view My Orders.');
        }

        // Change only Eloquent's read scope. Laravel's authenticated user stays the workstation.
        return $this->context->withUserScope($staff, function () use ($staff, $counter, $filter, $page, $orderId) {
            $query = Order::where('company_id', $staff->company_id)->where('branch_id', $counter->branch_id)->where('stock_location_id', $counter->id);
            if ($staff->role->name === 'Waiter') {
                $query->where('waiter_id', $staff->id);
            } else {
                // Preserve the existing cashier settlement/ownership rules, including paid history.
                $query = $this->visibility->orders($query);
            }
            if ($orderId !== null) {
                $order = (clone $query)->with('waiter')->findOrFail($orderId);

                return ['order' => $this->present($order, $counter)];
            }
            if ($filter === 'unpaid') {
                $query->whereNotNull('issued_at')->where('balance', '>', 0)->whereIn('status', ['ISSUED', 'PARTIALLY_PAID', 'VOID_REQUESTED']);
            } elseif ($filter === 'paid') {
                $query->where('status', 'PAID');
            }
            $orders = $query->with('waiter')->latest('id')->paginate(20, ['*'], 'page', $page);

            return ['orders' => $orders->getCollection()->map(fn (Order $order) => $this->present($order, $counter))->all(), 'page' => $orders->currentPage(), 'last_page' => $orders->lastPage(), 'total' => $orders->total(), 'filter' => $filter];
        });
    }

    private function counter(Request $request, int $id): StockLocation
    {
        $actor = $request->user();
        abort_unless($actor->active && $actor->company_id && $actor->company->active && $actor->hasPermission('create-orders') && $actor->hasPermission('view-orders'), 403);
        $counter = StockLocation::whereKey($id)->where('active', true)->where('type', 'COUNTER')->firstOrFail();
        abort_unless(Branch::whereKey($counter->branch_id)->where('active', true)->exists(), 403);
        $this->visibility->assertOperationalLocation($counter->id);
        if (in_array($actor->role->name, ['Waiter', 'Cashier'], true)) {
            $shift = app(OperationalShift::class)->active($actor);
            abort_unless($shift && $shift->stock_location_id === $counter->id && $actor->canAccessBranch($counter->branch_id), 403);
        }

        return $counter;
    }

    /** @return Builder<User> */
    private function eligible(Request $request, StockLocation $counter): Builder
    {
        $actor = $request->user();
        $query = User::where('company_id', $actor->company_id)->where('active', true)->whereNotNull('operational_pin')->whereHas('role', fn ($q) => $q->whereIn('name', ['Waiter', 'Cashier']))
            ->whereHas('branches', fn ($q) => $q->where('branches.id', $counter->branch_id))
            ->where(function ($q) use ($counter) {
                foreach (['Waiter' => 'WAITER', 'Cashier' => 'CASHIER'] as $role => $type) {
                    $q->orWhere(fn ($q) => $q->whereHas('role', fn ($q) => $q->where('name', $role))->whereIn('id', Shift::where('branch_id', $counter->branch_id)->where('stock_location_id', $counter->id)->where('status', 'OPEN')->where('type', $type)->select('user_id')));
                }
            });
        if (in_array($actor->role->name, ['Waiter', 'Cashier'], true)) {
            $query->whereKey($actor->id);
        }

        return $query->orderBy('name');
    }

    private function operational(User $user): bool
    {
        return $user->hasPermission('view-orders') && ($user->role->name === 'Waiter' ? $user->hasPermission('create-orders') : ($user->hasPermission('issue-drinks') || $user->hasPermission('receive-payments')));
    }

    private function staff(Request $request, StockLocation $counter, int $id): User
    {
        $staff = $this->eligible($request, $counter)->findOrFail($id);
        abort_unless($this->operational($staff), 403);
        $this->shift($staff, $counter);

        return $staff;
    }

    private function shift(User $staff, StockLocation $counter): Shift
    {
        return Shift::where('company_id', $staff->company_id)->where('branch_id', $counter->branch_id)->where('stock_location_id', $counter->id)->where('user_id', $staff->id)->where('status', 'OPEN')->where('type', $staff->role->name === 'Waiter' ? 'WAITER' : 'CASHIER')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function state(Request $request): array
    {
        $state = $request->session()->get(self::SESSION_KEY);
        if (! is_array($state) || ($state['actor_id'] ?? null) !== $request->user()->id || ($state['company_id'] ?? null) !== $request->user()->company_id || ($state['expires_at'] ?? 0) <= now()->timestamp) {
            $request->session()->forget(self::SESSION_KEY);
            abort(403, 'Verify your PIN to view My Orders.');
        }

        return $state;
    }

    private function fingerprint(User $staff): string
    {
        return hash_hmac('sha256', $staff->operational_pin ?? '', config('app.key'));
    }

    private function securityEvent(string $event, Request $request, User $staff, StockLocation $counter): void
    {
        $this->audit->record($event, 'Security', 'User', $staff->id, [], ['selected_staff_id' => $staff->id, 'terminal_user_id' => $request->user()->id, 'stock_location_id' => $counter->id], $counter->branch_id);
    }

    /** @return array<string, mixed> */
    private function present(Order $order, StockLocation $counter): array
    {
        return ['id' => $order->id, 'invoice' => $order->invoice_number, 'created_at' => $order->created_at?->toIso8601String(), 'table' => ServiceTable::whereKey($order->service_table_id)->value('name'), 'counter' => $counter->name, 'waiter' => $order->waiter->name, 'order_note' => $order->order_note, 'total' => $order->total, 'amount_paid' => $order->amount_paid, 'balance' => $order->balance, 'status' => $order->status];
    }
}
