<?php

namespace App\Console\Commands;

use App\Jobs\DeletePdfsJob;
use App\Models\PdfDocument;
use Illuminate\Console\Command;

class DeletePdfsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pdfs:delete';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $pdfs = PdfDocument::where('created_at', '<=', now()->subMinute())->get();

        foreach ($pdfs as $pdf) {
            dispatch(new DeletePdfsJob($pdf));
        }
    }
}
