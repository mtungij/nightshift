<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Models\StockLocation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RestrictPosTerminal
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user?->isPosTerminal() || $request->is('logout')) {
            return $next($request);
        }

        abort_unless($user->active && $user->company->active, 403);
        // Fail closed for missing, inactive, or foreign assignments, including old sessions.
        $location = StockLocation::whereKey($user->terminal_stock_location_id)->where('type', 'COUNTER')->where('active', true)->first();
        abort_unless($location && Branch::whereKey($location->branch_id)->where('active', true)->exists(), 403);
        $read = $request->isMethod('GET') && $request->is('/', 'dashboard', 'livewire/livewire.js', 'livewire/livewire.min.js', 'api/nightshift/bootstrap', 'api/nightshift/my-orders', 'api/nightshift/my-orders/staff', 'api/nightshift/my-orders/*', 'api/nightshift/products/*/image', 'api/nightshift/issue-tickets/*', 'api/nightshift/sales-tickets/*');
        $write = $request->isMethod('POST') && $request->is('api/nightshift/my-orders/verify', 'api/nightshift/my-orders/close', 'api/nightshift/commands/order-send', 'api/nightshift/commands/ticket-reprint', 'api/nightshift/sales-tickets/*/dispatch', 'settings/language');
        $theme = $request->isMethod('PUT') && $request->is('settings/theme');
        abort_unless($read || $write || $theme, 403);
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
