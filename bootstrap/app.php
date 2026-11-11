<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->throttleApi();

        $middleware->alias([
            'api.access' => \App\Http\Middleware\EnsureApiAccess::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'stripe/webhook',
            'swish/callback',
            'docsign/callback/*',
            'discord/interactions',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
