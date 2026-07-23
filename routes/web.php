<?php

use App\Http\Controllers\Admin\OperationalRunbookAttachmentDownloadController;
use App\Http\Controllers\App\AiController;
use App\Http\Controllers\App\BillingController;
use App\Http\Controllers\App\CustomerEnvironmentController;
use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\DepartmentController;
use App\Http\Controllers\App\GoNoGoAssessmentController;
use App\Http\Controllers\App\GoNoGoTemplateController;
use App\Http\Controllers\App\InfoCenterController;
use App\Http\Controllers\App\KnowledgeBaseAiUsageController;
use App\Http\Controllers\App\KnowledgeBaseController;
use App\Http\Controllers\App\KnowledgeBaseSettingsController;
use App\Http\Controllers\App\KnowledgeVocabularyController;
use App\Http\Controllers\App\NoticeController;
use App\Http\Controllers\App\NoticeDocumentDownloadController;
use App\Http\Controllers\App\SupplierController;
use App\Http\Controllers\App\UserController;
use App\Http\Controllers\App\UserNotificationController;
use App\Http\Controllers\App\WatchProfileController;
use App\Http\Controllers\App\WikiClaimController;
use App\Http\Controllers\App\WikiController;
use App\Http\Controllers\App\WikiDocumentOwnerApprovalController;
use App\Http\Controllers\App\WikiGraphController;
use App\Http\Controllers\App\WikiGraphDataController;
use App\Http\Controllers\App\WikiSourceController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
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
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/admin/operational-runbooks/attachments/{attachment}/download', [OperationalRunbookAttachmentDownloadController::class, 'download'])
        ->name('admin.operational-runbook-attachments.download');
});

Route::prefix('app')
    ->middleware(['auth', 'customer.frontend'])
    ->name('app.')
    ->group(function (): void {
        Route::redirect('/', '/app/notices?mode=saved');
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/customer-environment', [CustomerEnvironmentController::class, 'index'])->name('customer-environment.index');
        Route::patch('/customer-environment/permissions', [CustomerEnvironmentController::class, 'updatePermissions'])->name('customer-environment.permissions.update');
        Route::prefix('/customer-environment/knowledge-base')->name('customer-environment.knowledge-base.')->group(function (): void {
            Route::get('/', [KnowledgeBaseSettingsController::class, 'index'])->name('index');
            Route::post('/categories', [KnowledgeBaseSettingsController::class, 'storeCategory'])->name('categories.store');
            Route::patch('/categories/{category}', [KnowledgeBaseSettingsController::class, 'updateCategory'])->name('categories.update');
            Route::delete('/categories/{category}', [KnowledgeBaseSettingsController::class, 'destroyCategory'])->name('categories.destroy');
            Route::post('/topics', [KnowledgeBaseSettingsController::class, 'storeTopic'])->name('topics.store');
            Route::patch('/topics/{topic}', [KnowledgeBaseSettingsController::class, 'updateTopic'])->name('topics.update');
            Route::delete('/topics/{topic}', [KnowledgeBaseSettingsController::class, 'destroyTopic'])->name('topics.destroy');
        });
        Route::get('/info-center', [InfoCenterController::class, 'index'])->name('info-center.index');
        Route::get('/ai', [AiController::class, 'index'])->name('ai.index');
        Route::prefix('/ai/knowledge-vocabulary')->name('ai.knowledge-vocabulary.')->group(function (): void {
            Route::get('/', [KnowledgeVocabularyController::class, 'index'])->name('index');
            Route::post('/analysis-batches', [KnowledgeVocabularyController::class, 'storeBatch'])->name('analysis-batches.store');
            Route::delete('/analysis-batches/{batch}', [KnowledgeVocabularyController::class, 'destroyBatch'])->name('analysis-batches.destroy');
            Route::patch('/terms/{term}', [KnowledgeVocabularyController::class, 'updateTerm'])->name('terms.update');
            Route::delete('/terms/{term}', [KnowledgeVocabularyController::class, 'destroyTerm'])->name('terms.destroy');
            Route::patch('/suggestions/{suggestion}/approve', [KnowledgeVocabularyController::class, 'approveSuggestion'])->name('suggestions.approve');
            Route::patch('/suggestions/{suggestion}/reject', [KnowledgeVocabularyController::class, 'rejectSuggestion'])->name('suggestions.reject');
            Route::patch('/suggestions/{suggestion}/merge', [KnowledgeVocabularyController::class, 'mergeSuggestion'])->name('suggestions.merge');
            Route::patch('/suggestions/{suggestion}/edit-and-approve', [KnowledgeVocabularyController::class, 'editAndApproveSuggestion'])->name('suggestions.edit-and-approve');
        });
        Route::prefix('/ai/knowledge-base')->name('ai.knowledge-base.')->group(function (): void {
            Route::get('/', [KnowledgeBaseController::class, 'index'])->name('index');
            Route::get('/create', [KnowledgeBaseController::class, 'create'])->name('create');
            Route::post('/', [KnowledgeBaseController::class, 'store'])->name('store');
            Route::get('/ai-usage', [KnowledgeBaseAiUsageController::class, 'index'])->name('ai-usage');
            Route::get('/{knowledgeItem}', [KnowledgeBaseController::class, 'show'])->name('show');
            Route::patch('/{knowledgeItem}/summary', [KnowledgeBaseController::class, 'updateSummary'])->name('summary.update');
            Route::patch('/{knowledgeItem}/chunks/{chunk}/review-status', [KnowledgeBaseController::class, 'updateChunkReviewStatus'])
                ->name('chunks.review-status.update');
            Route::patch('/{knowledgeItem}/chunks/{chunk}/metadata', [KnowledgeBaseController::class, 'updateChunkMetadata'])
                ->name('chunks.metadata.update');
            Route::get('/{knowledgeItem}/chunks/{chunk}/image', [KnowledgeBaseController::class, 'showChunkImage'])
                ->name('chunks.image');
            Route::get('/{knowledgeItem}/edit', [KnowledgeBaseController::class, 'edit'])->name('edit');
            Route::put('/{knowledgeItem}', [KnowledgeBaseController::class, 'update'])->name('update');
            Route::post('/{knowledgeItem}/file', [KnowledgeBaseController::class, 'replaceFile'])->name('file.replace');
            Route::post('/{knowledgeItem}/versions/{version}/approve', [KnowledgeBaseController::class, 'approveVersion'])->name('versions.approve');
            Route::post('/{knowledgeItem}/versions/{version}/reject', [KnowledgeBaseController::class, 'rejectVersion'])->name('versions.reject');
            Route::delete('/{knowledgeItem}', [KnowledgeBaseController::class, 'destroy'])->name('destroy');
        });
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
        Route::post('/ai/{savedNotice}/requirements/{requirement}/answer-draft', [AiController::class, 'generateRequirementAnswerDraft'])
            ->name('ai.requirements.answer-draft.generate');
        Route::patch('/ai/{savedNotice}/requirements/{requirement}/answer-draft', [AiController::class, 'updateRequirementAnswerDraft'])
            ->name('ai.requirements.answer-draft.update');
        Route::post('/ai/{savedNotice}/requirements/{requirement}/wiki-answer', [AiController::class, 'generateRequirementWikiAnswer'])
            ->name('ai.requirements.wiki-answer.generate');
        Route::post('/ai/{savedNotice}/evidence/refresh', [AiController::class, 'refreshEvidence'])
            ->name('ai.evidence.refresh');
        Route::post('/ai/{savedNotice}/assessments/refresh', [AiController::class, 'refreshAssessments'])
            ->name('ai.requirements.assessment.refresh');
        Route::patch('/ai/{savedNotice}/evidence/{evidence}/selection-status', [AiController::class, 'updateEvidenceSelectionStatus'])
            ->name('ai.evidence.selection-status.update');
        Route::patch('/notifications/read-all', [UserNotificationController::class, 'markAllRead'])->name('notifications.read-all');
        Route::patch('/notifications/{userNotification}/read', [UserNotificationController::class, 'markRead'])->name('notifications.read');
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
            Route::get('/sources/{document}/download', [WikiSourceController::class, 'download'])->name('sources.download');
            Route::get('/graph-data', [WikiGraphDataController::class, '__invoke'])->name('graph.data');
            Route::get('/graph', [WikiGraphController::class, '__invoke'])->name('graph');
            Route::get('/runs/{run}/pages', [WikiController::class, 'runPages'])->name('runs.pages');
            Route::get('/runs/{run}/findings', [WikiController::class, 'runFindings'])->name('runs.findings');
            Route::patch('/runs/{run}/cancel', [WikiController::class, 'cancelRun'])->name('runs.cancel');
            Route::patch('/{slug}/claims/{claim}/manual-block-edit', [WikiController::class, 'updateManualMixedBlockEdit'])->name('claims.manual-block-edit.update');
            Route::get('/{slug}', [WikiController::class, 'show'])->name('show');
            Route::patch('/{slug}/submit', [WikiController::class, 'submit'])->name('submit');
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
