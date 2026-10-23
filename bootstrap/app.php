<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Behind a TLS-terminating proxy, set TRUSTED_PROXIES (comma separated)
        // so $request->ip() is the real client and not the proxy. Left empty
        // by default: trusting arbitrary X-Forwarded-For headers would let
        // clients spoof their IP and bypass the API rate limiter.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(
                at: array_map('trim', explode(',', (string) $proxies)),
                headers: Request::HEADER_X_FORWARDED_FOR
                    | Request::HEADER_X_FORWARDED_HOST
                    | Request::HEADER_X_FORWARDED_PORT
                    | Request::HEADER_X_FORWARDED_PROTO,
            );
        }

        $middleware->append(\App\Http\Middleware\ForceJsonResponse::class)
            ->statefulApi()
            ->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
