<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RedactPin
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            return $next($request);
        } finally {
            // Drop transient credentials before the exception handler or request
            // logging can inspect a completed or rejected request.
            $request->request->remove('pin');
            $request->query->remove('pin');
            $request->json()->remove('pin');
        }
    }
}
