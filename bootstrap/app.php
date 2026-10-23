<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\Api\AuthenticateApiClient;
use App\Http\Middleware\Api\ForceJsonResponse;
use App\Http\Middleware\Api\LogApiRequest;
use App\Http\Middleware\Api\ResolveApiCustomerGrant;
use App\Http\Middleware\EnsureCustomerFrontendAccess;
use App\Http\Middleware\EnsureHealthToken;
use App\Http\Middleware\EnsureModuleIsEnabled;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetCustomerLocale;
use App\Services\Api\ApiErrorResponder;
use App\Services\Security\ApiActorChannel;
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
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Trusted proxies are configured in AppServiceProvider::boot() from
        // config('procynia.security.trusted_proxies'), because configuration is not loaded yet at
        // this point. The previous trustProxies(at: '*') is deliberately gone: it made every peer a
        // trusted proxy, so a browser-supplied X-Forwarded-For became the client IP.
        // Security headers (F-05) are appended globally rather than to the web group: they must
        // also cover Filament's own middleware stack and any non-web response.
        $middleware->append(AddSecurityHeaders::class);

        $middleware->alias([
            'health.token' => EnsureHealthToken::class,
            'customer.frontend' => EnsureCustomerFrontendAccess::class,
            'customer.module' => EnsureModuleIsEnabled::class,
            'api.log' => LogApiRequest::class,
            'api.client' => AuthenticateApiClient::class,
            'api.customer' => ResolveApiCustomerGrant::class,
        ]);

        $middleware->api(prepend: [
            ForceJsonResponse::class,
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
        // The external API answers every failure in its own format (A10). The web application is
        // untouched: the callback declines anything outside /api.
        $exceptions->render(function (Throwable $e, Request $request) {
            return ApiActorChannel::covers($request)
                ? app(ApiErrorResponder::class)->render($e, $request)
                : null;
        });
    })->create();
