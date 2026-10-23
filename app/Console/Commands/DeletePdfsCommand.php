<?php

namespace App\Console\Commands;

use App\Jobs\DeletePdfsJob;
use App\Models\PdfDocument;
use Illuminate\Console\Command;

class DeletePdfsCommand extends Command
{
    protected $signature = 'pdfs:delete {--hours=24 : Retention window in hours}';

    protected $description = 'Delete processed PDF documents and their files after the retention window';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $deleted = 0;

        // Only finished documents: deleting rows that are still pending or
        // processing would break in-flight uploads and leave orphaned files.
        PdfDocument::query()
            ->whereIn('status', ['completed', 'failed'])
            ->where('created_at', '<=', now()->subHours($hours))
            ->chunkById(200, function ($documents) use (&$deleted) {
                foreach ($documents as $document) {
                    DeletePdfsJob::dispatch($document);
                    $deleted++;
                }
            });

        $this->info("Queued {$deleted} expired document(s) for deletion.");

        return self::SUCCESS;
    }
}
