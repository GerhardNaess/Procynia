<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BillingPriceResource\Pages\CreateBillingPrice;
use App\Filament\Resources\BillingPriceResource\Pages\EditBillingPrice;
use App\Filament\Resources\BillingPriceResource\Pages\ListBillingPrices;
use App\Filament\Resources\BillingPriceResource\Pages\ViewBillingPrice;
use App\Models\BillingPrice;
use App\Models\BillingProduct;
use App\Filament\Resources\BillingProductResource;
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

class BillingPriceResource extends Resource
{
    protected static ?string $model = BillingPrice::class;

    protected static ?string $navigationLabel = 'Tjenestekatalog';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Fakturering';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'tjeneste';

    protected static ?string $pluralModelLabel = 'tjenester';

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
                Section::make('Tjenestekatalog')
                    ->columns(2)
                    ->schema(self::catalogFormFields($includeProductSelect, $selectedProductId)),
                Section::make('Avansert')
                    ->columns(2)
                    ->schema(self::advancedFormFields()),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Tjenestekatalog')
                    ->columns(2)
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('interval')
                            ->label('Faktureringsintervall')
                            ->badge()
                            ->state(fn (BillingPrice $record): string => self::intervalLabel($record->interval)),
                        \Filament\Infolists\Components\TextEntry::make('unit_amount')
                            ->label('Pris')
                            ->state(fn (BillingPrice $record): string => self::lineItemPriceLabel($record)),
                        \Filament\Infolists\Components\TextEntry::make('product.category')
                            ->label('Type')
                            ->badge()
                            ->state(fn (BillingPrice $record): string => self::lineItemTypeLabel($record)),
                        \Filament\Infolists\Components\TextEntry::make('included_quantity')
                            ->label('Inkludert mengder')
                            ->columnSpanFull()
                            ->state(fn (BillingPrice $record): string => self::includedLabel($record)),
                        \Filament\Infolists\Components\TextEntry::make('is_active')
                            ->label('Status')
                            ->badge()
                            ->state(fn (BillingPrice $record): string => $record->is_active ? 'Aktiv' : 'Inaktiv'),
                        \Filament\Infolists\Components\TextEntry::make('visibility')
                            ->label('Synlig for salg')
                            ->badge()
                            ->state(fn (BillingPrice $record): string => self::saleVisibilityLabel($record)),
                        \Filament\Infolists\Components\TextEntry::make('created_at')
                            ->label('Opprettet')
                            ->dateTime('d.m.Y H:i'),
                        \Filament\Infolists\Components\TextEntry::make('updated_at')
                            ->label('Sist oppdatert')
                            ->dateTime('d.m.Y H:i'),
                        \Filament\Infolists\Components\TextEntry::make('product.description')
                            ->label('Beskrivelse')
                            ->columnSpanFull()
                            ->wrap()
                            ->placeholder('Ingen beskrivelse'),
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
                    ->label('Tjeneste')
                    ->options(fn (): array => self::productOptions()),
                SelectFilter::make('product_category')
                    ->label('Type')
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
        $users = (int) ($record->included_quantity ?? 0);
        $aiOffers = (int) ($record->included_ai_offers ?? 0);

        $parts = [];

        if ($users > 0) {
            $parts[] = number_format($users, 0, ',', ' ') . ' ' . ($users === 1 ? 'bruker' : 'brukere');
        }

        if ($aiOffers > 0) {
            $parts[] = number_format($aiOffers, 0, ',', ' ') . ' AI-tilbud';
        }

        return $parts !== [] ? implode(', ', $parts) : '–';
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
        $data['included_ai_offers'] = max(0, (int) ($data['included_ai_offers'] ?? 0));
        $data['is_recurring'] = ($data['interval'] ?? null) !== BillingPrice::INTERVAL_ONE_TIME;

        if (array_key_exists('stripe_price_id', $data) && blank($data['stripe_price_id'])) {
            $data['stripe_price_id'] = null;
        }

        if (array_key_exists('tier_key', $data) && blank($data['tier_key'])) {
            $data['tier_key'] = null;
        }

        return $data;
    }

    /**
     * @return array<int, object>
     */
    private static function catalogFormFields(bool $includeProductSelect, ?int $selectedProductId = null): array
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
                ->hidden(fn (string $operation): bool => $operation === 'edit')
                ->helperText($selectedProductId ? 'Forhåndsvalgt fra tjenestekatalogen.' : 'Velg hvilken plan eller tjeneste denne katalogoppføringen hører til.');
        }

        $fields[] = TextInput::make('name')
            ->label('Navn')
            ->required()
            ->maxLength(255);

        $fields[] = TextInput::make('product_category_label')
            ->label('Type')
            ->disabled()
            ->dehydrated(false)
            ->hidden(fn (string $operation): bool => $operation !== 'edit')
            ->helperText('Settes ved opprettelse og kan ikke endres.');

        $fields[] = Textarea::make('product_description')
            ->label('Beskrivelse')
            ->rows(3)
            ->columnSpanFull()
            ->hidden(fn (string $operation): bool => $operation !== 'edit');

        $fields[] = Select::make('interval')
            ->label('Intervall')
            ->options(fn (): array => self::intervalOptions())
            ->required()
            ->helperText('AI-lønnsomhet bruker monthly-priser direkte. Yearly-priser periodiseres til månedlig verdi ved å dele årsbeløpet på 12.');

        $fields[] = TextInput::make('currency')
            ->label('Valuta')
            ->required()
            ->maxLength(10)
            ->default('nok')
            ->helperText('AI-lønnsomhet bruker kun priser med valuta nok som inntektsgrunnlag. Andre valutaer blir ikke brukt i lønnsomhetsberegningen.');

        $fields[] = TextInput::make('unit_amount')
            ->label('Pris')
            ->suffix('kr')
            ->required()
            ->helperText('Angi pris i kroner. Lagres internt i øre.')
            ->formatStateUsing(function ($state): string {
                if ($state === null || $state === '') {
                    return '';
                }

                return number_format((int) $state / 100, 2, ',', ' ');
            })
            ->dehydrateStateUsing(function ($state): ?int {
                if (blank($state)) {
                    return null;
                }

                $normalized = str_replace([' ', ','], ['', '.'], (string) $state);

                return (int) round((float) $normalized * 100);
            })
            ->rules([
                function () {
                    return function (string $_attribute, mixed $value, \Closure $fail): void {
                        $normalized = str_replace([' ', ','], ['', '.'], (string) $value);

                        if (! is_numeric($normalized)) {
                            $fail('Prisen må være et gyldig tall.');
                        } elseif ((float) $normalized < 0) {
                            $fail('Prisen kan ikke være negativ.');
                        }
                    };
                },
            ]);

        $fields[] = TextInput::make('included_quantity')
            ->label('Inkludert antall brukere')
            ->numeric()
            ->minValue(0)
            ->default(0)
            ->required()
            ->helperText('Antall brukere som er inkludert i denne prisen.');

        $fields[] = TextInput::make('included_ai_offers')
            ->label('Inkludert antall AI-tilbud')
            ->numeric()
            ->minValue(0)
            ->default(0)
            ->required()
            ->helperText('Styrer AI-kapasitet i Fakturering → AI-forbruk. Må settes over 0 på betalte baseplaner (pro, max, ultra). Dersom feltet er 0, bruker systemet eldre kapasitetsdata fra kunden som fallback.');

        $fields[] = Toggle::make('is_active')
            ->label('Aktiv')
            ->default(true);

        return $fields;
    }

    /**
     * @return array<int, object>
     */
    private static function advancedFormFields(): array
    {
        return [
            TextInput::make('key')
                ->label('Intern nøkkel')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->disabled(fn (string $operation): bool => $operation === 'edit')
                ->helperText('Intern nøkkel. Opprett ny oppføring ved prisendring.'),

            TextInput::make('stripe_price_id')
                ->label('Stripe Price ID')
                ->maxLength(255)
                ->disabled(fn (string $operation): bool => $operation === 'edit')
                ->helperText('Stripe price-id hvis tjenesten er koblet til Stripe.'),

            TextInput::make('tier_key')
                ->label('Tier-nøkkel')
                ->maxLength(255)
                ->helperText('Intern plan- eller nivånøkkel.'),

            TextInput::make('product_sort_order')
                ->label('Visningsrekkefølge')
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->hidden(fn (string $operation): bool => $operation !== 'edit')
                ->helperText('Lavere tall vises først i katalogen.'),
        ];
    }
}
