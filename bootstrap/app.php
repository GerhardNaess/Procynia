<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\EnsureHealthToken;
use App\Http\Middleware\EnsureCustomerFrontendAccess;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetCustomerLocale;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'health.token' => EnsureHealthToken::class,
            'customer.frontend' => EnsureCustomerFrontendAccess::class,
        ]);

        $middleware->web(append: [
            SetCustomerLocale::class,
            HandleInertiaRequests::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'stripe/webhook',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
