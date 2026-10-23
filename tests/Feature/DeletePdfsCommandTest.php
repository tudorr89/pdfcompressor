<?php

namespace Tests\Feature;

use App\Jobs\DeletePdfsJob;
use App\Models\PdfDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DeletePdfsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_only_queues_expired_finished_documents(): void
    {
        Queue::fake();

        $expiredCompleted = PdfDocument::factory()->completed()->create();
        $expiredCompleted->forceFill(['created_at' => now()->subDays(2)])->save();

        $expiredFailed = PdfDocument::factory()->create(['status' => 'failed']);
        $expiredFailed->forceFill(['created_at' => now()->subDays(2)])->save();

        // Still in flight: must not be deleted even when old.
        $expiredPending = PdfDocument::factory()->create(['status' => 'pending']);
        $expiredPending->forceFill(['created_at' => now()->subDays(2)])->save();

        $freshCompleted = PdfDocument::factory()->completed()->create();

        $this->artisan('pdfs:delete')
            ->expectsOutput('Queued 2 expired document(s) for deletion.')
            ->assertSuccessful();

        Queue::assertPushed(DeletePdfsJob::class, 2);
        Queue::assertPushed(DeletePdfsJob::class, fn ($job) => $job->pdfDocument->is($expiredCompleted));
        Queue::assertPushed(DeletePdfsJob::class, fn ($job) => $job->pdfDocument->is($expiredFailed));
        Queue::assertNotPushed(DeletePdfsJob::class, fn ($job) => $job->pdfDocument->is($expiredPending));
        Queue::assertNotPushed(DeletePdfsJob::class, fn ($job) => $job->pdfDocument->is($freshCompleted));
    }

    public function test_the_retention_window_is_configurable(): void
    {
        Queue::fake();

        $document = PdfDocument::factory()->completed()->create();
        $document->forceFill(['created_at' => now()->subHours(3)])->save();

        $this->artisan('pdfs:delete', ['--hours' => 1])->assertSuccessful();
        Queue::assertPushed(DeletePdfsJob::class, 1);

        Queue::fake();

        $document->forceFill(['created_at' => now()->subMinutes(30)])->save();

        $this->artisan('pdfs:delete', ['--hours' => 1])->assertSuccessful();
        Queue::assertNotPushed(DeletePdfsJob::class);
    }
}
