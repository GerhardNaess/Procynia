<?php

use App\Http\Controllers\Api\V1\DeviationController;
use App\Http\Controllers\Api\V1\KpiController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\ObjectiveController;
use App\Http\Controllers\Api\V1\RiskController;
use App\Http\Controllers\Api\V1\RiskTreatmentActionController;
use Illuminate\Support\Facades\Route;

/*
 * The external Procynia API (docs/external-api-architecture.md, docs/external-api-phase-1-decisions.md).
 *
 * Mounted under /api by bootstrap/app.php. Every route runs the whole stack in this order — log,
 * authenticate the application, resolve the customer grant, module entitlement. Routes read (GET),
 * except the named write routes, which also require the write app role (api.write) and an operation
 * from App\Support\ApiOperationCatalog that the domain checks. ApiRouteContractTest enforces this.
 */
Route::prefix('v1')
    ->name('api.v1.')
    ->middleware(['api.log', 'api.client', 'api.customer', 'customer.module'])
    ->group(function (): void {
        Route::get('me', MeController::class)->name('me');
        Route::get('risks', [RiskController::class, 'index'])->name('risks.index');
        Route::get('risks/{riskId}', [RiskController::class, 'show'])->whereNumber('riskId')->name('risks.show');
        // The one write route: needs the write app role on top, and the operation allowed for the grant.
        Route::post('risks/{riskId}/treatment-actions', [RiskTreatmentActionController::class, 'store'])
            ->whereNumber('riskId')
            ->middleware('api.write')
            ->name('risks.treatment-actions.store');
        Route::get('deviations', [DeviationController::class, 'index'])->name('deviations.index');
        Route::get('objectives', [ObjectiveController::class, 'index'])->name('objectives.index');
        Route::get('kpis', [KpiController::class, 'index'])->name('kpis.index');
    });
