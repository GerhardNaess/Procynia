<?php

use App\Http\Controllers\App\DepartmentController;
use App\Http\Controllers\App\UserController;
use App\Http\Controllers\App\WatchProfileController;
use App\Http\Controllers\App\WatchProfileInboxController;
use App\Http\Controllers\App\NoticeController;
use App\Http\Controllers\App\NoticeDocumentDownloadController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $user = auth()->user();

    if (! $user) {
        return redirect()->route('login');
    }

    return method_exists($user, 'canAccessCustomerFrontend') && $user->canAccessCustomerFrontend()
        ? redirect()->route('app.notices.index')
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
        Route::redirect('/', '/app/notices');

        Route::get('/notices', [NoticeController::class, 'index'])->name('notices.index');
        Route::get('/inbox/user', [WatchProfileInboxController::class, 'userInbox'])->name('inbox.user');
        Route::get('/inbox/department', [WatchProfileInboxController::class, 'departmentInbox'])->name('inbox.department');
        Route::get('/notices/cpv-suggestions', [NoticeController::class, 'cpvSuggestions'])->name('notices.cpv-suggestions');
        Route::post('/notices/save', [NoticeController::class, 'storeSavedNotice'])->name('notices.save');
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
