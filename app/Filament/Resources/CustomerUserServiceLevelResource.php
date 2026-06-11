<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerUserServiceLevelResource\Pages\ListCustomerUserServiceLevels;
use App\Filament\Resources\CustomerUserServiceLevelResource\Pages\ViewCustomerUserServiceLevel;
use App\Models\BillingProduct;
use App\Models\Customer;
use App\Models\CustomerUserServiceLevel;
use App\Support\CustomerContext;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class CustomerUserServiceLevelResource extends Resource
{
    protected static ?string $model = CustomerUserServiceLevel::class;

    protected static ?string $navigationLabel = 'Brukerlisenser';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Fakturering';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'brukerlisens';

    protected static ?string $pluralModelLabel = 'brukerlisenser';

    protected static ?string $recordTitleAttribute = 'level_key';

    public static function canAccess(): bool
    {
        return app(CustomerContext::class)->isInternalAdmin();
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Tildeling')
                    ->columns(2)
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('customer.name')
                            ->label('Kunde'),
                        \Filament\Infolists\Components\TextEntry::make('user.name')
                            ->label('Bruker'),
                        \Filament\Infolists\Components\TextEntry::make('billingProduct.name')
                            ->label('Tjeneste'),
                        \Filament\Infolists\Components\TextEntry::make('billingPrice.name')
                            ->label('Pris'),
                        \Filament\Infolists\Components\TextEntry::make('level_key')
                            ->label('Nivå')
                            ->badge(),
                        \Filament\Infolists\Components\TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->state(fn (CustomerUserServiceLevel $record): string => self::statusLabel($record->status)),
                        \Filament\Infolists\Components\TextEntry::make('assignedByUser.name')
                            ->label('Tildelt av')
                            ->placeholder('Ukjent'),
                    ]),
                Section::make('Periode')
                    ->columns(2)
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('starts_at')
                            ->label('Start')
                            ->dateTime('Y-m-d H:i')
                            ->placeholder('Ikke satt'),
                        \Filament\Infolists\Components\TextEntry::make('ends_at')
                            ->label('Slutt')
                            ->dateTime('Y-m-d H:i')
                            ->placeholder('Aktiv'),
                        \Filament\Infolists\Components\TextEntry::make('created_at')
                            ->label('Opprettet')
                            ->dateTime('Y-m-d H:i'),
                        \Filament\Infolists\Components\TextEntry::make('updated_at')
                            ->label('Oppdatert')
                            ->dateTime('Y-m-d H:i'),
                    ]),
                Section::make('Metadata')
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('metadata')
                            ->label('Råmetadata')
                            ->state(fn ($state): string => self::prettyJson((array) $state))
                            ->columnSpanFull()
                            ->wrap()
                            ->extraAttributes([
                                'style' => 'max-height: 20rem; overflow: auto;',
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('customer.name')
                    ->label('Kunde')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('Bruker')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('billingProduct.name')
                    ->label('Tjeneste')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('billingPrice.name')
                    ->label('Pris')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('level_key')
                    ->label('Nivå')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (CustomerUserServiceLevel $record): string => self::statusLabel($record->status))
                    ->sortable(),
                TextColumn::make('starts_at')
                    ->label('Start')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                TextColumn::make('ends_at')
                    ->label('Slutt')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('Aktiv')
                    ->sortable(),
                TextColumn::make('assignedByUser.name')
                    ->label('Tildelt av')
                    ->placeholder('Ukjent')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('customer_id')
                    ->label('Kunde')
                    ->options(fn (): array => self::customerOptions()),
                SelectFilter::make('billing_product_id')
                    ->label('Tjeneste')
                    ->options(fn (): array => self::productOptions()),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(fn (): array => self::statusOptions()),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Vis'),
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['customer', 'user', 'billingProduct', 'billingPrice', 'assignedByUser'])
            ->orderByDesc('created_at');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomerUserServiceLevels::route('/'),
            'view' => ViewCustomerUserServiceLevel::route('/{record}'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            'active' => 'Aktiv',
            'pending_cancel' => 'Avsluttes',
            'ended' => 'Avsluttet',
            'cancelled' => 'Kansellert',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function customerOptions(): array
    {
        return Customer::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function productOptions(): array
    {
        return BillingProduct::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public static function statusLabel(string $status): string
    {
        return self::statusOptions()[$status] ?? ucfirst($status);
    }

    private static function prettyJson(array $metadata): string
    {
        if ($metadata === []) {
            return 'Ingen metadata registrert.';
        }

        return json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ?: 'Kunne ikke formatere metadata.';
    }
}
