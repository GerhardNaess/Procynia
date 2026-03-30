<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DoffinNoticeResource\Pages\ListDoffinNotices;
use App\Filament\Resources\DoffinNoticeResource\Pages\ViewDoffinNotice;
use App\Models\DoffinNotice;
use App\Support\CustomerContext;
use BackedEnum;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Displays persisted public Doffin notices in Filament admin.
 */
class DoffinNoticeResource extends Resource
{
    protected static ?string $model = DoffinNotice::class;

    protected static ?string $navigationLabel = 'Notices';

    protected static string|UnitEnum|null $navigationGroup = 'Doffin';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return app(CustomerContext::class)->isInternalAdmin();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('suppliers'))
            ->defaultSort('publication_date', 'desc')
            ->columns([
                TextColumn::make('notice_id')
                    ->label('Notice ID')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('notice_type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('heading')
                    ->searchable()
                    ->wrap()
                    ->limit(80),
                TextColumn::make('publication_date')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                TextColumn::make('buyer_name')
                    ->searchable()
                    ->wrap()
                    ->limit(50),
                TextColumn::make('estimated_value_display')
                    ->label('Estimated value')
                    ->wrap()
                    ->limit(30),
                TextColumn::make('suppliers_count')
                    ->label('Suppliers')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('last_harvested_at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('notice_type')
                    ->options(fn (): array => DoffinNotice::query()
                        ->whereNotNull('notice_type')
                        ->distinct()
                        ->orderBy('notice_type')
                        ->pluck('notice_type', 'notice_type')
                        ->all()),
                Filter::make('publication_date')
                    ->schema([
                        \Filament\Forms\Components\DatePicker::make('published_from')
                            ->label('Published from'),
                        \Filament\Forms\Components\DatePicker::make('published_until')
                            ->label('Published until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['published_from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('publication_date', '>=', $date))
                            ->when($data['published_until'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('publication_date', '<=', $date));
                    }),
            ])
            ->recordActions([
                \Filament\Actions\ViewAction::make(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Notice')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('notice_id')
                            ->label('Notice ID'),
                        TextEntry::make('notice_type')
                            ->label('Notice type')
                            ->badge(),
                        TextEntry::make('heading')
                            ->columnSpanFull()
                            ->wrap(),
                        TextEntry::make('publication_date')
                            ->dateTime('Y-m-d H:i'),
                        TextEntry::make('issue_date')
                            ->dateTime('Y-m-d H:i'),
                        TextEntry::make('buyer_name')
                            ->wrap(),
                        TextEntry::make('buyer_org_id'),
                        TextEntry::make('estimated_value_display')
                            ->label('Estimated value'),
                        TextEntry::make('last_harvested_at')
                            ->dateTime('Y-m-d H:i:s'),
                    ]),
                Section::make('Arrays')
                    ->columns(1)
                    ->schema([
                        TextEntry::make('cpv_codes_json')
                            ->label('CPV codes')
                            ->state(fn (DoffinNotice $record): string => implode(', ', $record->cpv_codes_json ?? []))
                            ->wrap()
                            ->placeholder('No CPV codes'),
                        TextEntry::make('awarded_names_json')
                            ->label('Awarded names')
                            ->state(fn (DoffinNotice $record): string => implode(', ', $record->awarded_names_json ?? []))
                            ->wrap()
                            ->placeholder('No awarded names'),
                        TextEntry::make('place_of_performance_json')
                            ->label('Place of performance')
                            ->state(fn (DoffinNotice $record): string => implode(', ', $record->place_of_performance_json ?? []))
                            ->wrap()
                            ->placeholder('No place of performance'),
                    ]),
                Section::make('Suppliers')
                    ->schema([
                        RepeatableEntry::make('supplier_rows')
                            ->state(function (DoffinNotice $record): array {
                                return $record->noticeSuppliers()
                                    ->with('supplier')
                                    ->orderBy('id')
                                    ->get()
                                    ->map(fn ($link): array => [
                                        'supplier_name' => $link->supplier?->supplier_name,
                                        'organization_number' => $link->supplier?->organization_number,
                                        'source' => $link->source,
                                        'winner_lots' => implode(', ', $link->winner_lots_json ?? []),
                                    ])
                                    ->all();
                            })
                            ->contained(false)
                            ->schema([
                                TextEntry::make('supplier_name')
                                    ->label('Supplier')
                                    ->wrap(),
                                TextEntry::make('organization_number')
                                    ->label('Organization number'),
                                TextEntry::make('source')
                                    ->badge(),
                                TextEntry::make('winner_lots')
                                    ->label('Winner lots')
                                    ->wrap()
                                    ->placeholder('None'),
                            ]),
                    ]),
                Section::make('Raw payload')
                    ->collapsible()
                    ->schema([
                        TextEntry::make('raw_payload_json')
                            ->formatStateUsing(fn ($state): string => is_array($state)
                                ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                                : 'No raw payload stored')
                            ->wrap()
                            ->columnSpanFull()
                            ->extraAttributes([
                                'style' => 'max-height: 28rem; overflow: auto;',
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
            'index' => ListDoffinNotices::route('/'),
            'view' => ViewDoffinNotice::route('/{record}'),
        ];
    }
}
