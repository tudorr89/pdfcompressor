<?php

namespace Tests\Feature;

use App\Jobs\CompressPdfJob;
use App\Models\PdfDocument;
use App\Services\PdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class CompressPdfJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['av.enabled' => false]);
    }

    public function test_it_compresses_the_pdf_and_marks_the_document_completed(): void
    {
        $this->requireGhostscript();

        $document = $this->documentWithFixture();

        (new CompressPdfJob($document))->handle(app(PdfService::class));

        $document->refresh();

        $this->assertSame('completed', $document->status);
        $this->assertNotNull($document->compressed_path);
        $this->assertGreaterThan(0, $document->compressed_size);
        $this->assertIsFloat($document->compression_ratio);
        Storage::disk('local')->assertExists($document->compressed_path);
    }

    public function test_it_fails_with_a_generic_message_when_the_source_file_is_missing(): void
    {
        $document = PdfDocument::factory()->create([
            'original_path' => 'pdfs/original/missing.pdf',
        ]);

        $job = new CompressPdfJob($document);

        try {
            $job->handle(app(PdfService::class));
            $this->fail('Expected the job to throw for a missing source file.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Source PDF is missing', $e->getMessage());
        }

        $job->failed(new RuntimeException('Source PDF is missing'));

        $document->refresh();
        $this->assertSame('failed', $document->status);
        $this->assertSame('Compression failed', $document->error_message);
        $this->assertNull($document->compressed_path);
    }

    public function test_it_deletes_the_file_and_fails_the_document_when_a_virus_is_found(): void
    {
        config(['av.enabled' => true]);

        Http::fake([
            config('av.api_url') => Http::response(['status' => 'infected', 'virus' => 'Eicar-Test-Signature'], 200),
        ]);

        $document = $this->documentWithFixture();

        (new CompressPdfJob($document))->handle(app(PdfService::class));

        $document->refresh();

        $this->assertSame('failed', $document->status);
        $this->assertSame('Virus detected', $document->error_message);
        $this->assertNull($document->compressed_path);
        Storage::disk('local')->assertMissing($document->original_path);
    }

    public function test_it_keeps_the_document_pending_when_the_scanner_is_unavailable(): void
    {
        config(['av.enabled' => true]);

        Http::fake([
            config('av.api_url') => Http::response(['status' => 'error'], 503),
        ]);

        $document = $this->documentWithFixture();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Virus scanner returned HTTP 503');

        try {
            (new CompressPdfJob($document))->handle(app(PdfService::class));
        } finally {
            $document->refresh();
            $this->assertSame('pending', $document->status);
        }
    }

    public function test_it_scans_and_then_compresses_a_clean_file(): void
    {
        $this->requireGhostscript();
        config(['av.enabled' => true]);

        Http::fake([
            config('av.api_url') => Http::response(['status' => 'clean'], 200),
        ]);

        $document = $this->documentWithFixture();

        (new CompressPdfJob($document))->handle(app(PdfService::class));

        $document->refresh();

        $this->assertSame('completed', $document->status);
        Storage::disk('local')->assertExists($document->original_path);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/scan'));
    }

    private function documentWithFixture(): PdfDocument
    {
        $path = 'pdfs/original/'.Str::uuid().'.pdf';
        Storage::disk('local')->put($path, (string) file_get_contents(base_path('tests/Fixtures/test.pdf')));

        return PdfDocument::factory()->create([
            'original_path' => $path,
            'original_filename' => 'test.pdf',
            'original_size' => Storage::disk('local')->size($path),
        ]);
    }

    private function requireGhostscript(): void
    {
        if (! Process::run([config('processor.gs_binary'), '--version'])->successful()) {
            $this->markTestSkipped('Ghostscript is not installed.');
        }
    }
}
