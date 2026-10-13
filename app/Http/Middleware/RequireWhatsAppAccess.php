<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class RequireWhatsAppAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->active && $request->user()->company_id && $request->user()->company->active, 403);
        Gate::authorize('whatsapp.view_settings');

        return $next($request);
    }
}
