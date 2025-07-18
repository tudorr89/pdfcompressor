<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\BytesConversionService;
use Illuminate\Http\Request;
use App\Jobs\CompressPdfJob;
use App\Models\PdfDocument;
use App\Services\PdfService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class PdfController extends Controller
{
    protected $pdfService;

    public function __construct(PdfService $pdfService)
    {
        $this->pdfService = $pdfService;
    }

    /**
     * Upload a PDF for compression
     */
    public function upload(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'pdf' => 'required|file|mimes:pdf|max:502400', // Max 500MB
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        try {
            $file = $request->file('pdf');
            if(config('av.enabled')) {
                // Scan PDF for viruses
                $scanResult = $this->pdfService->scanPdfForViruses($file);
                if (!$scanResult) {
                    return response()->json(['error' => 'PDF contains a virus'], 400);
                }
            }
            $document = $this->pdfService->storeOriginalPdf($file);

            // Dispatch compression job
            CompressPdfJob::dispatch($document);

            return response()->json([
                'message' => 'PDF uploaded successfully and queued for compression',
                'document_id' => $document->id,
                'status' => $document->status,
            ], 202);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to upload PDF: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get PDF compression status
     */
    public function status($id)
    {
        try {
            $document = $this->pdfService->getDocumentDetails($id);

            $response = [
                'id' => $document->id,
                'original_filename' => $document->original_filename,
                'original_size' => BytesConversionService::filesize($document->original_size),
                'status' => $document->status,
            ];

            if ($document->status === 'completed') {
                $response['compressed_size'] = BytesConversionService::filesize($document->compressed_size);
                $response['compression_ratio'] = $document->compression_ratio . '%';
                $response['download_url'] = $this->pdfService->getDownloadUrl($document);
            } elseif ($document->status === 'failed') {
                $response['error'] = $document->error_message;
            }

            return response()->json($response);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to get status: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Download the PDF file
     */
    public function download($id, $type = 'compressed')
    {
        try {
            $document = PdfDocument::findOrFail($id);

            if ($type === 'original') {
                $path = $document->original_path;
                $filename = $document->original_filename;
            } else {
                if ($document->status !== 'completed') {
                    return response()->json(['error' => 'Compressed PDF is not ready yet'], 404);
                }

                $path = $document->compressed_path;
                $filename = pathinfo($document->original_filename, PATHINFO_FILENAME) . '_compressed.pdf';
            }

            if (!Storage::exists($path)) {
                return response()->json(['error' => 'File not found'], 404);
            }

            return Storage::download($path, $filename);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to download file: ' . $e->getMessage()], 500);
        }
    }
}
