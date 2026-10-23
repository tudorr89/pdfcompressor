<?php

namespace Database\Factories;

use App\Models\PdfDocument;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PdfDocument>
 */
class PdfDocumentFactory extends Factory
{
    protected $model = PdfDocument::class;

    public function definition(): array
    {
        return [
            'original_filename' => fake()->word().'.pdf',
            'original_path' => 'pdfs/original/'.Str::uuid().'.pdf',
            'compressed_path' => null,
            'original_size' => fake()->numberBetween(1024, 1024 * 1024),
            'compressed_size' => null,
            'compression_ratio' => null,
            'status' => 'pending',
            'error_message' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'compressed_path' => 'pdfs/compressed/'.Str::uuid().'_compressed.pdf',
            'compressed_size' => 512,
            'compression_ratio' => 50.0,
            'status' => 'completed',
        ]);
    }
}
