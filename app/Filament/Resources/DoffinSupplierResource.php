<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DoffinSupplierResource\Pages\ListDoffinSuppliers;
use App\Filament\Resources\DoffinSupplierResource\Pages\ViewDoffinSupplier;
use App\Models\DoffinSupplier;
use App\Support\CustomerContext;
use BackedEnum;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Displays persisted Doffin suppliers in Filament admin.
 */
class DoffinSupplierResource extends Resource
{
    protected static ?string $model = DoffinSupplier::class;

    protected static ?string $navigationLabel = 'Suppliers';

    protected static string|UnitEnum|null $navigationGroup = 'Doffin';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return app(CustomerContext::class)->isInternalAdmin();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withListingMetrics())
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('supplier_name')
                    ->searchable()
                    ->wrap()
                    ->sortable(),
                TextColumn::make('organization_number')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('notices_count')
                    ->label('Notices')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime('Y-m-d H:i')
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
                Section::make('Supplier')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('supplier_name')
                            ->wrap(),
                        TextEntry::make('organization_number')
                            ->placeholder('Unknown'),
                        TextEntry::make('normalized_name'),
                        TextEntry::make('updated_at')
                            ->dateTime('Y-m-d H:i:s'),
                    ]),
                Section::make('Linked notices')
                    ->schema([
                        RepeatableEntry::make('notice_rows')
                            ->state(function (DoffinSupplier $record): array {
                                return $record->noticeSuppliers()
                                    ->with('notice')
                                    ->orderByDesc('id')
                                    ->get()
                                    ->map(fn ($link): array => [
                                        'notice_id' => $link->notice?->notice_id,
                                        'heading' => $link->notice?->heading,
                                        'buyer_name' => $link->notice?->buyer_name,
                                        'publication_date' => optional($link->notice?->publication_date)?->format('Y-m-d H:i'),
                                        'source' => $link->source,
                                        'winner_lots' => implode(', ', $link->winner_lots_json ?? []),
                                    ])
                                    ->all();
                            })
                            ->contained(false)
                            ->schema([
                                TextEntry::make('notice_id')
                                    ->label('Notice ID'),
                                TextEntry::make('heading')
                                    ->wrap(),
                                TextEntry::make('buyer_name')
                                    ->label('Buyer')
                                    ->wrap(),
                                TextEntry::make('publication_date'),
                                TextEntry::make('source')
                                    ->badge(),
                                TextEntry::make('winner_lots')
                                    ->label('Winner lots')
                                    ->wrap()
                                    ->placeholder('None'),
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
            'index' => ListDoffinSuppliers::route('/'),
            'view' => ViewDoffinSupplier::route('/{record}'),
        ];
    }
}
