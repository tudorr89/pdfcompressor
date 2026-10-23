<?php

namespace App\Services;

use App\Models\PdfDocument;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class PdfService
{
    private const DISK = 'local';

    /**
     * Store the uploaded PDF on the private disk.
     */
    public function storeOriginalPdf(UploadedFile $file): PdfDocument
    {
        $filename = Str::uuid()->toString().'.pdf';
        $path = $file->storeAs('pdfs/original', $filename, self::DISK);

        if ($path === false) {
            throw new RuntimeException('Could not store the uploaded PDF');
        }

        return PdfDocument::create([
            'original_filename' => $file->getClientOriginalName(),
            'original_path' => $path,
            'original_size' => $file->getSize(),
            'status' => 'pending',
        ]);
    }

    /**
     * Compress a stored PDF with Ghostscript.
     *
     * @throws RuntimeException when the source is missing or Ghostscript fails
     */
    public function compressPdf(PdfDocument $document): PdfDocument
    {
        $disk = Storage::disk(self::DISK);

        $originalPath = $disk->path($document->original_path);
        $compressedPath = 'pdfs/compressed/'.pathinfo($document->original_path, PATHINFO_FILENAME).'_compressed.pdf';
        $compressedFile = $disk->path($compressedPath);

        if (! is_file($originalPath)) {
            throw new RuntimeException('Source PDF is missing: '.$document->original_path);
        }

        $document->update(['status' => 'processing']);

        File::ensureDirectoryExists(dirname($compressedFile), 0755);

        // Array arguments (no shell) so paths with spaces/quotes can never be
        // reinterpreted and no escaping is required.
        $recompress = (bool) config('processor.recompress_images');
        $resolution = (int) config('processor.image_resolution');

        $arguments = [
            config('processor.gs_binary'),
            '-sDEVICE=pdfwrite',
            '-dCompatibilityLevel=1.5',
            '-dSAFER',
            '-dNOPAUSE',
            '-dQUIET',
            '-dBATCH',
            '-dPDFSETTINGS='.config('processor.pdf_settings'),

            // Images: downsample to the target dpi, force JPEG for colour and
            // grey (smallest), CCITT for 1-bit, and re-encode embedded
            // JPEG/JPEG2000 data instead of copying it through untouched.
            '-dDownsampleColorImages=true',
            '-dDownsampleGrayImages=true',
            '-dDownsampleMonoImages=true',
            '-dColorImageDownsampleType=/Bicubic',
            '-dGrayImageDownsampleType=/Bicubic',
            '-dColorImageDownsampleThreshold=1.0',
            '-dGrayImageDownsampleThreshold=1.0',
            '-dMonoImageDownsampleThreshold=1.0',
            '-dAutoFilterColorImages=false',
            '-dColorImageFilter=/DCTEncode',
            '-dAutoFilterGrayImages=false',
            '-dGrayImageFilter=/DCTEncode',
            '-dEncodeMonoImages=true',
            '-dMonoImageFilter=/CCITTFaxEncode',
            '-dPassThroughJPEGImages='.($recompress ? 'false' : 'true'),
            '-dPassThroughJPXImages='.($recompress ? 'false' : 'true'),

            // Structure: one copy of duplicated images, subsetted fonts, no
            // optional timestamps/IDs/XMP metadata.
            '-dDetectDuplicateImages=true',
            '-dCompressFonts=true',
            '-dSubsetFonts=true',
            '-dEmbedAllFonts=true',
            '-dPreserveAnnots=true',
            '-dOmitInfoDate=true',
            '-dOmitID=true',
            '-dOmitXMP=true',
        ];

        if ($resolution > 0) {
            $arguments[] = '-dColorImageResolution='.$resolution;
            $arguments[] = '-dGrayImageResolution='.$resolution;
        }

        $arguments[] = '-sOutputFile='.$compressedFile;
        $arguments[] = $originalPath;

        $process = Process::timeout(config('processor.gs_timeout'))->run($arguments);

        $compressedSize = is_file($compressedFile) ? filesize($compressedFile) : 0;

        if (! $process->successful() || $compressedSize === 0) {
            // Never leave a partial/empty file behind for a retry to reuse.
            File::delete($compressedFile);

            throw new RuntimeException(
                'Ghostscript failed: '.trim($process->errorOutput() ?: $process->output() ?: 'empty output')
            );
        }

        $originalSize = (int) $document->original_size;
        $compressionRatio = $originalSize > 0
            ? round(($originalSize - $compressedSize) / $originalSize * 100, 2)
            : 0.0;

        $document->update([
            'compressed_path' => $compressedPath,
            'compressed_size' => $compressedSize,
            'compression_ratio' => $compressionRatio,
            'status' => 'completed',
            'error_message' => null,
        ]);

        return $document;
    }

    public function getDocumentDetails(string $documentId): ?PdfDocument
    {
        return PdfDocument::find($documentId);
    }

    public function getDownloadUrl(PdfDocument $document, string $type = 'compressed'): string
    {
        $path = $type === 'original' ? $document->original_path : $document->compressed_path;

        if (! $path) {
            throw new RuntimeException("Requested file doesn't exist");
        }

        return route('pdfs.download', ['id' => $document->id, 'type' => $type]);
    }

    /**
     * Stream the file to the ClamAV API.
     *
     * @return bool true when clean, false when a virus was found
     *
     * @throws RuntimeException when the scanner cannot be reached or answers unexpectedly
     */
    public function scanPdfForViruses(string $filePath, ?string $filename = null): bool
    {
        if (! is_file($filePath)) {
            throw new RuntimeException('Could not open the PDF for scanning');
        }

        $handle = fopen($filePath, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Could not open the PDF for scanning');
        }

        try {
            // Stream the file instead of reading it into memory: uploads can be
            // several hundred MB and would otherwise exhaust the worker.
            $response = Http::connectTimeout(config('av.connect_timeout'))
                ->timeout(config('av.timeout'))
                ->acceptJson()
                ->attach('file', $handle, $filename ?: basename($filePath))
                ->post(config('av.api_url'));
        } catch (ConnectionException $e) {
            throw new RuntimeException('Virus scanner is unreachable', 0, $e);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        if (! $response->successful()) {
            throw new RuntimeException('Virus scanner returned HTTP '.$response->status());
        }

        return match ($response->json('status')) {
            'clean' => true,
            'infected' => false,
            default => throw new RuntimeException('Virus scanner returned an unexpected response'),
        };
    }
}
