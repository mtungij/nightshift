<?php

namespace App\Http\Controllers;

use App\Domain\OperationalShift;
use App\Models\Branch;
use App\Models\BusinessUnit;
use App\Models\CashHandover;
use App\Models\StockLocation;
use App\Services\CashHandoverService;
use App\Services\ShiftService;
use App\Services\UnitOperationalService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class OpenShiftController extends Controller
{
    public function show(Request $request, OperationalShift $shifts): View|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->active && ($user->company->active ?? false), 403);
        if (! $shifts->type($user) || $shifts->active($user)) {
            return redirect($shifts->destination($user));
        }
        Gate::authorize('open-shifts');

        $units = BusinessUnit::where('active', true)->orderBy('name')->get()->filter(fn ($unit) => $user->canAccessBusinessUnit($unit->id));
        foreach ($units as $unit) {
            app(UnitOperationalService::class)->ensureDefault($unit);
        }

        return view('open-shift', ['units' => $units, 'unitOperations' => app(UnitOperationalService::class), 'cashier' => $shifts->type($user) === 'CASHIER',
            'counters' => StockLocation::where('active', true)->where('type', 'COUNTER')
                ->whereIn('branch_id', Branch::where('active', true)->select('id'))->orderBy('name')->get(),
            'branches' => Branch::where('active', true)->pluck('name', 'id'),
            'handovers' => CashHandover::where('to_user_id', $user->id)->where('destination', 'NEXT_CASHIER')->get()->filter(fn ($h) => in_array(app(CashHandoverService::class)->latest($h)->status, ['PENDING', 'RECEIVED', 'APPROVED', 'DISPUTED'], true))->map(fn ($h) => app(CashHandoverService::class)->present($h))->values(),
        ]);
    }

    public function store(Request $request, OperationalShift $shifts, ShiftService $service): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->active && ($user->company->active ?? false) && $shifts->type($user), 403);
        // Safe retry / another tab: an existing shift is reused, never overlapped.
        if ($shifts->active($user)) {
            return redirect($shifts->destination($user));
        }
        $data = $request->validate(['business_unit_id' => ['required_without:stock_location_id', 'integer', 'min:1'], 'stock_location_id' => ['nullable', 'required_without:business_unit_id', 'integer', 'min:1'],
            'opening_cash' => ['prohibited'], 'handover_id' => ['nullable', 'integer', 'min:1'],
            'user_id' => ['prohibited'], 'type' => ['prohibited'], 'company_id' => ['prohibited'],
        ]);
        $service->open(['user_id' => $user->id, 'type' => $shifts->type($user), 'business_unit_id' => $data['business_unit_id'] ?? null, 'stock_location_id' => $data['stock_location_id'] ?? null, 'handover_id' => $data['handover_id'] ?? null]);

        return redirect($shifts->destination($user));
    }
}
