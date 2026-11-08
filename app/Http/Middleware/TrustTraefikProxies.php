<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

final class TrustTraefikProxies extends TrustProxies
{
    protected $headers = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO;

    /** @return array<int, string> */
    protected function proxies(): array
    {
        return config('proxy.trusted', ['127.0.0.1', '::1']);
    }

    public function handle(Request $request, Closure $next)
    {
        return parent::handle($request, function (Request $request) use ($next) {
            // APP_URL remains authoritative for an HTTPS-only deployment even on its HTTP backplane.
            URL::forceScheme(parse_url(config('app.url'), PHP_URL_SCHEME) === 'https' ? 'https' : null);

            return $next($request);
        });
    }
}
