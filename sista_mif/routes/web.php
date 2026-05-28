<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\InternalDataController;
use App\Http\Controllers\KemendikDataController;
use App\Http\Controllers\ClassificationTaskController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () { return view('auth.login'); });

Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', [InternalDataController::class, 'index'])->name('dashboard');
    Route::get('/kemendik', [KemendikDataController::class, 'index'])->name('kemendik');
    Route::post('/upload', [ClassificationTaskController::class, 'upload'])->name('upload.process');
    Route::get('/upload/status/{job_id}', [ClassificationTaskController::class, 'checkStatus'])->name('upload.status');
    
    Route::middleware('admin')->group(function () {
        Route::put('/internal/{id}', [InternalDataController::class, 'update'])->name('internal.update');
        Route::delete('/internal/{id}', [InternalDataController::class, 'destroy'])->name('internal.destroy');
        Route::post('/internal/bulk-delete', [InternalDataController::class, 'bulkDestroy'])->name('internal.bulk_destroy');
        Route::post('/internal/bulk-update', [InternalDataController::class, 'bulkUpdate'])->name('internal.bulk_update');
        Route::get('/internal/download-pdf', [InternalDataController::class, 'exportPdf'])->name('internal.download_pdf');
        Route::post('/retrain', [ClassificationTaskController::class, 'retrain'])->name('retrain.trigger');
        Route::get('/retrain/status', [ClassificationTaskController::class, 'retrainStatus'])->name('retrain.status');
    });

    Route::prefix('profile')->group(function () {
        Route::get('/', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/', [ProfileController::class, 'update'])->name('profile.update');
        Route::delete('/', [ProfileController::class, 'destroy'])->name('profile.destroy');
    });
});

require __DIR__.'/auth.php';