<?php

use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\AiController;
use App\Http\Controllers\App\KnowledgeBaseController;
use App\Http\Controllers\App\CustomerEnvironmentController;
use App\Http\Controllers\App\DepartmentController;
use App\Http\Controllers\App\InfoCenterController;
use App\Http\Controllers\App\UserNotificationController;
use App\Http\Controllers\App\UserController;
use App\Http\Controllers\App\WatchProfileController;
use App\Http\Controllers\App\NoticeController;
use App\Http\Controllers\App\NoticeDocumentDownloadController;
use App\Http\Controllers\App\SupplierController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $user = auth()->user();

    if (! $user) {
        return redirect()->route('login');
    }

    return method_exists($user, 'canAccessCustomerFrontend') && $user->canAccessCustomerFrontend()
        ? redirect()->route('app.notices.index', ['mode' => 'saved'])
        : redirect()->route('filament.admin.pages.dashboard');
});

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});

Route::prefix('app')
    ->middleware(['auth', 'customer.frontend'])
    ->name('app.')
    ->group(function (): void {
        Route::redirect('/', '/app/notices?mode=saved');
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/customer-environment', [CustomerEnvironmentController::class, 'index'])->name('customer-environment.index');
        Route::get('/info-center', [InfoCenterController::class, 'index'])->name('info-center.index');
        Route::get('/ai', [AiController::class, 'index'])->name('ai.index');
        Route::prefix('/ai/knowledge-base')->name('ai.knowledge-base.')->group(function (): void {
            Route::get('/', [KnowledgeBaseController::class, 'index'])->name('index');
            Route::get('/create', [KnowledgeBaseController::class, 'create'])->name('create');
            Route::post('/', [KnowledgeBaseController::class, 'store'])->name('store');
            Route::post('/retrieval-test', [KnowledgeBaseController::class, 'retrievalTest'])->name('retrieval-test');
            Route::get('/{knowledgeItem}', [KnowledgeBaseController::class, 'show'])->name('show');
            Route::patch('/{knowledgeItem}/summary', [KnowledgeBaseController::class, 'updateSummary'])->name('summary.update');
            Route::patch('/{knowledgeItem}/chunks/{chunk}/review-status', [KnowledgeBaseController::class, 'updateChunkReviewStatus'])
                ->name('chunks.review-status.update');
            Route::patch('/{knowledgeItem}/chunks/{chunk}/metadata', [KnowledgeBaseController::class, 'updateChunkMetadata'])
                ->name('chunks.metadata.update');
            Route::get('/{knowledgeItem}/edit', [KnowledgeBaseController::class, 'edit'])->name('edit');
            Route::put('/{knowledgeItem}', [KnowledgeBaseController::class, 'update'])->name('update');
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
        Route::delete('/ai/{savedNotice}/documents/{document}', [AiController::class, 'destroyDocument'])->name('ai.documents.destroy');
        Route::post('/ai/{savedNotice}/answer-basis/documents', [AiController::class, 'storeAnswerBasisDocuments'])
            ->name('ai.answer-basis.documents.store');
        Route::post('/ai/{savedNotice}/answer-basis/texts', [AiController::class, 'storeAnswerBasisText'])
            ->name('ai.answer-basis.texts.store');
        Route::delete('/ai/{savedNotice}/answer-basis/{answerBasisItem}', [AiController::class, 'destroyAnswerBasisItem'])
            ->name('ai.answer-basis.destroy');
        Route::post('/ai/{savedNotice}/requirements', [AiController::class, 'storeRequirement'])
            ->name('ai.requirements.store');
        Route::patch('/ai/{savedNotice}/requirements/{requirement}', [AiController::class, 'updateRequirement'])
            ->name('ai.requirements.update');
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
    });
