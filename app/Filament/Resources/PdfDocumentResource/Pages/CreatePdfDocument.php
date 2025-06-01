<?php

namespace App\Filament\Resources\PdfDocumentResource\Pages;

use App\Filament\Resources\PdfDocumentResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreatePdfDocument extends CreateRecord
{
    protected static string $resource = PdfDocumentResource::class;
}
