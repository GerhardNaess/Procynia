<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BillingPriceResource\Pages\CreateBillingPrice;
use App\Filament\Resources\BillingPriceResource\Pages\EditBillingPrice;
use App\Filament\Resources\BillingPriceResource\Pages\ListBillingPrices;
use App\Filament\Resources\BillingPriceResource\Pages\ViewBillingPrice;
use App\Models\BillingPrice;
use App\Models\BillingProduct;
use App\Support\CustomerContext;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
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

class BillingPriceResource extends Resource
{
    protected static ?string $model = BillingPrice::class;

    protected static ?string $navigationLabel = 'Tjenestekatalog';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Fakturering';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'varelinje';

    protected static ?string $pluralModelLabel = 'varelinjer';

    protected static ?string $recordRouteKeyName = 'billing_prices.id';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return app(CustomerContext::class)->isInternalAdmin();
    }

    public static function form(Schema $schema): Schema
    {
        return self::buildForm($schema);
    }

    public static function buildForm(Schema $schema, bool $includeProductSelect = true): Schema
    {
        $selectedProductId = request()->integer('billing_product_id') ?: null;

        return $schema
            ->components([
                Section::make('Varelinje')
                    ->columns(2)
                    ->schema(self::priceFormFields($includeProductSelect, $selectedProductId)),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasjon')
                    ->columns(2)
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('product.name')
                            ->label('Tilhører plan'),
                        \Filament\Infolists\Components\TextEntry::make('product.category')
                            ->label('Type')
                            ->badge()
                            ->state(fn (BillingPrice $record): string => self::lineItemTypeLabel($record)),
                        \Filament\Infolists\Components\TextEntry::make('interval')
                            ->label('Faktureringsintervall')
                            ->badge()
                            ->state(fn (BillingPrice $record): string => self::intervalLabel($record->interval)),
                        \Filament\Infolists\Components\TextEntry::make('unit_amount')
                            ->label('Pris')
                            ->state(fn (BillingPrice $record): string => self::lineItemPriceLabel($record)),
                        \Filament\Infolists\Components\TextEntry::make('included_quantity')
                            ->label('Inkludert antall')
                            ->state(fn (BillingPrice $record): string => self::includedLabel($record)),
                        \Filament\Infolists\Components\TextEntry::make('product.description')
                            ->label('Beskrivelse')
                            ->columnSpanFull()
                            ->wrap()
                            ->placeholder('Ingen beskrivelse'),
                        \Filament\Infolists\Components\TextEntry::make('created_at')
                            ->label('Opprettet')
                            ->dateTime('d.m.Y H:i'),
                        \Filament\Infolists\Components\TextEntry::make('updated_at')
                            ->label('Sist oppdatert')
                            ->dateTime('d.m.Y H:i'),
                    ]),
                Section::make('Stripe')
                    ->columns(2)
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('stripe_price_id')
                            ->label('Stripe Price ID')
                            ->placeholder('Ikke koblet'),
                        \Filament\Infolists\Components\TextEntry::make('stripe_connection_status')
                            ->label('Status for Stripe-kobling')
                            ->badge()
                            ->state(fn (BillingPrice $record): string => self::stripeConnectionLabel($record)),
                    ]),
                Section::make('Status')
                    ->columns(2)
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('is_active')
                            ->label('Status')
                            ->badge()
                            ->state(fn (BillingPrice $record): string => $record->is_active ? 'Aktiv' : 'Inaktiv'),
                        \Filament\Infolists\Components\TextEntry::make('visibility')
                            ->label('Synlig for salg')
                            ->badge()
                            ->state(fn (BillingPrice $record): string => self::saleVisibilityLabel($record)),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultPaginationPageOption(50)
            ->recordUrl(fn (BillingPrice $record): string => static::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('name')
                    ->label('Navn')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('product.name')
                    ->label('Plan / tjeneste')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('product.category')
                    ->label('Type')
                    ->badge()
                    ->state(fn (BillingPrice $record): string => self::lineItemTypeLabel($record)),
                TextColumn::make('interval')
                    ->label('Intervall')
                    ->badge()
                    ->state(fn (BillingPrice $record): string => self::intervalLabel($record->interval))
                    ->sortable(),
                TextColumn::make('unit_amount')
                    ->label('Pris')
                    ->state(fn (BillingPrice $record): string => self::lineItemPriceLabel($record))
                    ->sortable(),
                TextColumn::make('included_quantity')
                    ->label('Inkludert')
                    ->state(fn (BillingPrice $record): string => self::includedLabel($record))
                    ->sortable(),
                TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->state(fn (BillingPrice $record): string => $record->is_active ? 'Aktiv' : 'Inaktiv')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('billing_product_id')
                    ->label('Plan / tjeneste')
                    ->options(fn (): array => self::productOptions()),
                SelectFilter::make('product_category')
                    ->label('Kategori')
                    ->options(fn (): array => self::lineItemTypeOptions())
                    ->query(function (Builder $query, array $data): Builder {
                        $category = $data['value'] ?? null;

                        return $query->when(
                            filled($category),
                            fn (Builder $query): Builder => $query->where('billing_products.category', $category),
                        );
                    }),
                SelectFilter::make('interval')
                    ->label('Intervall')
                    ->options(fn (): array => self::intervalOptions()),
                TernaryFilter::make('is_active')
                    ->label('Status')
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
            ->select('billing_prices.*')
            ->join('billing_products', 'billing_products.id', '=', 'billing_prices.billing_product_id')
            ->with('product')
            ->orderByDesc('billing_prices.is_active')
            ->orderBy('billing_products.sort_order')
            ->orderBy('billing_products.name')
            ->orderByRaw("case billing_prices.interval when 'monthly' then 0 when 'yearly' then 1 when 'one_time' then 2 else 3 end")
            ->orderBy('billing_prices.name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBillingPrices::route('/'),
            'create' => CreateBillingPrice::route('/create'),
            'edit' => EditBillingPrice::route('/{record}/edit'),
            'view' => ViewBillingPrice::route('/{record}'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function intervalOptions(): array
    {
        return [
            BillingPrice::INTERVAL_MONTHLY => 'Månedlig',
            BillingPrice::INTERVAL_YEARLY => 'Årlig',
            BillingPrice::INTERVAL_ONE_TIME => 'Engangskjøp',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function lineItemTypeOptions(): array
    {
        return [
            BillingProduct::CATEGORY_BASE_PLAN => 'Abonnementsplan',
            BillingProduct::CATEGORY_USER_SEAT => 'Brukerlisens',
            BillingProduct::CATEGORY_USER_SERVICE => 'Brukertjeneste',
            BillingProduct::CATEGORY_ADDON => 'Tilleggstjeneste',
            BillingProduct::CATEGORY_ONE_OFF => 'Engangstjeneste',
            BillingProduct::CATEGORY_ENTERPRISE => 'Enterprise',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function productOptions(): array
    {
        return BillingProduct::query()
            ->orderByDesc('is_active')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public static function intervalLabel(string $interval): string
    {
        return self::intervalOptions()[$interval] ?? ucfirst($interval);
    }

    public static function formatAmount(mixed $state, ?string $currency = 'nok'): string
    {
        if ($state === null || $state === '') {
            return 'Ikke satt';
        }

        $formattedAmount = number_format(((int) $state) / 100, 0, ',', ' ');
        $currencyCode = strtolower((string) ($currency ?? 'nok'));

        return match ($currencyCode) {
            'nok' => $formattedAmount . ' kr',
            default => $formattedAmount . ' ' . strtoupper($currencyCode),
        };
    }

    public static function lineItemTypeLabel(BillingPrice $record): string
    {
        $category = (string) ($record->product?->category ?? '');

        if ($category === '') {
            return 'Ukjent type';
        }

        return self::lineItemTypeOptions()[$category] ?? Str::headline(str_replace('_', ' ', $category));
    }

    public static function lineItemPriceLabel(BillingPrice $record): string
    {
        if ($record->unit_amount === null) {
            return 'Ikke satt';
        }

        $amount = self::formatAmount($record->unit_amount, $record->currency);

        return match ($record->interval) {
            BillingPrice::INTERVAL_MONTHLY => $amount . ' / mnd',
            BillingPrice::INTERVAL_YEARLY => $amount . ' / år',
            default => $amount,
        };
    }

    public static function includedLabel(BillingPrice $record): string
    {
        $quantity = (int) ($record->included_quantity ?? 0);

        if ($quantity <= 0) {
            return '–';
        }

        $unit = match ($record->product?->category) {
            BillingProduct::CATEGORY_BASE_PLAN => $quantity === 1 ? 'bruker' : 'brukere',
            BillingProduct::CATEGORY_USER_SEAT, BillingProduct::CATEGORY_USER_SERVICE => $quantity === 1 ? 'bruker' : 'brukere',
            BillingProduct::CATEGORY_ADDON, BillingProduct::CATEGORY_ONE_OFF, BillingProduct::CATEGORY_ENTERPRISE => 'stk',
            default => 'stk',
        };

        return number_format($quantity, 0, ',', ' ') . ' ' . $unit;
    }

    public static function stripeConnectionLabel(BillingPrice $record): string
    {
        return filled($record->stripe_price_id) ? 'Koblet' : 'Ikke koblet';
    }

    public static function saleVisibilityLabel(BillingPrice $record): string
    {
        return $record->is_active && (bool) ($record->product?->is_active ?? false) ? 'Ja' : 'Nei';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeFormData(array $data): array
    {
        $data['currency'] = strtolower(trim((string) ($data['currency'] ?? 'nok'))) ?: 'nok';
        $data['unit_amount'] = filled($data['unit_amount'] ?? null) ? (int) $data['unit_amount'] : null;
        $data['included_quantity'] = max(0, (int) ($data['included_quantity'] ?? 0));
        $data['is_recurring'] = ($data['interval'] ?? null) !== BillingPrice::INTERVAL_ONE_TIME;

        if (blank($data['stripe_price_id'] ?? null)) {
            $data['stripe_price_id'] = null;
        }

        if (blank($data['tier_key'] ?? null)) {
            $data['tier_key'] = null;
        }

        return $data;
    }

    /**
     * @return array<int, object|string>
     */
    private static function priceFormFields(bool $includeProductSelect, ?int $selectedProductId = null): array
    {
        $fields = [];

        if ($includeProductSelect) {
            $fields[] = Select::make('billing_product_id')
                ->label('Plan / tjeneste')
                ->options(fn (): array => self::productOptions())
                ->required()
                ->default($selectedProductId)
                ->searchable()
                ->preload()
                ->helperText($selectedProductId ? 'Forhåndsvalgt fra tjenestekatalogen.' : 'Velg hvilken plan eller tjeneste denne varelinjen hører til.');
        }

        $fields[] = TextInput::make('key')
            ->label('Intern nøkkel')
            ->required()
            ->maxLength(255)
            ->unique(ignoreRecord: true)
            ->disabled(fn (string $operation): bool => $operation === 'edit')
            ->helperText('Intern nøkkel. Opprett ny varelinje ved prisendring.');

        $fields[] = TextInput::make('name')
            ->label('Navn')
            ->required()
            ->maxLength(255);

        $fields[] = Select::make('interval')
            ->label('Intervall')
            ->options(fn (): array => self::intervalOptions())
            ->required();

        $fields[] = TextInput::make('currency')
            ->label('Valuta')
            ->required()
            ->maxLength(10)
            ->default('nok');

        $fields[] = TextInput::make('unit_amount')
            ->label('Pris (øre)')
            ->numeric()
            ->minValue(0)
            ->required()
            ->helperText('Bruk øre som lagring. 99 000 = 990 kr.');

        $fields[] = TextInput::make('stripe_price_id')
            ->label('Stripe Price ID')
            ->maxLength(255)
            ->disabled(fn (string $operation): bool => $operation === 'edit')
            ->helperText('Stripe price-id hvis varelinjen er koblet til Stripe.');

        $fields[] = TextInput::make('tier_key')
            ->label('Tier-nøkkel')
            ->maxLength(255)
            ->helperText('Intern plan- eller nivånøkkel.');

        $fields[] = TextInput::make('included_quantity')
            ->label('Inkludert antall')
            ->numeric()
            ->minValue(0)
            ->default(0)
            ->required()
            ->helperText('Bruk 0 hvis dette ikke er relevant.');

        $fields[] = Toggle::make('is_active')
            ->label('Aktiv')
            ->default(true);

        return $fields;
    }
}
