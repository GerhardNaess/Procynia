<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WatchProfileResource\Pages\CreateWatchProfile;
use App\Filament\Resources\WatchProfileResource\Pages\EditWatchProfile;
use App\Filament\Resources\WatchProfileResource\Pages\ListWatchProfiles;
use App\Models\CpvCode;
use App\Models\Customer;
use App\Models\Department;
use App\Models\WatchProfile;
use App\Support\CustomerContext;
use BackedEnum;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WatchProfileResource extends Resource
{
    protected static ?string $model = WatchProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('procynia.watch_profile.section'))
                    ->columns(2)
                    ->schema([
                        Select::make('customer_id')
                            ->label(__('procynia.common.customer'))
                            ->options(fn (): array => self::customerOptions())
                            ->required()
                            ->default(fn (): ?int => app(CustomerContext::class)->currentCustomerId())
                            ->disabled(fn (): bool => ! app(CustomerContext::class)->isInternalAdmin())
                            ->dehydrated(true)
                            ->searchable()
                            ->preload(),
                        Select::make('department_id')
                            ->label(__('procynia.common.department'))
                            ->options(function (Get $get): array {
                                $context = app(CustomerContext::class);
                                $selectedCustomerId = $get('customer_id');
                                $query = Department::query()->orderBy('name');

                                if ($context->isInternalAdmin()) {
                                    if (is_numeric($selectedCustomerId)) {
                                        $query->where('customer_id', (int) $selectedCustomerId);
                                    }
                                } else {
                                    $customerId = $context->currentCustomerId();

                                    if ($customerId !== null) {
                                        $query->where('customer_id', $customerId);
                                    }
                                }

                                return $query->pluck('name', 'id')->all();
                            })
                            ->searchable()
                            ->preload(),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Toggle::make('is_active')
                            ->label(__('procynia.common.active'))
                            ->default(true),
                        Textarea::make('description')
                            ->label(__('procynia.common.description'))
                            ->rows(3)
                            ->columnSpanFull(),
                        Textarea::make('keywords')
                            ->label(__('procynia.watch_profile.keywords'))
                            ->helperText(__('procynia.watch_profile.keywords_help'))
                            ->rows(6)
                            ->formatStateUsing(fn ($state): string => is_array($state) ? implode("\n", $state) : '')
                            ->dehydrateStateUsing(fn ($state): array => collect(preg_split('/\r\n|\r|\n/', (string) $state) ?: [])
                                ->map(fn ($value): string => trim($value))
                                ->filter()
                                ->values()
                                ->all())
                            ->columnSpanFull(),
                    ]),
                Section::make(__('procynia.watch_profile.cpv_codes'))
                    ->schema([
                        Repeater::make('cpvCodes')
                            ->relationship()
                            ->defaultItems(0)
                            ->itemLabel(fn (array $state): ?string => self::cpvItemLabel($state['cpv_code'] ?? null))
                            ->schema([
                                TextInput::make('cpv_code')
                                    ->label(__('procynia.watch_profile.cpv_code'))
                                    ->required()
                                    ->maxLength(255)
                                    ->helperText(fn (?string $state): ?string => self::cpvHelperText($state)),
                                TextInput::make('weight')
                                    ->required()
                                    ->numeric()
                                    ->default(1)
                                    ->minValue(1),
                            ])
                            ->columns(2)
                            ->columnSpanFull()
                            ->addActionLabel(__('procynia.watch_profile.add_cpv_code')),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('customer.name')
                    ->label(__('procynia.common.customer'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: ! app(CustomerContext::class)->isInternalAdmin()),
                TextColumn::make('department.name')
                    ->label(__('procynia.common.department'))
                    ->placeholder('Unassigned')
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('procynia.common.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('is_active')
                    ->label(__('procynia.common.active'))
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('procynia.common.created_at'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label(__('procynia.common.updated_at'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make(),
            ]);
    }

    public static function getNavigationLabel(): string
    {
        return __('procynia.watch_profile.resource');
    }

    public static function getEloquentQuery(): Builder
    {
        return app(CustomerContext::class)->scopeCustomerOwned(parent::getEloquentQuery());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWatchProfiles::route('/'),
            'create' => CreateWatchProfile::route('/create'),
            'edit' => EditWatchProfile::route('/{record}/edit'),
        ];
    }

    private static function cpvItemLabel(?string $code): ?string
    {
        $code = trim((string) $code);

        if ($code === '') {
            return null;
        }

        $catalogEntry = CpvCode::query()
            ->where('code', $code)
            ->first();

        if (! $catalogEntry instanceof CpvCode) {
            return $code;
        }

        $description = app(CustomerContext::class)->cpvDescription($catalogEntry);

        return $description === null ? $code : "{$code} — {$description}";
    }

    private static function cpvHelperText(?string $code): ?string
    {
        $code = trim((string) $code);

        if ($code === '') {
            return null;
        }

        $catalogEntry = CpvCode::query()
            ->where('code', $code)
            ->first();

        if (! $catalogEntry instanceof CpvCode) {
            return __('procynia.watch_profile.catalog_entry_missing');
        }

        return app(CustomerContext::class)->cpvDescription($catalogEntry);
    }

    private static function customerOptions(): array
    {
        $context = app(CustomerContext::class);
        $query = Customer::query()
            ->where('is_active', true)
            ->orderBy('name');

        if (! $context->isInternalAdmin()) {
            $customerId = $context->currentCustomerId();

            if ($customerId !== null) {
                $query->where('id', $customerId);
            }
        }

        return $query->pluck('name', 'id')->all();
    }
}
