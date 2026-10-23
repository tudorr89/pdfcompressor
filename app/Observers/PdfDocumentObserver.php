<?php

namespace App\Observers;

use App\Models\PdfDocument;
use Illuminate\Support\Facades\Storage;

class PdfDocumentObserver
{
    /**
     * Handle the PdfDocument "deleted" event.
     */
    public function deleted(PdfDocument $pdfDocument): void
    {
        if($pdfDocument->original_path && Storage::disk('local')->exists($pdfDocument->original_path)) {
            Storage::disk('local')->delete($pdfDocument->original_path);
        }

        if($pdfDocument->compressed_path && Storage::disk('local')->exists($pdfDocument->compressed_path)) {
            Storage::disk('local')->delete($pdfDocument->compressed_path);
        }
    }

    /**
     * Handle the PdfDocument "force deleted" event.
     */
    public function forceDeleted(PdfDocument $pdfDocument): void
    {
        // The "deleted" event is enough; force deletes must clean up as well.
        $this->deleted($pdfDocument);
    }
}
