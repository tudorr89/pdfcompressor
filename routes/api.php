<?php

use App\Http\Controllers\API\PdfController;
use Illuminate\Support\Facades\Route;

Route::post('/pdfs/upload', [PdfController::class, 'upload'])->name('pdfs.upload');
Route::get('/pdfs/{id}/status', [PdfController::class, 'status'])->name('pdfs.status');
Route::get('/pdfs/download/{id}/{type?}', [PdfController::class, 'download'])->name('pdfs.download');
