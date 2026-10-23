<?php

namespace Tests\Feature;

use App\Jobs\CompressPdfJob;
use App\Models\PdfDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PdfApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();
    }

    public function test_upload_stores_the_pdf_and_dispatches_the_compression_job(): void
    {
        $response = $this->post('/api/v1/pdfs/upload', ['pdf' => $this->pdfUpload()]);

        $response->assertStatus(202)
            ->assertJsonStructure(['message', 'document_id', 'status'])
            ->assertJsonPath('status', 'pending');

        $document = PdfDocument::sole();

        $this->assertSame('pending', $document->status);
        $this->assertSame('Invoice 2026.pdf', $document->original_filename);
        Storage::disk('local')->assertExists($document->original_path);
        Queue::assertPushed(CompressPdfJob::class, fn (CompressPdfJob $job) => $job->document->is($document));
    }

    public function test_upload_rejects_non_pdf_files(): void
    {
        $file = UploadedFile::fake()->createWithContent('notes.txt', 'not a pdf');

        $this->post('/api/v1/pdfs/upload', ['pdf' => $file])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['pdf']]);

        $this->assertDatabaseCount('pdf_documents', 0);
    }

    public function test_status_returns_404_for_unknown_documents(): void
    {
        $this->getJson('/api/v1/pdfs/'.Str::uuid().'/status')
            ->assertStatus(404)
            ->assertJsonPath('error', 'Document not found');
    }

    public function test_status_reports_a_completed_document(): void
    {
        $document = PdfDocument::factory()->completed()->create();

        $this->getJson("/api/v1/pdfs/{$document->id}/status")
            ->assertStatus(200)
            ->assertJsonPath('id', $document->id)
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('compression_ratio', '50%')
            ->assertJsonPath(
                'download_url',
                route('pdfs.download', ['id' => $document->id, 'type' => 'compressed'])
            );
    }

    public function test_status_never_reports_a_negative_compression_ratio(): void
    {
        $document = PdfDocument::factory()->completed()->create(['compression_ratio' => -12.5]);

        $this->getJson("/api/v1/pdfs/{$document->id}/status")
            ->assertStatus(200)
            ->assertJsonPath('compression_ratio', '0%');
    }

    public function test_status_reports_the_error_of_a_failed_document(): void
    {
        $document = PdfDocument::factory()->create([
            'status' => 'failed',
            'error_message' => 'Virus detected',
        ]);

        $this->getJson("/api/v1/pdfs/{$document->id}/status")
            ->assertStatus(200)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('error', 'Virus detected');
    }

    public function test_download_returns_the_compressed_file(): void
    {
        $document = PdfDocument::factory()->completed()->create();
        Storage::disk('local')->put($document->compressed_path, '%PDF-1.4 compressed');

        $response = $this->get("/api/v1/pdfs/download/{$document->id}/compressed");

        $response->assertStatus(200);
        $this->assertSame('%PDF-1.4 compressed', $response->streamedContent());
        $this->assertStringContainsString('_compressed.pdf', (string) $response->headers->get('content-disposition'));
    }

    public function test_download_returns_404_when_compression_is_not_finished(): void
    {
        $document = PdfDocument::factory()->create(['status' => 'processing']);

        $this->getJson("/api/v1/pdfs/download/{$document->id}/compressed")
            ->assertStatus(404)
            ->assertJsonPath('error', 'Compressed PDF is not ready yet');
    }

    public function test_download_rejects_an_invalid_type(): void
    {
        $document = PdfDocument::factory()->completed()->create();

        $this->getJson("/api/v1/pdfs/download/{$document->id}/bogus")
            ->assertStatus(422)
            ->assertJsonPath('error', 'Invalid file type');
    }

    public function test_download_sanitizes_the_client_supplied_filename(): void
    {
        $document = PdfDocument::factory()->create([
            'original_filename' => "evil\"\r\nX-Injected: 1.pdf",
        ]);
        Storage::disk('local')->put($document->original_path, '%PDF-1.4 original');

        $response = $this->get("/api/v1/pdfs/download/{$document->id}/original");

        $response->assertStatus(200);
        $disposition = (string) $response->headers->get('content-disposition');
        // No CR/LF from the client filename may reach the header.
        $this->assertStringNotContainsString("\r", $disposition);
        $this->assertStringNotContainsString("\n", $disposition);
        $this->assertStringContainsString('evil', $disposition);
    }

    private function pdfUpload(string $name = 'Invoice 2026.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            (string) file_get_contents(base_path('tests/Fixtures/test.pdf'))
        );
    }
}
