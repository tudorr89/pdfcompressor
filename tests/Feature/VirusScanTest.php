<?php

namespace Tests\Feature;

use App\Services\PdfService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class VirusScanTest extends TestCase
{
    private string $pdfPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdfPath = base_path('tests/Fixtures/test.pdf');
    }

    public function test_it_accepts_a_clean_file(): void
    {
        Http::fake([config('av.api_url') => Http::response(['status' => 'clean'], 200)]);

        $this->assertTrue(app(PdfService::class)->scanPdfForViruses($this->pdfPath, 'test.pdf'));

        Http::assertSent(fn (Request $request) => $request->isMultipart()
            && $request->hasFile('file', null, 'test.pdf'));
    }

    public function test_it_rejects_an_infected_file(): void
    {
        Http::fake([config('av.api_url') => Http::response(['status' => 'infected'], 200)]);

        $this->assertFalse(app(PdfService::class)->scanPdfForViruses($this->pdfPath, 'test.pdf'));
    }

    public function test_it_throws_when_the_scanner_returns_an_error(): void
    {
        Http::fake([config('av.api_url') => Http::response(['status' => 'error'], 500)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Virus scanner returned HTTP 500');

        app(PdfService::class)->scanPdfForViruses($this->pdfPath, 'test.pdf');
    }

    public function test_it_throws_when_the_scanner_is_unreachable(): void
    {
        Http::fake(fn () => throw new ConnectionException('connection refused'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Virus scanner is unreachable');

        app(PdfService::class)->scanPdfForViruses($this->pdfPath, 'test.pdf');
    }

    public function test_it_throws_on_an_unexpected_scanner_response(): void
    {
        Http::fake([config('av.api_url') => Http::response(['message' => 'nope'], 200)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unexpected response');

        app(PdfService::class)->scanPdfForViruses($this->pdfPath, 'test.pdf');
    }

    public function test_it_throws_when_the_file_cannot_be_opened(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not open the PDF for scanning');

        app(PdfService::class)->scanPdfForViruses('/tmp/definitely-missing.pdf');
    }
}
