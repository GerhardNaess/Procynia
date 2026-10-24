<?php

use App\Http\Controllers\Api\V1\AttentionController;
use App\Http\Controllers\Api\V1\BusinessAreaController;
use App\Http\Controllers\Api\V1\Compliance\ComplianceAuditController;
use App\Http\Controllers\Api\V1\Compliance\ComplianceFrameworkController;
use App\Http\Controllers\Api\V1\Compliance\ComplianceRequirementController;
use App\Http\Controllers\Api\V1\Compliance\ComplianceStatusController;
use App\Http\Controllers\Api\V1\DeviationController;
use App\Http\Controllers\Api\V1\ImprovementCaseRegistrationController;
use App\Http\Controllers\Api\V1\ImprovementController;
use App\Http\Controllers\Api\V1\KpiController;
use App\Http\Controllers\Api\V1\ManagementReviewController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\ObjectiveController;
use App\Http\Controllers\Api\V1\QualityController;
use App\Http\Controllers\Api\V1\RiskController;
use App\Http\Controllers\Api\V1\RiskTreatmentActionController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Models\ImprovementCase;
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
        // Across the served modules; each module is included only where the identity may read it.
        Route::get('attention', AttentionController::class)->name('attention');
        Route::get('business-areas', BusinessAreaController::class)->name('business-areas');
        Route::get('risks', [RiskController::class, 'index'])->name('risks.index');
        Route::get('risks/{riskId}', [RiskController::class, 'show'])->whereNumber('riskId')->name('risks.show');
        // Write routes need the write app role on top, and the operation allowed for the grant.
        Route::post('risks/{riskId}/treatment-actions', [RiskTreatmentActionController::class, 'store'])
            ->whereNumber('riskId')
            ->middleware('api.write')
            ->name('risks.treatment-actions.store');
        Route::get('deviations', [DeviationController::class, 'index'])->name('deviations.index');
        Route::get('deviations/{caseId}', [DeviationController::class, 'show'])->whereNumber('caseId')->name('deviations.show');
        Route::get('improvements', [ImprovementController::class, 'index'])->name('improvements.index');
        Route::get('improvements/{caseId}', [ImprovementController::class, 'show'])->whereNumber('caseId')->name('improvements.show');
        // Registering a case or a tiltak in Avvik og forbedringer; the type is the path's.
        foreach (['deviations' => ImprovementCase::TYPE_DEVIATION, 'improvements' => ImprovementCase::TYPE_IMPROVEMENT] as $path => $type) {
            Route::post($path, [ImprovementCaseRegistrationController::class, 'store'])
                ->defaults('type', $type)
                ->middleware('api.write')
                ->name("{$path}.store");
            Route::post("{$path}/{caseId}/actions", [ImprovementCaseRegistrationController::class, 'storeAction'])
                ->whereNumber('caseId')
                ->defaults('type', $type)
                ->middleware('api.write')
                ->name("{$path}.actions.store");
        }
        Route::get('objectives', [ObjectiveController::class, 'index'])->name('objectives.index');
        Route::get('objectives/{objectiveId}', [ObjectiveController::class, 'show'])->whereNumber('objectiveId')->name('objectives.show');
        Route::get('kpis', [KpiController::class, 'index'])->name('kpis.index');
        Route::get('kpis/{kpiId}', [KpiController::class, 'show'])->whereNumber('kpiId')->name('kpis.show');
        Route::get('kpis/{kpiId}/measurements', [KpiController::class, 'measurements'])->whereNumber('kpiId')->name('kpis.measurements');
        Route::get('management-reviews', [ManagementReviewController::class, 'index'])->name('management-reviews.index');
        Route::get('management-reviews/{reviewId}', [ManagementReviewController::class, 'show'])->whereNumber('reviewId')->name('management-reviews.show');
        Route::get('compliance/requirements', [ComplianceRequirementController::class, 'index'])->name('compliance.requirements.index');
        Route::get('compliance/requirements/{requirementId}', [ComplianceRequirementController::class, 'show'])->whereNumber('requirementId')->name('compliance.requirements.show');
        Route::get('compliance/status', ComplianceStatusController::class)->name('compliance.status');
        Route::get('compliance/audits', [ComplianceAuditController::class, 'index'])->name('compliance.audits.index');
        Route::get('compliance/audits/{auditId}', [ComplianceAuditController::class, 'show'])->whereNumber('auditId')->name('compliance.audits.show');
        Route::get('compliance/frameworks', [ComplianceFrameworkController::class, 'index'])->name('compliance.frameworks.index');
        Route::get('compliance/frameworks/{frameworkId}', [ComplianceFrameworkController::class, 'show'])->whereNumber('frameworkId')->name('compliance.frameworks.show');
        Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
        Route::get('suppliers/control-requirements', [SupplierController::class, 'controlRequirements'])->name('suppliers.control-requirements');
        Route::get('suppliers/{supplierId}', [SupplierController::class, 'show'])->whereNumber('supplierId')->name('suppliers.show');
        Route::get('quality/items', [QualityController::class, 'items'])->name('quality.items');
        Route::get('quality/items/{itemId}', [QualityController::class, 'show'])->whereNumber('itemId')->name('quality.items.show');
        Route::get('quality/controls', [QualityController::class, 'controls'])->name('quality.controls');
    });
