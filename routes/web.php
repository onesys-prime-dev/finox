<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\Admin;
use App\Http\Controllers\ChequeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\PayeeController;
use App\Http\Controllers\PrintingController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\UtilityController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // Profile & utilities
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/preferences', [ProfileController::class, 'preferences'])->name('profile.preferences');
    Route::get('/search', [UtilityController::class, 'search'])->name('search');
    Route::post('/filters', [UtilityController::class, 'saveFilter'])->name('filters.store');
    Route::delete('/filters/{filter}', [UtilityController::class, 'deleteFilter'])->name('filters.destroy');
    Route::post('/problems', [UtilityController::class, 'reportProblem'])->name('problems.store');
    Route::get('/payees/search', [PayeeController::class, 'search'])->name('payees.search');
    Route::post('/payees/quick', [PayeeController::class, 'quickStore'])->name('payees.quick');

    // Cheques
    Route::prefix('cheques')->name('cheques.')->group(function () {
        Route::get('/', [ChequeController::class, 'index'])->name('index');
        Route::get('/create', [ChequeController::class, 'create'])->name('create');
        Route::get('/check', [ChequeController::class, 'check'])->name('check');
        Route::get('/available-receipts', [ChequeController::class, 'availableReceipts'])->name('available-receipts');
        Route::post('/', [ChequeController::class, 'store'])->middleware('once')->name('store');
        Route::post('/bulk-prepare', [ChequeController::class, 'bulkPrepare'])->name('bulk-prepare');
        Route::get('/{cheque}', [ChequeController::class, 'show'])->name('show');
        Route::get('/{cheque}/schedule', [ChequeController::class, 'schedule'])->name('schedule');
        Route::post('/{cheque}/schedule/printed', [ChequeController::class, 'schedulePrinted'])->name('schedule.printed');
        Route::get('/{cheque}/edit', [ChequeController::class, 'edit'])->name('edit');
        Route::put('/{cheque}', [ChequeController::class, 'update'])->name('update');
        Route::post('/{cheque}/prepare', [ChequeController::class, 'prepare'])->name('prepare');
        Route::post('/{cheque}/release', [ChequeController::class, 'release'])->name('release');
        Route::post('/{cheque}/for-clearing', [ChequeController::class, 'forClearing'])->name('for-clearing');
        Route::post('/{cheque}/clearing', [ChequeController::class, 'clearing'])->name('clearing');
        Route::post('/{cheque}/stale', [ChequeController::class, 'stale'])->name('stale');
        Route::post('/{cheque}/stop', [ChequeController::class, 'stop'])->name('stop');
        Route::post('/{cheque}/documents', [DocumentController::class, 'attach'])->name('documents');
    });

    // Receipts
    Route::prefix('receipts')->name('receipts.')->group(function () {
        Route::get('/', [ReceiptController::class, 'index'])->name('index');
        Route::get('/intake', [ReceiptController::class, 'create'])->name('create');
        Route::get('/new', [ReceiptController::class, 'createForm'])->name('new');
        Route::get('/duplicates', [ReceiptController::class, 'duplicates'])->name('duplicates');
        Route::get('/cheque-search', [ReceiptController::class, 'chequeSearch'])->name('cheque-search');
        Route::post('/upload', [ReceiptController::class, 'upload'])->name('upload');
        Route::post('/', [ReceiptController::class, 'store'])->middleware('once')->name('store');
        Route::get('/{receipt}', [ReceiptController::class, 'show'])->name('show');
        Route::get('/{receipt}/edit', [ReceiptController::class, 'edit'])->name('edit');
        Route::put('/{receipt}', [ReceiptController::class, 'update'])->name('update');
        Route::post('/{receipt}/files', [ReceiptController::class, 'addFile'])->name('files.add');
        Route::post('/{receipt}/files/{document}/replace', [ReceiptController::class, 'replaceFile'])->name('files.replace');
        Route::post('/{receipt}/{action}', [ReceiptController::class, 'transition'])->where('action', 'verify|process|archive|cancel')->name('transition');
    });

    // Documents (private streaming)
    Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::post('/documents', [DocumentController::class, 'store'])->name('documents.store');
    Route::get('/documents/{document}/view', [DocumentController::class, 'show'])->name('documents.show');
    Route::get('/documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
    Route::get('/templates/{template}/background', [Admin\TemplateController::class, 'background'])->name('templates.background');

    // Printing center
    Route::prefix('printing')->name('printing.')->group(function () {
        Route::get('/', [PrintingController::class, 'index'])->name('index');
        Route::get('/history', [PrintingController::class, 'history'])->name('history');
        Route::get('/templates', [PrintingController::class, 'templates'])->name('templates');
        Route::post('/preview', [PrintingController::class, 'preview'])->name('preview');
        Route::post('/start', [PrintingController::class, 'start'])->middleware('once')->name('start');
        Route::post('/jobs/{job}/confirm', [PrintingController::class, 'confirm'])->name('confirm');
        Route::get('/calibrate/{template}', [PrintingController::class, 'calibrate'])->name('calibrate');
    });

    // Register, reports, activity
    Route::get('/transactions', [RegisterController::class, 'index'])->name('register.index');
    Route::get('/transactions/export', [RegisterController::class, 'export'])->name('register.export');
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/{report}', [ReportController::class, 'show'])->name('reports.show');
    Route::get('/reports/{report}/export', [ReportController::class, 'export'])->name('reports.export');
    Route::get('/activity', [ActivityLogController::class, 'index'])->name('activity.index');

    Route::get('/payees', [PayeeController::class, 'index'])->name('payees.index');
    Route::post('/payees', [PayeeController::class, 'store'])->name('payees.store');
    Route::put('/payees/{payee}', [PayeeController::class, 'update'])->name('payees.update');

    // Administration
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::middleware('can:users.manage')->group(function () {
            Route::get('/users', [Admin\UserController::class, 'index'])->name('users.index');
            Route::post('/users', [Admin\UserController::class, 'store'])->name('users.store');
            Route::get('/users/{user}', [Admin\UserController::class, 'show'])->name('users.show');
            Route::put('/users/{user}', [Admin\UserController::class, 'update'])->name('users.update');
            Route::post('/users/{user}/toggle', [Admin\UserController::class, 'toggle'])->name('users.toggle');
            Route::post('/users/{user}/reset-password', [Admin\UserController::class, 'resetPassword'])->name('users.reset');
            Route::post('/users/{user}/unlock', [Admin\UserController::class, 'unlock'])->name('users.unlock');
            Route::get('/problems', [UtilityController::class, 'problems'])->name('problems.index');
            Route::post('/problems/{problem}/resolve', [UtilityController::class, 'resolveProblem'])->name('problems.resolve');
        });
        Route::middleware('can:roles.manage')->group(function () {
            Route::get('/roles', [Admin\RoleController::class, 'index'])->name('roles.index');
            Route::put('/roles/{role}', [Admin\RoleController::class, 'update'])->name('roles.update');
        });
        Route::middleware('can:banks.manage')->group(function () {
            Route::get('/banks', [Admin\BankController::class, 'index'])->name('banks.index');
            Route::post('/banks', [Admin\BankController::class, 'storeBank'])->name('banks.store');
            Route::put('/banks/{bank}', [Admin\BankController::class, 'updateBank'])->name('banks.update');
            Route::post('/banks/{bank}/accounts', [Admin\BankController::class, 'storeAccount'])->name('accounts.store');
            Route::put('/accounts/{account}', [Admin\BankController::class, 'updateAccount'])->name('accounts.update');
        });
        Route::middleware('can:templates.manage')->group(function () {
            Route::get('/templates', [Admin\TemplateController::class, 'index'])->name('templates.index');
            Route::get('/templates/create', [Admin\TemplateController::class, 'create'])->name('templates.create');
            Route::post('/templates', [Admin\TemplateController::class, 'store'])->name('templates.store');
            Route::get('/templates/{template}/edit', [Admin\TemplateController::class, 'edit'])->name('templates.edit');
            Route::post('/templates/{template}', [Admin\TemplateController::class, 'update'])->name('templates.update'); // POST: multipart background upload
            Route::delete('/templates/{template}', [Admin\TemplateController::class, 'destroy'])->name('templates.destroy');
        });
        Route::middleware('can:settings.manage')->group(function () {
            Route::get('/settings', [Admin\SettingsController::class, 'edit'])->name('settings.edit');
            Route::put('/settings', [Admin\SettingsController::class, 'update'])->name('settings.update');
            Route::post('/categories', [Admin\SettingsController::class, 'storeCategory'])->name('categories.store');
            Route::put('/categories/{category}', [Admin\SettingsController::class, 'updateCategory'])->name('categories.update');
        });
    });
});

require __DIR__.'/auth.php';
