<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PdfDocumentResource\Pages;
use App\Filament\Resources\PdfDocumentResource\RelationManagers;
use App\Models\PdfDocument;
use App\Services\BytesConversionService;
use App\Services\PdfService;
use Filament\Actions\DeleteAction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Storage;

class PdfDocumentResource extends Resource
{
    protected static ?string $model = PdfDocument::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('original_filename')
                    ->required(),
                Forms\Components\TextInput::make('original_path')
                    ->required(),
                Forms\Components\TextInput::make('compressed_path'),
                Forms\Components\TextInput::make('original_size')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('compressed_size')
                    ->numeric(),
                Forms\Components\TextInput::make('compression_ratio')
                    ->numeric(),
                Forms\Components\TextInput::make('status')
                    ->required(),
                Forms\Components\Textarea::make('error_message')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('original_filename')
                    ->searchable(),
                Tables\Columns\TextColumn::make('original_size')
                    ->formatStateUsing(fn (string $state): string => BytesConversionService::filesize($state)),
                Tables\Columns\TextColumn::make('compressed_size')
                    ->formatStateUsing(fn (string $state): string => BytesConversionService::filesize($state)),
                Tables\Columns\TextColumn::make('compression_ratio'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'gray',
                        'processing' => 'warning',
                        'completed' => 'success',
                        'failed' => 'danger',
                    })
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\Action::make('download')
                    ->action(fn (PdfDocument $document) => Storage::disk('local')->download($document->compressed_path))
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPdfDocuments::route('/'),
            //'create' => Pages\CreatePdfDocument::route('/create'),
            //'edit' => Pages\EditPdfDocument::route('/{record}/edit'),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return 'PDF Documents';
    }
}
