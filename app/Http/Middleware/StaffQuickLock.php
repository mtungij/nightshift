<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class StaffQuickLock
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $user->operational_pin || $request->is('quick-lock*', 'logout') || $request->routeIs('language.update')) {
            return $next($request);
        }
        $last = $request->session()->get('staff_last_activity', time());
        if (time() - $last >= config('session.quick_lock_minutes') * 60) {
            $request->session()->put('staff_locked', true);
        }
        if ($request->session()->get('staff_locked')) {
            return $request->expectsJson() || $request->is('api/*')
                ? response()->json(['message' => 'Your workspace is locked.', 'quick_lock' => true], 423)
                : redirect()->route('quick-lock');
        }
        $request->session()->put('staff_last_activity', time());

        return $next($request);
    }
}
