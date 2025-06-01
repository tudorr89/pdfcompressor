<?php

use App\Http\Controllers\API\PdfController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/pdfs/upload', [PdfController::class, 'upload']);
Route::get('/pdfs/{id}/status', [PdfController::class, 'status']);
Route::get('/pdfs/download/{id}/{type?}', [PdfController::class, 'download'])->name('pdfs.download');
Route::get('/pdfs', [PdfController::class, 'index']);
