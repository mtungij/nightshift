<?php

namespace App\Http\Controllers;

use App\Domain\OperationalShift;
use App\Models\Branch;
use App\Models\StockLocation;
use App\Services\ShiftService;
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

        return view('open-shift', ['cashier' => $shifts->type($user) === 'CASHIER',
            'counters' => StockLocation::where('active', true)->where('type', 'COUNTER')
                ->whereIn('branch_id', Branch::where('active', true)->select('id'))->orderBy('name')->get(),
            'branches' => Branch::where('active', true)->pluck('name', 'id'),
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
        $data = $request->validate(['stock_location_id' => ['required', 'integer', 'min:1'],
            'opening_cash' => $shifts->type($user) === 'CASHIER' ? ['required', 'numeric', 'min:0', 'decimal:0,2', 'max:99999999999999.99'] : ['prohibited'],
            'user_id' => ['prohibited'], 'type' => ['prohibited'], 'company_id' => ['prohibited'],
        ]);
        $service->open(['user_id' => $user->id, 'type' => $shifts->type($user), 'stock_location_id' => $data['stock_location_id'], 'opening_cash' => $data['opening_cash'] ?? '0']);

        return redirect($shifts->destination($user));
    }
}
