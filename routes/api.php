<?php

use App\Http\Controllers\Api\V1\MeController;
use Illuminate\Support\Facades\Route;

/*
 * The external Procynia API (docs/external-api-architecture.md, docs/external-api-phase-1-decisions.md).
 *
 * Mounted under /api by bootstrap/app.php. Read-only: every route is a GET, and every route runs
 * the whole stack in this order — log, authenticate the application, resolve the customer grant,
 * module entitlement. ApiRouteContractTest enforces both.
 */
Route::prefix('v1')
    ->name('api.v1.')
    ->middleware(['api.log', 'api.client', 'api.customer', 'customer.module'])
    ->group(function (): void {
        Route::get('me', MeController::class)->name('me');
    });
