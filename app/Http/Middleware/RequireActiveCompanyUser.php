<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireActiveCompanyUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user && $request->routeIs('two-factor.login.store') && $request->session()->has('login.id')) {
            $user = User::find($request->session()->get('login.id'));
        }
        abort_if($user?->company_id && ! $user->active && ! $request->routeIs('logout'), 403);

        return $next($request);
    }
}
