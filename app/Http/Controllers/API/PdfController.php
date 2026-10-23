<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\CompressPdfJob;
use App\Models\PdfDocument;
use App\Services\BytesConversionService;
use App\Services\PdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PdfController extends Controller
{
    public function __construct(private readonly PdfService $pdfService) {}

    /**
     * Upload a PDF for compression.
     */
    public function upload(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            // Laravel's `max` is in kilobytes: 512000 KB = 500 MiB, matching
            // php.ini (upload_max_filesize=500M, post_max_size=512M).
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:512000'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        try {
            $document = DB::transaction(
                fn () => $this->pdfService->storeOriginalPdf($request->file('pdf'))
            );

            // Virus scanning and compression both run in the queue: a large
            // PDF cannot be scanned within the HTTP request execution limit.
            CompressPdfJob::dispatch($document);

            return response()->json([
                'message' => 'PDF uploaded successfully and queued for compression',
                'document_id' => $document->id,
                'status' => $document->status,
            ], 202);
        } catch (Throwable $e) {
            Log::error('PDF upload failed', ['exception' => $e]);

            return response()->json(['error' => 'Failed to upload PDF'], 500);
        }
    }

    /**
     * Get PDF compression status.
     */
    public function status(string $documentId): JsonResponse
    {
        $document = $this->pdfService->getDocumentDetails($documentId);

        if (! $document) {
            return response()->json(['error' => 'Document not found'], 404);
        }

        $response = [
            'id' => $document->id,
            'original_filename' => $document->original_filename,
            'original_size' => BytesConversionService::filesize($document->original_size),
            'status' => $document->status,
        ];

        if ($document->status === 'completed') {
            $response['compressed_size'] = BytesConversionService::filesize($document->compressed_size);
            // A negative ratio just means the output grew; report 0% instead.
            $response['compression_ratio'] = max(0, (float) $document->compression_ratio).'%';
            $response['download_url'] = $this->pdfService->getDownloadUrl($document);
        } elseif ($document->status === 'failed') {
            $response['error'] = $document->error_message;
        }

        return response()->json($response);
    }

    /**
     * Download the original or compressed PDF.
     */
    public function download(string $documentId, string $type = 'compressed'): JsonResponse|StreamedResponse
    {
        if (! in_array($type, ['original', 'compressed'], true)) {
            return response()->json(['error' => 'Invalid file type'], 422);
        }

        $document = PdfDocument::find($documentId);

        if (! $document) {
            return response()->json(['error' => 'Document not found'], 404);
        }

        if ($type === 'compressed' && $document->status !== 'completed') {
            return response()->json(['error' => 'Compressed PDF is not ready yet'], 404);
        }

        $path = $type === 'original' ? $document->original_path : $document->compressed_path;
        $disk = Storage::disk('local');

        if (! $path || ! $disk->exists($path)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        $filename = $type === 'original'
            ? $this->safeFilename($document->original_filename)
            : $this->safeFilename(pathinfo($document->original_filename, PATHINFO_FILENAME)).'_compressed.pdf';

        return $disk->download($path, $filename);
    }

    /**
     * The uploaded filename is client-controlled; strip characters that could
     * break the Content-Disposition header.
     */
    private function safeFilename(string $name): string
    {
        $safe = Str::of(basename($name))
            ->replace(["\r", "\n", '"'], '')
            ->limit(180, '')
            ->toString();

        return $safe !== '' ? $safe : 'document.pdf';
    }
}
