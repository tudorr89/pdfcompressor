<?php

namespace App\Jobs;

use App\Models\PdfDocument;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class DeletePdfsJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public PdfDocument $pdfDocument)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->pdfDocument->delete();
    }
}
