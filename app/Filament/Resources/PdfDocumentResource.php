<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PdfDocumentResource\Pages;
use App\Models\PdfDocument;
use App\Services\BytesConversionService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

class PdfDocumentResource extends Resource
{
    protected static ?string $model = PdfDocument::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('original_filename')
                    ->required(),
                TextInput::make('original_path')
                    ->required(),
                TextInput::make('compressed_path'),
                TextInput::make('original_size')
                    ->required()
                    ->numeric(),
                TextInput::make('compressed_size')
                    ->numeric(),
                TextInput::make('compression_ratio')
                    ->numeric(),
                TextInput::make('status')
                    ->required(),
                Textarea::make('error_message')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('original_filename')
                    ->searchable(),
                TextColumn::make('original_size')
                    ->formatStateUsing(fn ($state): string => BytesConversionService::filesize((int) $state)),
                TextColumn::make('compressed_size')
                    ->formatStateUsing(fn ($state): string => BytesConversionService::filesize((int) $state)),
                TextColumn::make('compression_ratio'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'gray',
                        'processing' => 'warning',
                        'completed' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (PdfDocument $record): bool => $record->status === 'completed' && $record->compressed_path !== null)
                    ->action(fn (PdfDocument $record) => Storage::disk('local')->download((string) $record->compressed_path)),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
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
        ];
    }

    public static function getNavigationLabel(): string
    {
        return 'PDF Documents';
    }
}
