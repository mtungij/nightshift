<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $companyDefault = match ($user?->company?->default_language) {
            'Swahili', 'Kiswahili', 'sw' => 'sw',
            default => 'en',
        };
        // A signed-in account never inherits another account's browser choice.
        $locale = $user ? ($user->language_preference ?? $companyDefault) : $request->session()->get('locale', 'en');
        app()->setLocale(in_array($locale, ['en', 'sw'], true) ? $locale : 'en');

        return $next($request);
    }
}
