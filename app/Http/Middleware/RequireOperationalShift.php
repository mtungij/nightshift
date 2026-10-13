<?php

namespace App\Http\Middleware;

use App\Domain\OperationalShift;
use App\Support\UiText;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireOperationalShift
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $shifts = app(OperationalShift::class);
        if ($user && $shifts->type($user)) {
            abort_unless($user->active && ($user->company->active ?? false), 403);
            if (! $shifts->active($user) && ! ($request->isMethod('POST') && $request->is('api/nightshift/commands/shift-open'))) {
                $message = UiText::get('Open your shift to start working.');

                return $request->expectsJson() || $request->is('api/*')
                    ? response()->json(['message' => $message, 'shift_required' => true, 'redirect' => route('shift.open', absolute: false)], 409)
                    : redirect()->route('shift.open')->with('status', $message);
            }
        }

        $response = $next($request);
        if ($user && $shifts->type($user)) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }
}
