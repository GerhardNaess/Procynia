<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SyncLogResource\Pages\ListSyncLogs;
use App\Models\SyncLog;
use App\Support\CustomerContext;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SyncLogResource extends Resource
{
    protected static ?string $model = SyncLog::class;

    protected static ?string $navigationLabel = 'Sync Logs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return app(CustomerContext::class)->isInternalAdmin();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->sortable(),
                TextColumn::make('job_type')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('notice.notice_id')
                    ->label('Notice')
                    ->searchable(),
                TextColumn::make('message')
                    ->limit(70)
                    ->wrap(),
                TextColumn::make('started_at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                TextColumn::make('finished_at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('job_type')
                    ->options(fn (): array => SyncLog::query()
                        ->whereNotNull('job_type')
                        ->distinct()
                        ->orderBy('job_type')
                        ->pluck('job_type', 'job_type')
                        ->all()),
                SelectFilter::make('status')
                    ->options(fn (): array => SyncLog::query()
                        ->whereNotNull('status')
                        ->distinct()
                        ->orderBy('status')
                        ->pluck('status', 'status')
                        ->all()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSyncLogs::route('/'),
        ];
    }
}
