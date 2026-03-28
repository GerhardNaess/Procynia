<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerResource\Pages\CreateCustomer;
use App\Filament\Resources\CustomerResource\Pages\EditCustomer;
use App\Filament\Resources\CustomerResource\Pages\ListCustomers;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Support\CustomerContext;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('procynia.customer.section'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('procynia.common.name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('slug')
                            ->label(__('procynia.customer.slug'))
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        Select::make('nationality_id')
                            ->label(__('procynia.customer.nationality'))
                            ->required()
                            ->options(fn (): array => self::nationalityOptions())
                            ->searchable()
                            ->preload()
                            ->default(fn (): ?int => Nationality::query()->where('code', 'NO')->value('id')),
                        Select::make('language_id')
                            ->label(__('procynia.customer.language'))
                            ->required()
                            ->options(fn (): array => self::languageOptions())
                            ->searchable()
                            ->preload()
                            ->default(fn (): ?int => Language::query()->where('code', 'no')->value('id')),
                        Toggle::make('is_active')
                            ->label(__('procynia.customer.is_active'))
                            ->default(true),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('procynia.common.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label(__('procynia.customer.slug'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('nationality.name_no')
                    ->label(__('procynia.customer.nationality'))
                    ->state(fn (Customer $record): string => self::nationalityLabel($record->nationality))
                    ->sortable(),
                TextColumn::make('language.name_no')
                    ->label(__('procynia.customer.language'))
                    ->state(fn (Customer $record): string => $record->language?->name_no ?? __('procynia.common.none'))
                    ->sortable(),
                TextColumn::make('is_active')
                    ->label(__('procynia.customer.is_active'))
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('procynia.common.created_at'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make(),
            ]);
    }

    public static function canAccess(): bool
    {
        return app(CustomerContext::class)->isInternalAdmin();
    }

    public static function getNavigationLabel(): string
    {
        return __('procynia.customer.resource');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['nationality', 'language'])
            ->orderBy('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }

    private static function nationalityOptions(): array
    {
        return Nationality::query()
            ->orderBy('name_no')
            ->get()
            ->mapWithKeys(fn (Nationality $nationality): array => [
                $nationality->id => self::nationalityLabel($nationality),
            ])
            ->all();
    }

    private static function languageOptions(): array
    {
        return Language::query()
            ->orderBy('name_no')
            ->pluck('name_no', 'id')
            ->all();
    }

    private static function nationalityLabel(?Nationality $nationality): string
    {
        if (! $nationality instanceof Nationality) {
            return __('procynia.common.none');
        }

        return trim("{$nationality->flag_emoji} {$nationality->name_no}");
    }
}
