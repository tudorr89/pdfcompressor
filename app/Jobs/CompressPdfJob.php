<?php

namespace App\Jobs;

use App\Models\PdfDocument;
use App\Services\PdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CompressPdfJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * Ghostscript may need minutes for very large PDFs. Keep this below the
     * Horizon worker timeout (960s) and Redis retry_after (1020s).
     */
    public int $timeout = 900;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120, 300];

    public function __construct(public PdfDocument $document) {}

    public function uniqueId(): string
    {
        return (string) $this->document->id;
    }

    public function handle(PdfService $pdfService): void
    {
        if (config('av.enabled')) {
            $isClean = $pdfService->scanPdfForViruses(
                Storage::disk('local')->path($this->document->original_path),
                $this->document->original_filename,
            );

            if (! $isClean) {
                Log::warning('Uploaded PDF rejected by the virus scanner', [
                    'document' => $this->document->id,
                ]);

                Storage::disk('local')->delete($this->document->original_path);

                $this->document->update([
                    'status' => 'failed',
                    'error_message' => 'Virus detected',
                ]);

                return;
            }
        }

        $pdfService->compressPdf($this->document);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('PDF compression job failed permanently', [
            'document' => $this->document->id,
            'exception' => $exception,
        ]);

        $this->document->update([
            'status' => 'failed',
            'error_message' => 'Compression failed',
        ]);
    }
}
