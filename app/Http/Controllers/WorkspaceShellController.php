<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Shift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Navigation metadata only; operational data and commands keep their existing shift guards. */
final class WorkspaceShellController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->active && $actor->company_id && $actor->company->active && ! $actor->isPosTerminal(), 403);
        $page = $request->validate(['page' => ['required', 'in:business-units,treasury,cash-handovers,audit,whatsapp']])['page'];
        if ($page === 'cash-handovers') {
            abort_unless(($actor->hasPermission('issue-drinks') && $actor->hasPermission('close-shifts')) || $actor->hasPermission('view-treasury') || $actor->hasPermission('receive-treasury') || $actor->hasPermission('review-cash-handovers'), 403);
        } else {
            Gate::authorize(['business-units' => 'manage-business-units', 'treasury' => 'view-treasury', 'audit' => 'view-audit', 'whatsapp' => 'whatsapp.view_settings'][$page]);
        }
        $branches = Branch::get(['id', 'name']);
        $shifts = Shift::where('user_id', $actor->id)->where('status', 'OPEN')->get(['id', 'branch_id', 'opened_at']);
        $current = $shifts->first();

        return response()->json(['company' => $actor->company->name, 'role' => $actor->role?->name, 'permissions' => $actor->role?->permissions()->pluck('name')->all() ?? [],
            'user' => ['id' => $actor->id, 'name' => $actor->name, 'email' => $actor->email], 'branch' => ($current ? $branches->firstWhere('id', $current->branch_id)?->name : $branches->first()?->name) ?? '', 'branches' => $branches,
            'currency' => $actor->company->currency, 'timezone' => $actor->company->timezone, 'date' => now()->toDateString(),
            'shift' => ['active' => $current !== null, 'id' => $current?->id], 'shifts' => $shifts, 'products' => [], 'waiters' => [], 'orders' => [], 'approvals' => [], 'payments' => [], 'categories' => [], 'locations' => [], 'tables' => [],
        ])->header('Cache-Control', 'private, no-store');
    }
}
