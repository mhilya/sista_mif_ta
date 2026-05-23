<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ClassificationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () { return view('auth.login'); });

Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', [ClassificationController::class, 'dashboard'])->name('dashboard');
    Route::get('/kemendik', [ClassificationController::class, 'kemendik'])->name('kemendik');
    Route::post('/upload', [ClassificationController::class, 'upload'])->name('upload.process');
    Route::put('/internal/{id}', [ClassificationController::class, 'update'])->name('internal.update');
    Route::delete('/internal/{id}', [ClassificationController::class, 'destroy'])->name('internal.destroy');
    Route::post('/internal/bulk-delete', [ClassificationController::class, 'bulkDestroy'])->name('internal.bulk_destroy');
    Route::post('/internal/bulk-update', [ClassificationController::class, 'bulkUpdate'])->name('internal.bulk_update');
    Route::get('/internal/download-pdf', [ClassificationController::class, 'downloadPdf'])->name('internal.download_pdf');
    Route::post('/retrain', [ClassificationController::class, 'retrain'])->name('retrain.trigger');
    Route::get('/retrain/status', [ClassificationController::class, 'retrainStatus'])->name('retrain.status');


    Route::prefix('profile')->group(function () {
        Route::get('/', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/', [ProfileController::class, 'update'])->name('profile.update');
        Route::delete('/', [ProfileController::class, 'destroy'])->name('profile.destroy');
    });
});

require __DIR__.'/auth.php';