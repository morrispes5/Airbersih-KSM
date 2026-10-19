<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DocumentsController;
use App\Livewire\Workspace;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/app/dashboard'));
Route::get('/login', [AuthController::class, 'form'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/app/{page?}', Workspace::class)->name('workspace');
    Route::get('/documents/invoices/{invoice}', [DocumentsController::class, 'invoice'])->name('invoice.preview');
    Route::get('/documents/attachments/{attachment}', [DocumentsController::class, 'attachment'])->name('attachment');
    Route::get('/documents/report', [DocumentsController::class, 'report'])->name('report.export');
    Route::get('/documents/sources/{batch}', [DocumentsController::class, 'source'])->name('source.download');
});
