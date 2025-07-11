<?php

namespace App\Jobs;

use App\Models\PdfDocument;
use App\Services\PdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CompressPdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $document;
    public $tries = 3;
    public $timeout = 300; // 5 minutes

    public function __construct(PdfDocument $document)
    {
        $this->document = $document;
    }

    public function handle(PdfService $pdfService)
    {
        try {
            //Log::info('Starting PDF compression for document ID: ' . $this->document->id);
            $pdfService->compressPdf($this->document);
            //Log::info('PDF compression completed for document ID: ' . $this->document->id);
        } catch (\Exception $e) {
            Log::error('PDF compression job failed: ' . $e->getMessage());

            if ($this->attempts() >= $this->tries) {
                $this->document->update([
                    'status' => 'failed',
                    'error_message' => 'Max retries exceeded: ' . $e->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    public function failed(\Exception $exception)
    {
        Log::error('PDF compression job failed permanently: ' . $exception->getMessage());

        $this->document->update([
            'status' => 'failed',
            'error_message' => 'Job failed: ' . $exception->getMessage(),
        ]);
    }
}
