<?php

namespace App\Services;

use App\Models\PdfDocument;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Process;

class PdfService
{
    /**
     * Store original PDF file
     */
    public function storeOriginalPdf($file)
    {
        $originalFilename = $file->getClientOriginalName();
        $originalSize = $file->getSize();

        // Generate a unique filename
        $filename = time() . '_' . uniqid('', true) . '.pdf';
        $path = $file->storeAs('pdfs/original', $filename, 'local');

        // Create PDF document record
        return PdfDocument::create([
            'original_filename' => $originalFilename,
            'original_path' => $path,
            'original_size' => $originalSize,
            'status' => 'pending',
        ]);
    }

    /**
     * Compress PDF file using Ghostscript
     */
    public function compressPdf(PdfDocument $document, string $settings = null)
    {
        try {
            $document->update(['status' => 'processing']);

            $originalPath = storage_path('app/private/' . $document->original_path);
            $compressedFilename = pathinfo($document->original_path, PATHINFO_FILENAME) . '_compressed.pdf';
            $compressedPath = 'pdfs/compressed/' . $compressedFilename;
            $fullCompressedPath = storage_path('app/private/' . $compressedPath);

            // Ensure the directory exists with proper permissions
            $compressedDir = dirname($fullCompressedPath);
            if (!file_exists($compressedDir)) {
                if (!mkdir($compressedDir, 0777, true) && !is_dir($compressedDir)) {
                    throw new \RuntimeException(sprintf('Directory "%s" was not created', $compressedDir));
                }
            }
            chmod($compressedDir, 0777);

            // Make sure the original file is readable
            if (file_exists($originalPath)) {
                chmod($originalPath, 0644);
            }

            // Run Ghostscript command for PDF compression
            $baseParams = [
                'gs',
                '-sDEVICE=pdfwrite',
                '-dCompatibilityLevel=1.4',
                '-dNOPAUSE',
                '-dQUIET',
                '-dBATCH',
            ];

            // Apply AI recommendations
            $params = array_merge($baseParams, $settings['custom_params']);

            // Add dynamic parameters based on analysis
            if ($settings['image_downsample']) {
                $params[] = '-dDownsampleColorImages=true';
                $params[] = '-dColorImageResolution=150';
            }

            if ($settings['color_convert'] === 'gray') {
                $params[] = '-sColorConversionStrategy=Gray';
            }

            $command = implode(' ', $params);
            $process = Process::run('gs '.$settings.' -sOutputFile='.$fullCompressedPath.' '.$originalPath);

            if (!$process->successful()) {
                throw new \Exception('PDF compression failed: ' . $process->errorOutput());
            }

            $compressedSize = Storage::size($compressedPath);
            $compressionRatio = ($document->original_size > 0)
                ? round(($document->original_size - $compressedSize) / $document->original_size * 100, 2)
                : 0;

            // Update document record
            $document->update([
                'compressed_path' => $compressedPath,
                'compressed_size' => $compressedSize,
                'compression_ratio' => $compressionRatio,
                'status' => 'completed',
            ]);

            return $document;
        } catch (\Exception $e) {
            Log::error('PDF compression error: ' . $e->getMessage());

            $document->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get PDF document details
     */
    public function getDocumentDetails($id)
    {
        return PdfDocument::findOrFail($id);
    }

    /**
     * Get download URL for the PDF
     */
    public function getDownloadUrl(PdfDocument $document, $type = 'compressed')
    {
        $path = ($type === 'original') ? $document->original_path : $document->compressed_path;

        if (!$path) {
            throw new \Exception("Requested file doesn't exist");
        }

        // Generate URL
        return url('api/v1/pdfs/download/' . $document->id . '/' . $type);
    }
    /**
     * Scan PDF for viruses
     */
    public function scanPdfForViruses($file)
    {
        $request = Http::attach('file', $file->getContent(), $file->getClientOriginalName())->post(config('av.api_url'));

        $response = $request->json();

        return $response['status']==='clean' ?? false;
    }
}
