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
        $middleware->web(append: [
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\SanitizeInput::class,
            \App\Http\Middleware\SetLocale::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Exclude interaction routes from CSRF verification (likes/views/reports are rate-limited by IP)
        $middleware->validateCsrfTokens(except: [
            'interactions/*',
            'api/*',
        ]);

        // Register middleware aliases
        $middleware->alias([
            'account.old_enough' => \App\Http\Middleware\EnsureAccountIsOldEnough::class,
            'throttle.ip' => \App\Http\Middleware\ThrottleByIp::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
