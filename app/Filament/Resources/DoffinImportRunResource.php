<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DoffinImportRunResource\Pages\ListDoffinImportRuns;
use App\Filament\Resources\DoffinImportRunResource\Pages\ViewDoffinImportRun;
use App\Models\DoffinImportRun;
use App\Support\CustomerContext;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class DoffinImportRunResource extends Resource
{
    protected static ?string $model = DoffinImportRun::class;

    protected static ?string $navigationLabel = 'Import Runs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        return app(CustomerContext::class)->isInternalAdmin();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('started_at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                TextColumn::make('finished_at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                TextColumn::make('trigger')
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('fetched_count')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('created_count')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('updated_count')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('skipped_count')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('failed_count')
                    ->numeric()
                    ->sortable(),
            ])
            ->recordActions([
                \Filament\Actions\ViewAction::make(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Run summary')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('trigger')
                            ->badge(),
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('started_at')
                            ->dateTime('Y-m-d H:i:s'),
                        TextEntry::make('finished_at')
                            ->dateTime('Y-m-d H:i:s')
                            ->placeholder('Still running'),
                        TextEntry::make('fetched_count')
                            ->numeric(),
                        TextEntry::make('created_count')
                            ->numeric(),
                        TextEntry::make('updated_count')
                            ->numeric(),
                        TextEntry::make('skipped_count')
                            ->numeric(),
                        TextEntry::make('failed_count')
                            ->numeric(),
                        TextEntry::make('error_message')
                            ->columnSpanFull()
                            ->wrap()
                            ->placeholder('No error message stored'),
                    ]),
                Section::make('Meta')
                    ->schema([
                        TextEntry::make('meta')
                            ->formatStateUsing(fn ($state): string => is_array($state) && $state !== []
                                ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                                : 'No meta stored')
                            ->wrap()
                            ->columnSpanFull()
                            ->extraAttributes([
                                'style' => 'max-height: 24rem; overflow: auto;',
                            ]),
                    ]),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDoffinImportRuns::route('/'),
            'view' => ViewDoffinImportRun::route('/{record}'),
        ];
    }
}
