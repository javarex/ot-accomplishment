<?php

use App\Http\Controllers\AccessControlController;
use App\Http\Controllers\AccomplishmentAiController;
use App\Http\Controllers\AccomplishmentReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DtrImportController;
use App\Http\Controllers\ReportGenerationController;
use App\Http\Controllers\ReportTemplateController;
use App\Http\Controllers\SignatoryController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserImpersonationController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::resource('users', UserController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('users/{user}/restore', [UserController::class, 'restore'])->name('users.restore');
    Route::post('users/{user}/impersonate', [UserImpersonationController::class, 'store'])->name('impersonation.store');
    Route::delete('impersonation', [UserImpersonationController::class, 'destroy'])->name('impersonation.destroy');
    Route::get('access-control', [AccessControlController::class, 'index'])->name('access-control.index');
    Route::post('access-control/roles', [AccessControlController::class, 'storeRole'])->name('access-control.roles.store');
    Route::put('access-control/roles/{role}', [AccessControlController::class, 'updateRole'])->name('access-control.roles.update');
    Route::delete('access-control/roles/{role}', [AccessControlController::class, 'destroyRole'])->name('access-control.roles.destroy');
    Route::put('access-control/users/{user}/roles', [AccessControlController::class, 'updateUserRoles'])->name('access-control.users.roles.update');
    Route::resource('reports', AccomplishmentReportController::class);
    Route::put('reports/{report}/hourly-rate', [AccomplishmentReportController::class, 'updateHourlyRate'])->name('reports.hourly-rate.update');
    Route::get('reports/{report}/preview', [ReportGenerationController::class, 'preview'])->name('reports.preview');
    Route::post('reports/{report}/generate', [ReportGenerationController::class, 'generate'])->name('reports.generate');
    Route::post('reports/{report}/generate-docx', [ReportGenerationController::class, 'generateDocx'])->name('reports.generate-docx');
    Route::post('reports/{report}/ai-improve', AccomplishmentAiController::class)->middleware('throttle:10,1')->name('reports.ai.improve');
    Route::post('reports/{report}/dtr', [DtrImportController::class, 'store'])->name('reports.dtr.store');
    Route::get('reports/{report}/dtr/{dtrImport}', [DtrImportController::class, 'show'])->name('reports.dtr.show');
    Route::post('reports/{report}/dtr/{dtrImport}/import', [DtrImportController::class, 'commit'])->name('reports.dtr.commit');
    Route::resource('signatories', SignatoryController::class)->except(['create', 'edit', 'show']);
    Route::get('report-template', [ReportTemplateController::class, 'edit'])->name('report-template.edit');
    Route::post('report-template', [ReportTemplateController::class, 'update'])->name('report-template.update');
});

require __DIR__.'/settings.php';
