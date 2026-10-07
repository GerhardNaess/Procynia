<?php

use App\Http\Controllers\Admin\OperationalRunbookAttachmentDownloadController;
use App\Http\Controllers\App\AiController;
use App\Http\Controllers\App\BillingController;
use App\Http\Controllers\App\BusinessAreaController;
use App\Http\Controllers\App\ComplianceAuditController;
use App\Http\Controllers\App\ComplianceRequirementController;
use App\Http\Controllers\App\ComplianceSourceController;
use App\Http\Controllers\App\CustomerEnvironmentController;
use App\Http\Controllers\App\CustomerRoleController;
use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\DepartmentController;
use App\Http\Controllers\App\GoNoGoAssessmentController;
use App\Http\Controllers\App\GoNoGoTemplateController;
use App\Http\Controllers\App\GovernanceController;
use App\Http\Controllers\App\HomeController;
use App\Http\Controllers\App\ImprovementActionController;
use App\Http\Controllers\App\ImprovementCaseContextController;
use App\Http\Controllers\App\ImprovementCaseController;
use App\Http\Controllers\App\InfoCenterController;
use App\Http\Controllers\App\KpiContextController;
use App\Http\Controllers\App\KpiController;
use App\Http\Controllers\App\KpiMeasurementController;
use App\Http\Controllers\App\NoticeController;
use App\Http\Controllers\App\NoticeDocumentDownloadController;
use App\Http\Controllers\App\ObjectiveController;
use App\Http\Controllers\App\QualityController;
use App\Http\Controllers\App\RiskAcceptanceController;
use App\Http\Controllers\App\RiskAssessmentController;
use App\Http\Controllers\App\RiskContextController;
use App\Http\Controllers\App\RiskControlController;
use App\Http\Controllers\App\RiskController;
use App\Http\Controllers\App\RiskTreatmentActionController;
use App\Http\Controllers\App\RiskWikiKnowledgeController;
use App\Http\Controllers\App\SupplierController;
use App\Http\Controllers\App\SupplierManagementController;
use App\Http\Controllers\App\UserController;
use App\Http\Controllers\App\UserNotificationController;
use App\Http\Controllers\App\WatchProfileController;
use App\Http\Controllers\App\WikiAskController;
use App\Http\Controllers\App\WikiClaimController;
use App\Http\Controllers\App\WikiController;
use App\Http\Controllers\App\WikiDocumentOwnerApprovalController;
use App\Http\Controllers\App\WikiGraphController;
use App\Http\Controllers\App\WikiGraphDataController;
use App\Http\Controllers\App\WikiGraphFocusController;
use App\Http\Controllers\App\WikiSourceController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EntraAuthController;
use App\Http\Controllers\Health\DocumentHealthController;
use App\Http\Controllers\Health\IntegrationHealthController;
use App\Http\Controllers\Ops\QueueHeartbeatHealthController;
use App\Http\Controllers\Ops\QueueSchedulerHealthController;
use App\Http\Controllers\PublicRegistrationController;
use App\Http\Controllers\StripeWebhookController;
use App\Models\Language;
use App\Models\Nationality;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

Route::post('/stripe/webhook', [StripeWebhookController::class, 'handleWebhook'])->name('cashier.webhook');

Route::prefix('health')
    ->middleware('health.token')
    ->name('health.')
    ->group(function (): void {
        Route::get('/integrations/doffin/import-freshness', [IntegrationHealthController::class, 'doffinImportFreshness'])
            ->name('integrations.doffin.import-freshness');
        Route::get('/integrations/openai', [IntegrationHealthController::class, 'openAiConnectivity'])
            ->name('integrations.openai');
        Route::get('/integrations/stripe/webhooks', [IntegrationHealthController::class, 'stripeWebhooks'])
            ->name('integrations.stripe.webhooks');
        Route::get('/documents/parsing', [DocumentHealthController::class, 'documentParsing'])
            ->name('documents.parsing');
    });

Route::prefix('ops')->middleware('health.token')->name('ops.')->group(function (): void {
    Route::get('/health/queues/{queue}', [QueueHeartbeatHealthController::class, 'check'])
        ->name('health.queues.check');
    Route::get('/health/queue-scheduler', [QueueSchedulerHealthController::class, 'check'])
        ->name('health.queue-scheduler');
});

Route::get('/', function () {
    $user = auth()->user();

    if (! $user) {
        return Inertia::render('Public/Home');
    }

    return method_exists($user, 'canAccessCustomerFrontend') && $user->canAccessCustomerFrontend()
        ? redirect()->route('app.notices.index', ['mode' => 'saved'])
        : redirect()->route('filament.admin.pages.dashboard');
});

Route::name('public.')->group(function (): void {
    Route::get('/funksjoner', fn () => Inertia::render('Public/Features'))->name('features');
    Route::get('/priser', fn () => Inertia::render('Public/Pricing'))->name('pricing');
    Route::get('/sikkerhet', fn () => Inertia::render('Public/Security'))->name('security');
    Route::get('/kontakt', fn () => Inertia::render('Public/Contact'))->name('contact');
    Route::get('/betingelser', fn () => Inertia::render('Public/Terms'))->name('terms');
    Route::get('/personvern', fn () => Inertia::render('Public/Privacy'))->name('privacy');
    Route::get('/faq', fn () => Inertia::render('Public/Faq'))->name('faq');
    Route::get('/registrer', function (): Response {
        $locale = app()->getLocale();

        $languageOptions = Language::query()
            ->orderBy('name_no')
            ->get()
            ->map(static function (Language $language) use ($locale): array {
                $label = $locale === 'en'
                    ? ($language->name_en ?: $language->name_no ?: $language->code)
                    : ($language->name_no ?: $language->name_en ?: $language->code);

                return [
                    'id' => $language->id,
                    'label' => $label,
                    'code' => $language->code,
                ];
            })
            ->values()
            ->all();

        $nationalityOptions = Nationality::query()
            ->orderBy('name_no')
            ->get()
            ->map(static function (Nationality $nationality) use ($locale): array {
                $label = $locale === 'en'
                    ? ($nationality->name_en ?: $nationality->name_no ?: $nationality->code)
                    : ($nationality->name_no ?: $nationality->name_en ?: $nationality->code);

                return [
                    'id' => $nationality->id,
                    'label' => $label,
                    'code' => $nationality->code,
                ];
            })
            ->values()
            ->all();

        return Inertia::render('Public/Register', [
            'publicRegistration' => [
                'languages' => $languageOptions,
                'nationalities' => $nationalityOptions,
            ],
        ]);
    })->name('register');
    Route::post('/registrer', [PublicRegistrationController::class, 'store'])
        ->middleware(['guest', 'throttle:public-registration'])
        ->name('register.store');
});

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');

    // Microsoft Entra ID sign-in. Both routes 404 when Entra is disabled, so a deployment without
    // SSO does not expose an OIDC surface at all.
    //
    // Rate limited on the same principle as F-01: starting a flow and returning from one are both
    // unauthenticated endpoints that do real work (a token exchange, a JWKS fetch). This is not the
    // password throttle — there is no credential here to guess — it is abuse protection.
    Route::get('/login/entra', [EntraAuthController::class, 'redirect'])
        ->middleware('throttle:entra-auth')
        ->name('login.entra');
    Route::get('/login/entra/callback', [EntraAuthController::class, 'callback'])
        ->middleware('throttle:entra-auth')
        ->name('login.entra.callback');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/admin/operational-runbooks/attachments/{attachment}/download', [OperationalRunbookAttachmentDownloadController::class, 'download'])
        ->name('admin.operational-runbook-attachments.download');
});

Route::prefix('app')
    // customer.module gates the routes listed in config/procynia_modules.php -> route_modules
    // against the customer's package entitlements. It is applied to the whole group rather than
    // to each route, because the map it reads is keyed by route name.
    ->middleware(['auth', 'customer.frontend', 'customer.module'])
    ->name('app.')
    ->group(function (): void {
        Route::redirect('/', '/app/notices?mode=saved');
        // Hjem: Procynia across its modules. Keeps the /app/dashboard path the rail and the logo
        // have always pointed at; only what the path renders has changed.
        Route::get('/dashboard', HomeController::class)->name('dashboard');

        // The bid cockpit, unchanged, now under the module it belongs to. It was the home page
        // until Hjem became cross-module, which is why its path moved rather than its content.
        Route::get('/bid-status', [DashboardController::class, 'index'])->name('bid-status');

        // Styring: the landing page of the arbeidsområde that groups Kvalitet, Risiko, Mål og KPI and
        // Avvik og forbedringer. Deliberately not in route_modules — it belongs to no module, and the
        // controller answers from the four modules' own entitlement and permission instead.
        Route::get('/governance', GovernanceController::class)->name('governance');

        // Kvalitet. Every route here is named under `app.quality.`, which config/procynia_modules.php
        // maps to the `quality` module — so the write actions are entitlement-gated by the group's
        // customer.module middleware exactly as the page itself is.
        Route::prefix('/quality')->name('quality.')->group(function (): void {
            Route::get('/', [QualityController::class, 'index'])->name('index');

            // Quality items are the domain's own objects, so they are bound by their own id — not
            // by a Wiki page, which is what the retired classification model did.
            Route::post('/items', [QualityController::class, 'storeItem'])->name('items.store');
            Route::get('/items/{item}', [QualityController::class, 'show'])->name('items.show');
            Route::patch('/items/{item}', [QualityController::class, 'updateItem'])->name('items.update');
            Route::delete('/items/{item}', [QualityController::class, 'destroyItem'])->name('items.destroy');

            // Steps, input/output, checklist lines and control fields all travel as a set — see
            // QualityItemService for why structure is written wholesale rather than row by row.
            Route::put('/items/{item}/structure', [QualityController::class, 'updateStructure'])
                ->name('items.structure.update');

            // Prosessflyt. Only a process has one — the controller refuses the rest through
            // QualityProcessBlueprintService — and the blueprint is the source of truth: the
            // swimlane is drawn from it client-side and never stored.
            //
            // There is deliberately no "generate" route. A deterministic generator used to seed a
            // flow — from the process's own steps, or from a worked ITIL example when it had none —
            // and it wrote straight over whatever the process already had. A flow a person
            // described and adopted is now only ever replaced by a person: by adopting another
            // proposal, or by saving the editor. See QualityProcessBlueprintService::store().

            // Describing the process in plain language, and adopting what comes back. Two routes
            // rather than one because they are two decisions: interpreting writes nothing, and
            // adopting is the user saying the proposal — as they corrected it — is the flow. Only
            // the second touches the database, and only it may record `source = ai`.
            Route::post('/items/{item}/blueprint/interpret', [QualityController::class, 'interpretFlow'])
                ->name('items.blueprint.interpret');
            Route::post('/items/{item}/blueprint/adopt', [QualityController::class, 'adoptFlowProposal'])
                ->name('items.blueprint.adopt');

            // Asking for a change to the flow that exists. Writes nothing, like interpret: what comes
            // back is a list of proposed changes the user reads before anything is accepted.
            Route::post('/items/{item}/blueprint/changes/propose', [QualityController::class, 'proposeFlowChange'])
                ->name('items.blueprint.changes.propose');
            // Accepting one, whole. Re-applies the operations against the version they were made for
            // and stores an ordinary working version — never a revision.
            Route::post('/items/{item}/blueprint/changes/accept', [QualityController::class, 'acceptFlowChange'])
                ->name('items.blueprint.changes.accept');

            // Turning down one of the suggestions beside a proposal. A third decision, and the only
            // one that is remembered: it writes no flow, and it exists so the same note is not put
            // in front of the user every time they regenerate.
            Route::post('/items/{item}/blueprint/clarifications/dismiss', [QualityController::class, 'dismissFlowClarification'])
                ->name('items.blueprint.clarifications.dismiss');

            // Answering one instead. Separate from interpret because the description is revised
            // before it is read — the answer is woven into the text rather than appended to it, and
            // the question itself is never written down. Still writes nothing: what comes back is a
            // proposal carrying the revised description.
            Route::post('/items/{item}/blueprint/clarifications/answer', [QualityController::class, 'answerFlowClarification'])
                ->name('items.blueprint.clarifications.answer');
            Route::put('/items/{item}/blueprint', [QualityController::class, 'updateBlueprint'])
                ->name('items.blueprint.update');
            Route::post('/items/{item}/blueprint/approve', [QualityController::class, 'approveBlueprint'])
                ->name('items.blueprint.approve');

            // Removing the flow alone. A separate decision from deleting the process, which lives
            // on the Kvalitet list: this one says the flow is wrong and should be described again,
            // and leaves the process, its documents and the Wiki knowledge its activities produced
            // exactly where they were.
            Route::delete('/items/{item}/blueprint', [QualityController::class, 'destroyBlueprint'])
                ->name('items.blueprint.destroy');

            // An activity as a SOURCE of knowledge. Two routes for the same reason interpreting and
            // adopting a flow are two: drafting writes nothing, and creating is the user saying the
            // article — as they corrected it — is what should go into Wiki. Only the second touches
            // the database, and what it creates is an ordinary Enterprise Wiki page in draft.
            Route::post('/items/{item}/activities/article-draft', [QualityController::class, 'draftActivityArticle'])
                ->name('items.activities.article-draft');
            Route::post('/items/{item}/activities/articles', [QualityController::class, 'storeActivityArticle'])
                ->name('items.activities.articles.store');

            // Controls on an activity. The control is an ordinary `control` quality item; the flow
            // is not written. Removing takes it off the activity and leaves it in the register.
            Route::post('/items/{item}/activities/controls', [QualityController::class, 'storeActivityControl'])
                ->name('items.activities.controls.store');
            Route::delete('/activity-controls/{control}', [QualityController::class, 'destroyActivityControl'])
                ->name('activity-controls.destroy');

            // The seam to Wiki. Attaching a page changes nothing about the page.
            Route::post('/items/{item}/wiki-links', [QualityController::class, 'storeWikiLink'])
                ->name('items.wiki-links.store');
            Route::delete('/wiki-links/{link}', [QualityController::class, 'destroyWikiLink'])
                ->name('wiki-links.destroy');

            // The seam to the document store — a separate thing from the Wiki seam above. These
            // reach files the document consists of, uses or leaves behind; the store itself is the
            // existing enterprise_wiki_documents one, and removing a link never removes a file.
            Route::post('/items/{item}/documents', [QualityController::class, 'storeDocument'])
                ->name('items.documents.store');
            Route::post('/items/{item}/document-links', [QualityController::class, 'storeDocumentLink'])
                ->name('items.document-links.store');
            Route::delete('/document-links/{link}', [QualityController::class, 'destroyDocumentLink'])
                ->name('document-links.destroy');

            // Evidence that a control is met: a name, a description and optionally a file the store
            // already has. Written to the same seam as above, so removal is document-links.destroy.
            Route::post('/items/{item}/evidence', [QualityController::class, 'storeControlEvidence'])
                ->name('items.evidence.store');

            // Verktøy — documents a control is carried out with. The file is in the same archive as
            // above; a tool is its name and purpose, and a control's use of it is a document-link
            // row in the `tool` capacity, so removing one is document-links.destroy.
            Route::post('/tools', [QualityController::class, 'storeTool'])->name('tools.store');
            Route::get('/tools/{tool}/file', [QualityController::class, 'toolFile'])->name('tools.file');
            Route::post('/items/{item}/tools', [QualityController::class, 'storeControlTool'])
                ->name('items.tools.store');

            Route::post('/relations', [QualityController::class, 'storeRelation'])->name('relations.store');
            Route::delete('/relations/{relation}', [QualityController::class, 'destroyRelation'])->name('relations.destroy');
        });
        // Risiko. Named under `app.risk.`, which config/procynia_modules.php maps to the `risk`
        // module. Risks are addressed by a plain id and resolved through RiskAccessService, never
        // by implicit model binding: a risk outside the user's fagområder must be a 404
        // exactly like an id that does not exist.
        Route::prefix('/risk')->name('risk.')->group(function (): void {
            Route::get('/', [RiskController::class, 'index'])->name('index');
            Route::post('/risks', [RiskController::class, 'store'])->name('store');
            Route::get('/risks/{riskId}', [RiskController::class, 'show'])->whereNumber('riskId')->name('show');
            Route::patch('/risks/{riskId}', [RiskController::class, 'update'])->whereNumber('riskId')->name('update');
            Route::delete('/risks/{riskId}', [RiskController::class, 'destroy'])->whereNumber('riskId')->name('destroy');
            // Assessments are only ever added: no update or delete route exists on purpose.
            Route::post('/risks/{riskId}/assessments', [RiskAssessmentController::class, 'store'])->whereNumber('riskId')->name('assessments.store');
            // Risiko → håndteres av → Kontroll. Only the link is written or removed; the control
            // stays in Kvalitet untouched either way.
            Route::post('/risks/{riskId}/controls', [RiskControlController::class, 'store'])->whereNumber('riskId')->name('controls.store');
            Route::delete('/risks/{riskId}/controls/{controlId}', [RiskControlController::class, 'destroy'])->whereNumber(['riskId', 'controlId'])->name('controls.destroy');
            // Risiko → hører hjemme i → Kvalitet-prosess / -aktivitet. Shown from the risk only.
            Route::post('/risks/{riskId}/context', [RiskContextController::class, 'store'])->whereNumber('riskId')->name('context.store');
            Route::delete('/risks/{riskId}/context/processes/{processId}', [RiskContextController::class, 'destroyProcess'])->whereNumber(['riskId', 'processId'])->name('context.processes.destroy');
            Route::delete('/risks/{riskId}/context/activities/{linkId}', [RiskContextController::class, 'destroyActivity'])->whereNumber(['riskId', 'linkId'])->name('context.activities.destroy');
            // Tiltak on a risk. Reached through the risk only; every write takes risk.edit in its area.
            Route::post('/risks/{riskId}/actions', [RiskTreatmentActionController::class, 'store'])->whereNumber('riskId')->name('actions.store');
            Route::patch('/risks/{riskId}/actions/{actionId}', [RiskTreatmentActionController::class, 'update'])->whereNumber(['riskId', 'actionId'])->name('actions.update');
            Route::post('/risks/{riskId}/actions/{actionId}/complete', [RiskTreatmentActionController::class, 'complete'])->whereNumber(['riskId', 'actionId'])->name('actions.complete');
            Route::post('/risks/{riskId}/actions/{actionId}/reopen', [RiskTreatmentActionController::class, 'reopen'])->whereNumber(['riskId', 'actionId'])->name('actions.reopen');
            // Acceptance of residual risk. Never edited; a mistake is revoked and accepted anew. risk.accept in the area.
            Route::post('/risks/{riskId}/acceptances', [RiskAcceptanceController::class, 'store'])->whereNumber('riskId')->name('acceptances.store');
            Route::post('/risks/{riskId}/acceptances/{acceptanceId}/revoke', [RiskAcceptanceController::class, 'revoke'])->whereNumber(['riskId', 'acceptanceId'])->name('acceptances.revoke');
            // Risiko → Enterprise Wiki: what the person wrote becomes an ordinary Wiki source. risk.edit + wiki.source.manage.
            Route::post('/risks/{riskId}/wiki-knowledge', [RiskWikiKnowledgeController::class, 'store'])->whereNumber('riskId')->name('wiki-knowledge.store');
        });
        // Mål og KPI. Named under `app.objectives.`, mapped to the `objectives` module. Objectives are
        // addressed by a plain id and resolved through ObjectiveAccessService, never by implicit
        // model binding, so one outside the user's fagområder is a 404 like an id that does not exist.
        Route::prefix('/objectives')->name('objectives.')->group(function (): void {
            Route::get('/', [ObjectiveController::class, 'index'])->name('index');
            Route::post('/', [ObjectiveController::class, 'store'])->name('store');
            Route::get('/{objectiveId}', [ObjectiveController::class, 'show'])->whereNumber('objectiveId')->name('show');
            Route::patch('/{objectiveId}', [ObjectiveController::class, 'update'])->whereNumber('objectiveId')->name('update');
            Route::delete('/{objectiveId}', [ObjectiveController::class, 'destroy'])->whereNumber('objectiveId')->name('destroy');
            // Lukk mål / Gjenåpne: the only ways status changes. Each writes an immutable history row.
            Route::post('/{objectiveId}/close', [ObjectiveController::class, 'close'])->whereNumber('objectiveId')->name('close');
            Route::post('/{objectiveId}/reopen', [ObjectiveController::class, 'reopen'])->whereNumber('objectiveId')->name('reopen');
            // KPIs, always under their objective: resolved through the objective, never on their own.
            Route::post('/{objectiveId}/kpis', [KpiController::class, 'store'])->whereNumber('objectiveId')->name('kpis.store');
            Route::get('/{objectiveId}/kpis/{kpiId}', [KpiController::class, 'show'])->whereNumber(['objectiveId', 'kpiId'])->name('kpis.show');
            Route::patch('/{objectiveId}/kpis/{kpiId}', [KpiController::class, 'update'])->whereNumber(['objectiveId', 'kpiId'])->name('kpis.update');
            Route::delete('/{objectiveId}/kpis/{kpiId}', [KpiController::class, 'destroy'])->whereNumber(['objectiveId', 'kpiId'])->name('kpis.destroy');
            // Avslutt / Gjenåpne: the only ways a KPI's status changes. Each writes an immutable history row.
            Route::post('/{objectiveId}/kpis/{kpiId}/retire', [KpiController::class, 'retire'])->whereNumber(['objectiveId', 'kpiId'])->name('kpis.retire');
            Route::post('/{objectiveId}/kpis/{kpiId}/reopen', [KpiController::class, 'reopen'])->whereNumber(['objectiveId', 'kpiId'])->name('kpis.reopen');
            // Measurements: registered (a correction is a new one) and withdrawn — never edited or deleted.
            // What the KPI measures in Kvalitet, one process at a time (KpiContextController).
            Route::put('/{objectiveId}/kpis/{kpiId}/processes/{processId}', [KpiContextController::class, 'update'])->whereNumber(['objectiveId', 'kpiId', 'processId'])->name('kpis.context.update');
            Route::post('/{objectiveId}/kpis/{kpiId}/measurements', [KpiMeasurementController::class, 'store'])->whereNumber(['objectiveId', 'kpiId'])->name('kpis.measurements.store');
            Route::post('/{objectiveId}/kpis/{kpiId}/measurements/{measurementId}/withdraw', [KpiMeasurementController::class, 'withdraw'])->whereNumber(['objectiveId', 'kpiId', 'measurementId'])->name('kpis.measurements.withdraw');
        });
        // Avvik og forbedringer. Named under `app.improvements.`, mapped to the `improvements` module.
        // Cases are addressed by a plain id and resolved through ImprovementCaseAccessService, never
        // by implicit model binding, so one outside the user's fagområder is a 404.
        Route::prefix('/improvements')->name('improvements.')->group(function (): void {
            Route::get('/', [ImprovementCaseController::class, 'index'])->name('index');
            Route::post('/', [ImprovementCaseController::class, 'store'])->name('store');
            Route::get('/{caseId}', [ImprovementCaseController::class, 'show'])->whereNumber('caseId')->name('show');
            Route::patch('/{caseId}', [ImprovementCaseController::class, 'update'])->whereNumber('caseId')->name('update');
            Route::delete('/{caseId}', [ImprovementCaseController::class, 'destroy'])->whereNumber('caseId')->name('destroy');
            // Start behandling / Lukk / Avbryt / Gjenåpne: the only ways status changes. Each writes an
            // immutable history row; no route edits or removes one.
            Route::post('/{caseId}/start', [ImprovementCaseController::class, 'start'])->whereNumber('caseId')->name('start');
            Route::post('/{caseId}/close', [ImprovementCaseController::class, 'close'])->whereNumber('caseId')->name('close');
            Route::post('/{caseId}/cancel', [ImprovementCaseController::class, 'cancel'])->whereNumber('caseId')->name('cancel');
            Route::post('/{caseId}/reopen', [ImprovementCaseController::class, 'reopen'])->whereNumber('caseId')->name('reopen');
            // Årsak og bakgrunn: one text, written while the case is open or under arbeid.
            Route::put('/{caseId}/cause', [ImprovementCaseController::class, 'updateCause'])->whereNumber('caseId')->name('cause.update');
            // Which Kvalitet process and activities the case concerns, one process at a time.
            Route::put('/{caseId}/processes/{processId}', [ImprovementCaseContextController::class, 'update'])->whereNumber(['caseId', 'processId'])->name('context.update');
            // Tiltak, reached only through their case. Start / Fullfør / Avbryt / Gjenåpne are the only
            // ways a tiltak's status changes; each writes an immutable history row.
            Route::post('/{caseId}/actions', [ImprovementActionController::class, 'store'])->whereNumber('caseId')->name('actions.store');
            Route::patch('/{caseId}/actions/{actionId}', [ImprovementActionController::class, 'update'])->whereNumber(['caseId', 'actionId'])->name('actions.update');
            Route::delete('/{caseId}/actions/{actionId}', [ImprovementActionController::class, 'destroy'])->whereNumber(['caseId', 'actionId'])->name('actions.destroy');
            Route::post('/{caseId}/actions/{actionId}/start', [ImprovementActionController::class, 'start'])->whereNumber(['caseId', 'actionId'])->name('actions.start');
            Route::post('/{caseId}/actions/{actionId}/complete', [ImprovementActionController::class, 'complete'])->whereNumber(['caseId', 'actionId'])->name('actions.complete');
            Route::post('/{caseId}/actions/{actionId}/cancel', [ImprovementActionController::class, 'cancel'])->whereNumber(['caseId', 'actionId'])->name('actions.cancel');
            Route::post('/{caseId}/actions/{actionId}/reopen', [ImprovementActionController::class, 'reopen'])->whereNumber(['caseId', 'actionId'])->name('actions.reopen');
            // Effektverifisering of a completed tiltak: append-only, a new judgement never edits an old one.
            Route::post('/{caseId}/actions/{actionId}/verify', [ImprovementActionController::class, 'verify'])->whereNumber(['caseId', 'actionId'])->name('actions.verify');
        });
        // Etterlevelse og revisjon. Named under `app.compliance.`, mapped to the `compliance` module.
        // Requirements and sources are addressed by a plain id and resolved through
        // ComplianceAccessService, never by implicit model binding, so one of another customer is a 404.
        Route::prefix('/compliance')->name('compliance.')->group(function (): void {
            Route::get('/', [ComplianceRequirementController::class, 'home'])->name('index');
            Route::get('/requirements', [ComplianceRequirementController::class, 'index'])->name('requirements.index');
            Route::post('/requirements', [ComplianceRequirementController::class, 'store'])->name('requirements.store');
            Route::get('/requirements/{requirementId}', [ComplianceRequirementController::class, 'show'])->whereNumber('requirementId')->name('requirements.show');
            Route::patch('/requirements/{requirementId}', [ComplianceRequirementController::class, 'update'])->whereNumber('requirementId')->name('requirements.update');
            Route::delete('/requirements/{requirementId}', [ComplianceRequirementController::class, 'destroy'])->whereNumber('requirementId')->name('requirements.destroy');
            // Sett som utgått / Gjenåpne: the only ways status changes. Each writes an immutable history row.
            Route::post('/requirements/{requirementId}/retire', [ComplianceRequirementController::class, 'retire'])->whereNumber('requirementId')->name('requirements.retire');
            Route::post('/requirements/{requirementId}/reopen', [ComplianceRequirementController::class, 'reopen'])->whereNumber('requirementId')->name('requirements.reopen');
            // Vurder etterlevelse: append-only, a correction is a new assessment.
            Route::post('/requirements/{requirementId}/assessments', [ComplianceRequirementController::class, 'assess'])->whereNumber('requirementId')->name('requirements.assess');
            // Hvordan kravet oppfylles: links to existing Kvalitet processes and controls, never copies.
            Route::post('/requirements/{requirementId}/processes', [ComplianceRequirementController::class, 'linkProcess'])->whereNumber('requirementId')->name('requirements.processes.store');
            Route::delete('/requirements/{requirementId}/processes/{processId}', [ComplianceRequirementController::class, 'unlinkProcess'])->whereNumber(['requirementId', 'processId'])->name('requirements.processes.destroy');
            Route::post('/requirements/{requirementId}/controls', [ComplianceRequirementController::class, 'linkControl'])->whereNumber('requirementId')->name('requirements.controls.store');
            Route::delete('/requirements/{requirementId}/controls/{controlId}', [ComplianceRequirementController::class, 'unlinkControl'])->whereNumber(['requirementId', 'controlId'])->name('requirements.controls.destroy');
            // Revisjoner. Planned and run with compliance.audit; status changes only through the lifecycle
            // actions, each writing an immutable history row.
            Route::get('/audits', [ComplianceAuditController::class, 'index'])->name('audits.index');
            Route::post('/audits', [ComplianceAuditController::class, 'store'])->name('audits.store');
            Route::get('/audits/{auditId}', [ComplianceAuditController::class, 'show'])->whereNumber('auditId')->name('audits.show');
            Route::patch('/audits/{auditId}', [ComplianceAuditController::class, 'update'])->whereNumber('auditId')->name('audits.update');
            Route::delete('/audits/{auditId}', [ComplianceAuditController::class, 'destroy'])->whereNumber('auditId')->name('audits.destroy');
            Route::post('/audits/{auditId}/start', [ComplianceAuditController::class, 'start'])->whereNumber('auditId')->name('audits.start');
            Route::post('/audits/{auditId}/complete', [ComplianceAuditController::class, 'complete'])->whereNumber('auditId')->name('audits.complete');
            Route::post('/audits/{auditId}/cancel', [ComplianceAuditController::class, 'cancel'])->whereNumber('auditId')->name('audits.cancel');
            Route::post('/audits/{auditId}/reopen', [ComplianceAuditController::class, 'reopen'])->whereNumber('auditId')->name('audits.reopen');
            // Scope as structure: fixed rows. «Fra kravkilde» is a shortcut that writes them, never a rule.
            Route::post('/audits/{auditId}/requirements', [ComplianceAuditController::class, 'addRequirements'])->whereNumber('auditId')->name('audits.requirements.store');
            Route::post('/audits/{auditId}/requirements/from-source', [ComplianceAuditController::class, 'addRequirementsFromSource'])->whereNumber('auditId')->name('audits.requirements.from-source');
            Route::delete('/audits/{auditId}/requirements/{requirementId}', [ComplianceAuditController::class, 'removeRequirement'])->whereNumber(['auditId', 'requirementId'])->name('audits.requirements.destroy');
            Route::post('/audits/{auditId}/processes', [ComplianceAuditController::class, 'addProcess'])->whereNumber('auditId')->name('audits.processes.store');
            Route::delete('/audits/{auditId}/processes/{processId}', [ComplianceAuditController::class, 'removeProcess'])->whereNumber(['auditId', 'processId'])->name('audits.processes.destroy');
            Route::post('/audits/{auditId}/findings', [ComplianceAuditController::class, 'storeFinding'])->whereNumber('auditId')->name('audits.findings.store');
            Route::patch('/audits/{auditId}/findings/{findingId}', [ComplianceAuditController::class, 'updateFinding'])->whereNumber(['auditId', 'findingId'])->name('audits.findings.update');
            Route::delete('/audits/{auditId}/findings/{findingId}', [ComplianceAuditController::class, 'destroyFinding'])->whereNumber(['auditId', 'findingId'])->name('audits.findings.destroy');
            Route::post('/audits/{auditId}/findings/{findingId}/handoff', [ComplianceAuditController::class, 'handOffFinding'])->whereNumber(['auditId', 'findingId'])->name('audits.findings.handoff');
            // Kravkilder, managed from the Krav register.
            Route::post('/sources', [ComplianceSourceController::class, 'store'])->name('sources.store');
            Route::patch('/sources/{sourceId}', [ComplianceSourceController::class, 'update'])->whereNumber('sourceId')->name('sources.update');
            Route::delete('/sources/{sourceId}', [ComplianceSourceController::class, 'destroy'])->whereNumber('sourceId')->name('sources.destroy');
        });
        // Leverandøroppfølging. Named under `app.supplier-management.`, mapped to the `supplier` module.
        // Not /suppliers: that path and `app.suppliers.` are Anbud's Doffin competitor view.
        // Suppliers are addressed by a plain id and resolved through SupplierAccessService, never by
        // implicit model binding, so one of another customer is a 404.
        Route::prefix('/supplier-management')->name('supplier-management.')->group(function (): void {
            Route::get('/', [SupplierManagementController::class, 'index'])->name('index');
            Route::post('/', [SupplierManagementController::class, 'store'])->name('store');
            Route::get('/{supplierId}', [SupplierManagementController::class, 'show'])->whereNumber('supplierId')->name('show');
            Route::patch('/{supplierId}', [SupplierManagementController::class, 'update'])->whereNumber('supplierId')->name('update');
            Route::delete('/{supplierId}', [SupplierManagementController::class, 'destroy'])->whereNumber('supplierId')->name('destroy');
            // Ta i bruk / Avslutt leverandør / Gjenåpne leverandør: the only ways status changes. Each
            // writes an immutable history row.
            Route::post('/{supplierId}/activate', [SupplierManagementController::class, 'activate'])->whereNumber('supplierId')->name('activate');
            Route::post('/{supplierId}/end', [SupplierManagementController::class, 'end'])->whereNumber('supplierId')->name('end');
            Route::post('/{supplierId}/reopen', [SupplierManagementController::class, 'reopen'])->whereNumber('supplierId')->name('reopen');
        });
        Route::get('/customer-environment', [CustomerEnvironmentController::class, 'index'])->name('customer-environment.index');
        Route::patch('/customer-environment/permissions', [CustomerEnvironmentController::class, 'updatePermissions'])->name('customer-environment.permissions.update');

        // Customer-defined roles. System Owner only, enforced in the controller — these sit beside
        // the fixed bid_role matrix above and never touch anbud authorization. Tilganger defines
        // a role; handing it to a person happens on the user's own edit screen.
        Route::post('/customer-environment/roles', [CustomerRoleController::class, 'store'])
            ->name('customer-environment.roles.store');
        Route::patch('/customer-environment/roles/{customerRole}', [CustomerRoleController::class, 'update'])
            ->name('customer-environment.roles.update');
        Route::delete('/customer-environment/roles/{customerRole}', [CustomerRoleController::class, 'destroy'])
            ->name('customer-environment.roles.destroy');

        // Fagområder. Named here, attached to roles on the role itself (or reached with «Alle»). System
        // Owner only, enforced in the controller — the same gate as the roles beside them.
        Route::post('/customer-environment/business-areas', [BusinessAreaController::class, 'store'])
            ->name('customer-environment.business-areas.store');
        Route::patch('/customer-environment/business-areas/{businessArea}', [BusinessAreaController::class, 'update'])
            ->name('customer-environment.business-areas.update');
        Route::delete('/customer-environment/business-areas/{businessArea}', [BusinessAreaController::class, 'destroy'])
            ->name('customer-environment.business-areas.destroy');
        Route::get('/info-center', [InfoCenterController::class, 'index'])->name('info-center.index');
        Route::get('/ai', [AiController::class, 'index'])->name('ai.index');
        Route::get('/ai/{savedNotice}', [AiController::class, 'show'])->name('ai.show');
        Route::get('/ai/{savedNotice}/instructions', [AiController::class, 'instructions'])->name('ai.instructions.show');
        Route::patch('/ai/{savedNotice}/instructions', [AiController::class, 'updateAiInstructions'])
            ->name('ai.instructions.update');
        Route::post('/ai/{savedNotice}/documents', [AiController::class, 'storeDocuments'])->name('ai.documents.store');
        Route::get('/ai/{savedNotice}/documents/{document}/preview', [AiController::class, 'previewDocument'])->name('ai.documents.preview');
        Route::get('/ai/{savedNotice}/documents/{document}/preview-file', [AiController::class, 'previewPdfDocument'])->name('ai.documents.preview-file');
        Route::get('/ai/{savedNotice}/documents/{document}/download', [AiController::class, 'downloadDocument'])->name('ai.documents.download');
        Route::get('/ai/{savedNotice}/export/requirements.docx', [AiController::class, 'exportRequirementsToDocx'])->name('ai.requirements.export.docx');
        Route::delete('/ai/{savedNotice}/documents/{document}', [AiController::class, 'destroyDocument'])->name('ai.documents.destroy');
        Route::post('/ai/{savedNotice}/answer-basis/documents', [AiController::class, 'storeAnswerBasisDocuments'])
            ->name('ai.answer-basis.documents.store');
        Route::post('/ai/{savedNotice}/answer-basis/texts', [AiController::class, 'storeAnswerBasisText'])
            ->name('ai.answer-basis.texts.store');
        Route::delete('/ai/{savedNotice}/answer-basis/{answerBasisItem}', [AiController::class, 'destroyAnswerBasisItem'])
            ->name('ai.answer-basis.destroy');
        Route::post('/ai/{savedNotice}/requirements', [AiController::class, 'storeRequirement'])
            ->name('ai.requirements.store');
        Route::patch('/ai/{savedNotice}/requirements/reject-all', [AiController::class, 'rejectAllRequirements'])
            ->name('ai.requirements.reject-all');
        // Permanent deletion, deliberately distinct from reject-all above: rejection is reversible,
        // this is not. Declared before the {requirement} route so 'delete-all' is never bound as a
        // requirement id.
        Route::delete('/ai/{savedNotice}/requirements/delete-all', [AiController::class, 'destroyAllRequirements'])
            ->name('ai.requirements.destroy-all');
        Route::delete('/ai/{savedNotice}/requirements/{requirement}', [AiController::class, 'destroyRequirement'])
            ->name('ai.requirements.destroy');
        Route::patch('/ai/{savedNotice}/requirements/{requirement}', [AiController::class, 'updateRequirement'])
            ->name('ai.requirements.update');
        Route::patch('/ai/{savedNotice}/requirements/{requirement}/assigned-user', [AiController::class, 'updateRequirementAssignedUser'])
            ->name('ai.requirements.assigned-user.update');
        Route::patch('/ai/{savedNotice}/requirements/{requirement}/review-status', [AiController::class, 'updateRequirementReviewStatus'])
            ->name('ai.requirements.review-status.update');
        Route::patch('/ai/{savedNotice}/requirements/{requirement}/work', [AiController::class, 'updateRequirementWork'])
            ->name('ai.requirements.work.update');
        Route::patch('/ai/{savedNotice}/requirements/{requirement}/answer-basis', [AiController::class, 'syncRequirementAnswerBasisSelection'])
            ->name('ai.requirements.answer-basis.sync');
        Route::post('/ai/{savedNotice}/requirements/{requirement}/wiki-answer', [AiController::class, 'generateRequirementWikiAnswer'])
            ->name('ai.requirements.wiki-answer.generate');
        Route::patch('/ai/{savedNotice}/requirements/{requirement}/wiki-answer', [AiController::class, 'updateRequirementWikiAnswer'])
            ->name('ai.requirements.wiki-answer.update');
        Route::post('/ai/{savedNotice}/assessments/refresh', [AiController::class, 'refreshAssessments'])
            ->name('ai.requirements.assessment.refresh');
        // Polled by the bell while the person sits still, so a notification that arrives during a
        // long session is not invisible until they happen to navigate.
        Route::get('/notifications', [UserNotificationController::class, 'index'])->name('notifications.index');
        Route::patch('/notifications/read-all', [UserNotificationController::class, 'markAllRead'])->name('notifications.read-all');
        Route::patch('/notifications/{userNotification}/read', [UserNotificationController::class, 'markRead'])->name('notifications.read');
        // Before the {userNotification} route, or "unread" would be read as an id.
        Route::delete('/notifications/unread', [UserNotificationController::class, 'destroyUnread'])->name('notifications.destroy-unread');
        Route::delete('/notifications/{userNotification}', [UserNotificationController::class, 'destroy'])->name('notifications.destroy');
        Route::get('/inbox/{any?}', static fn () => redirect()->route('app.info-center.index'))->where('any', '.*');
        Route::get('/messages/{any?}', static fn () => redirect()->route('app.info-center.index'))->where('any', '.*');

        Route::get('/notices', [NoticeController::class, 'index'])->name('notices.index');
        Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
        Route::get('/suppliers/{supplier}', [SupplierController::class, 'show'])->name('suppliers.show');
        Route::get('/notices/cpv-suggestions', [NoticeController::class, 'cpvSuggestions'])->name('notices.cpv-suggestions');
        Route::post('/notices/save', [NoticeController::class, 'storeSavedNotice'])->name('notices.save');
        Route::delete('/notices/watch-alerts/{watchProfileInboxRecord}', [NoticeController::class, 'destroyWatchAlertRecord'])
            ->name('notices.watch-alerts.destroy');
        Route::get('/notices/saved/{savedNotice}', [NoticeController::class, 'showSavedNotice'])->name('notices.saved.show');
        Route::post('/notices/saved/{savedNotice}/case-access', [NoticeController::class, 'storeSavedNoticeCaseAccess'])->name('notices.saved.case-access.store');
        Route::delete('/notices/saved/{savedNotice}/case-access/{caseAccess}', [NoticeController::class, 'destroySavedNoticeCaseAccess'])->name('notices.saved.case-access.destroy');
        Route::post('/notices/saved/{savedNotice}/phase-comments', [NoticeController::class, 'storeSavedNoticePhaseComment'])->name('notices.saved.phase-comments.store');
        Route::post('/notices/saved/{savedNotice}/info-items', [NoticeController::class, 'storeSavedNoticeInfoItem'])->name('notices.saved.info-items.store');
        Route::patch('/notices/saved/{savedNotice}/info-items/{infoItem}/close', [NoticeController::class, 'closeSavedNoticeInfoItem'])->name('notices.saved.info-items.close');
        Route::post('/notices/saved/{savedNotice}/submissions', [NoticeController::class, 'storeSavedNoticeSubmission'])->name('notices.saved.submissions.store');
        Route::patch('/notices/saved/{savedNotice}/status', [NoticeController::class, 'updateSavedNoticeStatus'])->name('notices.saved.status.update');
        Route::patch('/notices/saved/{savedNotice}/reopen-after-no-go', [NoticeController::class, 'reopenSavedNoticeAfterNoGo'])
            ->name('notices.saved.reopen-after-no-go');
        Route::patch('/notices/saved/{savedNotice}/opportunity-owner', [NoticeController::class, 'updateSavedNoticeOpportunityOwner'])->name('notices.saved.opportunity-owner.update');
        Route::patch('/notices/saved/{savedNotice}/bid-manager', [NoticeController::class, 'updateSavedNoticeBidManager'])->name('notices.saved.bid-manager.update');
        Route::patch('/notices/saved/{savedNotice}/deadlines', [NoticeController::class, 'updateSavedNoticeDeadlines'])->name('notices.saved.deadlines.update');
        Route::patch('/notices/saved/{savedNotice}/history-metadata', [NoticeController::class, 'updateSavedNoticeHistoryMetadata'])->name('notices.saved.history-metadata.update');
        Route::patch('/notices/saved/{savedNotice}/archive', [NoticeController::class, 'archiveSavedNotice'])->name('notices.saved.archive');
        Route::delete('/notices/history/{savedNotice}', [NoticeController::class, 'destroyArchivedSavedNotice'])->name('notices.history.destroy');
        Route::delete('/notices/saved/{savedNotice}', [NoticeController::class, 'destroySavedNotice'])->name('notices.saved.destroy');
        Route::get('/notices/{notice}', [NoticeController::class, 'show'])->name('notices.show');
        Route::get('/departments', [DepartmentController::class, 'index'])->name('departments.index');
        Route::get('/departments/create', [DepartmentController::class, 'create'])->name('departments.create');
        Route::post('/departments', [DepartmentController::class, 'store'])->name('departments.store');
        Route::get('/departments/{department}/edit', [DepartmentController::class, 'edit'])->name('departments.edit');
        Route::put('/departments/{department}', [DepartmentController::class, 'update'])->name('departments.update');
        Route::patch('/departments/{department}/toggle-active', [DepartmentController::class, 'toggleActive'])->name('departments.toggle-active');
        Route::get('/watch-profiles', [WatchProfileController::class, 'index'])->name('watch-profiles.index');
        Route::get('/watch-profiles/cpv-suggestions', [WatchProfileController::class, 'cpvSuggestions'])->name('watch-profiles.cpv-suggestions');
        Route::get('/watch-profiles/create', [WatchProfileController::class, 'create'])->name('watch-profiles.create');
        Route::post('/watch-profiles', [WatchProfileController::class, 'store'])->name('watch-profiles.store');
        Route::get('/watch-profiles/{watchProfile}/edit', [WatchProfileController::class, 'edit'])->name('watch-profiles.edit');
        Route::put('/watch-profiles/{watchProfile}', [WatchProfileController::class, 'update'])->name('watch-profiles.update');
        Route::patch('/watch-profiles/{watchProfile}/toggle-active', [WatchProfileController::class, 'toggleActive'])->name('watch-profiles.toggle-active');
        Route::delete('/watch-profiles/{watchProfile}', [WatchProfileController::class, 'destroy'])->name('watch-profiles.destroy');
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::patch('/users/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggle-active');
        Route::get('/notices/{notice}/documents/{document}/download', [NoticeDocumentDownloadController::class, 'download'])
            ->name('notices.documents.download');
        Route::get('/notices/{notice}/documents/download-all', [NoticeDocumentDownloadController::class, 'downloadAll'])
            ->name('notices.documents.download-all');
        Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');
        Route::post('/billing/cancel', [BillingController::class, 'cancel'])->name('billing.cancel');
        Route::post('/billing/resume', [BillingController::class, 'resume'])->name('billing.resume');
        Route::post('/billing/change-plan', [BillingController::class, 'changePlan'])->name('billing.change-plan');
        Route::post('/billing/packages/{package}/request', [BillingController::class, 'requestPackage'])
            ->name('billing.packages.request');

        // Go/No-go template admin (System Owner only)
        Route::prefix('/go-no-go-templates')->name('go-no-go-templates.')->group(function (): void {
            Route::get('/', [GoNoGoTemplateController::class, 'index'])->name('index');
            Route::post('/', [GoNoGoTemplateController::class, 'store'])->name('store');
            Route::get('/{template}/edit', [GoNoGoTemplateController::class, 'edit'])->name('edit');
            Route::put('/{template}', [GoNoGoTemplateController::class, 'update'])->name('update');
            Route::patch('/{template}/toggle-active', [GoNoGoTemplateController::class, 'toggleActive'])->name('toggle-active');
            Route::patch('/{template}/set-default', [GoNoGoTemplateController::class, 'setDefault'])->name('set-default');
            Route::post('/{template}/criteria', [GoNoGoTemplateController::class, 'storeCriterion'])->name('criteria.store');
            Route::put('/{template}/criteria/{criterion}', [GoNoGoTemplateController::class, 'updateCriterion'])->name('criteria.update');
            Route::patch('/{template}/criteria/{criterion}/toggle-active', [GoNoGoTemplateController::class, 'toggleActiveCriterion'])->name('criteria.toggle-active');
        });

        // Go/No-go assessment persistence (all users with case access)
        Route::patch('/notices/saved/{savedNotice}/go-no-go-assessment', [GoNoGoAssessmentController::class, 'upsert'])
            ->name('notices.saved.go-no-go-assessment.upsert');

        // Enterprise Wiki (fase 2-3, 4A)
        Route::prefix('/wiki')->name('wiki.')->group(function (): void {
            Route::get('/', [WikiController::class, 'index'])->name('index');
            Route::post('/sources', [WikiSourceController::class, 'store'])->name('sources.store');
            Route::post('/sources/{document}/ingest', [WikiSourceController::class, 'ingest'])->name('sources.ingest');
            Route::patch('/sources/{document}/owner', [WikiSourceController::class, 'updateOwner'])->name('sources.owner.update');
            Route::delete('/sources/{document}', [WikiSourceController::class, 'destroy'])->name('sources.destroy');
            Route::get('/sources/{document}/delete-preview', [WikiSourceController::class, 'deletePreview'])->name('sources.delete-preview');
            Route::patch('/sources/{document}/cancel-blocking-runs', [WikiSourceController::class, 'cancelBlockingRunsForDeletion'])->name('sources.cancel-blocking-runs');
            Route::get('/sources/{document}/download', [WikiSourceController::class, 'download'])->name('sources.download');
            Route::get('/sources/{document}/images/{imageKey}', [WikiSourceController::class, 'image'])->name('sources.image');
            Route::get('/graph-data', [WikiGraphDataController::class, '__invoke'])->name('graph.data');
            // Neo4j-backed focus traversal. Separate from /graph-data on purpose: that endpoint is
            // the SQL-backed graph and must keep answering whether or not the projection runs.
            Route::get('/graph-focus', [WikiGraphFocusController::class, '__invoke'])->name('graph.focus');
            Route::get('/graph', [WikiGraphController::class, '__invoke'])->name('graph');
            // "Spør Wiki" — read-only Q&A. Must stay above the /{slug} catch-all below, or "ask"
            // would be resolved as a page slug.
            Route::get('/ask', [WikiAskController::class, 'index'])->name('ask');
            Route::post('/ask', [WikiAskController::class, 'ask'])->name('ask.submit');
            Route::get('/runs/{run}/pages', [WikiController::class, 'runPages'])->name('runs.pages');
            Route::get('/runs/{run}/findings', [WikiController::class, 'runFindings'])->name('runs.findings');
            Route::patch('/runs/{run}/cancel', [WikiController::class, 'cancelRun'])->name('runs.cancel');
            Route::patch('/runs/{run}/retry-maintainer-decision', [WikiController::class, 'retryMaintainerDecision'])->name('runs.retry-maintainer-decision');
            Route::patch('/{slug}/claims/{claim}/manual-block-edit', [WikiController::class, 'updateManualMixedBlockEdit'])->name('claims.manual-block-edit.update');
            // Ordinary manual editing of a page's working version. Page-addressed on purpose: the
            // claims/{claim}/manual-block-edit route above is claim repair, with claim-approval
            // authorization, and general editing must not inherit that contract.
            Route::patch('/{slug}/working-version', [WikiController::class, 'updateWorkingVersion'])->name('working-version.update');
            Route::get('/{slug}', [WikiController::class, 'show'])->name('show');
            // Deleting a Wiki page is the page's own action, not a side effect of deleting the
            // source document behind it. Reached by DELETE, so it cannot collide with the GET
            // catch-all above.
            Route::delete('/{slug}', [WikiController::class, 'destroy'])->name('destroy');
            Route::patch('/{slug}/submit', [WikiController::class, 'submit'])->name('submit');
            // Asking for quality assurance, which is separate from handing the page to a
            // reviewer: QA contributes, the Wiki approver decides.
            Route::patch('/{slug}/qa-assignment', [WikiController::class, 'updateQaAssignment'])->name('qa-assignment.update');
            Route::patch('/{slug}/approve', [WikiController::class, 'approve'])->name('approve');
            Route::patch('/{slug}/reject', [WikiController::class, 'reject'])->name('reject');
            Route::patch('/{slug}/document-owner-approvals/{approval}/approve', [WikiDocumentOwnerApprovalController::class, 'approve'])->name('document-owner-approvals.approve');
            Route::patch('/{slug}/document-owner-approvals/{approval}/reject', [WikiDocumentOwnerApprovalController::class, 'reject'])->name('document-owner-approvals.reject');
            Route::get('/{slug}/claims/{claim}/source-documents/{document}/elements', [WikiClaimController::class, 'sourceDocumentElements'])->name('claims.source-documents.elements');
            Route::post('/{slug}/claims/{claim}/source-references', [WikiClaimController::class, 'storeSourceReference'])->name('claims.source-references.store');
            Route::patch('/{slug}/claims/{claim}/approve', [WikiClaimController::class, 'approve'])->name('claims.approve');
            Route::patch('/{slug}/claims/{claim}/reject', [WikiClaimController::class, 'reject'])->name('claims.reject');
            Route::patch('/{slug}/claims/{claim}/unapprove', [WikiClaimController::class, 'unapprove'])->name('claims.unapprove');
            Route::patch('/{slug}/claims/{claim}/blocking', [WikiClaimController::class, 'updateBlockingOverride'])->name('claims.blocking.update');
        });
    });
