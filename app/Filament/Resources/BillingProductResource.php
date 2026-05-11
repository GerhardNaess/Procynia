<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BillingProductResource\Pages\CreateBillingProduct;
use App\Filament\Resources\BillingProductResource\Pages\EditBillingProduct;
use App\Filament\Resources\BillingProductResource\Pages\ListBillingProducts;
use App\Filament\Resources\BillingProductResource\Pages\ViewBillingProduct;
use App\Models\BillingProduct;
use App\Support\CustomerContext;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use UnitEnum;

class BillingProductResource extends Resource
{
    protected static ?string $model = BillingProduct::class;

    protected static ?string $navigationLabel = 'Planer og tjenester';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Fakturering';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'tjeneste';

    protected static ?string $pluralModelLabel = 'planer og tjenester';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return app(CustomerContext::class)->isInternalAdmin();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Tjeneste')
                    ->columns(2)
                    ->schema([
                        TextInput::make('key')
                            ->label('Nøkkel')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->disabled(fn (string $operation): bool => $operation === 'edit')
                            ->helperText('Brukes som intern nøkkel og bør ikke endres etter opprettelse.'),
                        TextInput::make('name')
                            ->label('Navn')
                            ->required()
                            ->maxLength(255),
                        Select::make('category')
                            ->label('Kategori')
                            ->options(fn (): array => self::categoryOptions())
                            ->required()
                            ->disabled(fn (string $operation): bool => $operation === 'edit')
                            ->helperText('Kategori settes ved opprettelse og brukes i billing-logikken.'),
                        Select::make('billing_scope')
                            ->label('Faktureringsnivå')
                            ->options(fn (): array => self::billingScopeOptions())
                            ->required()
                            ->disabled(fn (string $operation): bool => $operation === 'edit'),
                        TextInput::make('sort_order')
                            ->label('Sortering')
                            ->numeric()
                            ->default(0),
                        Toggle::make('is_active')
                            ->label('Aktiv')
                            ->default(true),
                        Textarea::make('description')
                            ->label('Beskrivelse')
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Tjeneste')
                    ->columns(2)
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('key')
                            ->label('Nøkkel'),
                        \Filament\Infolists\Components\TextEntry::make('name')
                            ->label('Navn'),
                        \Filament\Infolists\Components\TextEntry::make('category')
                            ->label('Kategori')
                            ->badge()
                            ->state(fn (BillingProduct $record): string => self::categoryLabel($record->category)),
                        \Filament\Infolists\Components\TextEntry::make('billing_scope')
                            ->label('Faktureringsnivå')
                            ->badge()
                            ->state(fn (BillingProduct $record): string => self::billingScopeLabel($record->billing_scope)),
                        \Filament\Infolists\Components\TextEntry::make('sort_order')
                            ->label('Sortering')
                            ->numeric(),
                        \Filament\Infolists\Components\TextEntry::make('is_active')
                            ->label('Status')
                            ->badge()
                            ->state(fn (BillingProduct $record): string => $record->is_active ? 'Aktiv' : 'Inaktiv'),
                        \Filament\Infolists\Components\TextEntry::make('description')
                            ->label('Beskrivelse')
                            ->columnSpanFull()
                            ->wrap()
                            ->placeholder('Ingen beskrivelse'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->label('Navn')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('key')
                    ->label('Nøkkel')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('category')
                    ->label('Kategori')
                    ->badge()
                    ->state(fn (BillingProduct $record): string => self::categoryLabel($record->category))
                    ->sortable(),
                TextColumn::make('billing_scope')
                    ->label('Faktureringsnivå')
                    ->badge()
                    ->state(fn (BillingProduct $record): string => self::billingScopeLabel($record->billing_scope))
                    ->sortable(),
                TextColumn::make('description')
                    ->label('Beskrivelse')
                    ->limit(70)
                    ->wrap(),
                TextColumn::make('sort_order')
                    ->label('Sortering')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->state(fn (BillingProduct $record): string => $record->is_active ? 'Aktiv' : 'Inaktiv')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Oppdatert')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label('Kategori')
                    ->options(fn (): array => self::categoryOptions()),
                SelectFilter::make('billing_scope')
                    ->label('Faktureringsnivå')
                    ->options(fn (): array => self::billingScopeOptions()),
                TernaryFilter::make('is_active')
                    ->label('Aktiv')
                    ->trueLabel('Aktiv')
                    ->falseLabel('Inaktiv'),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Vis'),
                EditAction::make()
                    ->label('Rediger'),
            ]);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBillingProducts::route('/'),
            'create' => CreateBillingProduct::route('/create'),
            'edit' => EditBillingProduct::route('/{record}/edit'),
            'view' => ViewBillingProduct::route('/{record}'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function categoryOptions(): array
    {
        return [
            BillingProduct::CATEGORY_BASE_PLAN => 'Basisplan',
            BillingProduct::CATEGORY_USER_SEAT => 'Brukerlisens',
            BillingProduct::CATEGORY_USER_SERVICE => 'Brukertjeneste',
            BillingProduct::CATEGORY_ADDON => 'Tillegg',
            BillingProduct::CATEGORY_ONE_OFF => 'Engangstjeneste',
            BillingProduct::CATEGORY_ENTERPRISE => 'Enterprise',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function billingScopeOptions(): array
    {
        return [
            BillingProduct::BILLING_SCOPE_CUSTOMER => 'Kunde',
            BillingProduct::BILLING_SCOPE_USER => 'Bruker',
            BillingProduct::BILLING_SCOPE_QUANTITY => 'Antall',
        ];
    }

    public static function categoryLabel(string $category): string
    {
        return self::categoryOptions()[$category] ?? Str::headline($category);
    }

    public static function billingScopeLabel(string $scope): string
    {
        return self::billingScopeOptions()[$scope] ?? Str::headline($scope);
    }
}
