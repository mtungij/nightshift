<?php

use App\Http\Middleware\RedactPin;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\StaffQuickLock;
use App\Support\UiText;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trimStrings(except: ['pin']);
        $middleware->appendToGroup('web', [SetLocale::class, RedactPin::class, StaffQuickLock::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response) {
            if ($response instanceof JsonResponse && $response->getStatusCode() >= 400) {
                $data = $response->getData(true);
                if (is_array($data)) {
                    if (isset($data['message']) && is_string($data['message'])) {
                        $data['message'] = UiText::get($data['message']);
                    }
                    if (isset($data['errors']) && is_array($data['errors'])) {
                        foreach ($data['errors'] as $field => $messages) {
                            if (is_array($messages)) {
                                $data['errors'][$field] = array_map(fn ($message) => is_string($message) ? UiText::get($message) : $message, $messages);
                            }
                        }
                    }
                    $response->setData($data);
                }
            }

            return $response;
        });
        $exceptions->dontFlash(['pin', 'operational_pin']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
