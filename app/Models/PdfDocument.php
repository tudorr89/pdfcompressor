<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PdfDocument extends Model
{
    use HasUuids;
    protected $fillable = [
        'original_filename',
        'original_path',
        'compressed_path',
        'original_size',
        'compressed_size',
        'compression_ratio',
        'status',
        'error_message',
    ];
}
