<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DepartmentResource\Pages\CreateDepartment;
use App\Filament\Resources\DepartmentResource\Pages\EditDepartment;
use App\Filament\Resources\DepartmentResource\Pages\ListDepartments;
use App\Models\Customer;
use App\Models\Department;
use App\Support\CustomerContext;
use BackedEnum;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DepartmentResource extends Resource
{
    protected static ?string $model = Department::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('procynia.department.section'))
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
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Placeholder::make('watch_profile_note')
                            ->label(__('procynia.department.targeting_rules_note'))
                            ->content(__('procynia.department.targeting_rules_note'))
                            ->columnSpanFull(),
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
                TextColumn::make('name')
                    ->label(__('procynia.common.name'))
                    ->searchable()
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
            ->recordActions([
                \Filament\Actions\EditAction::make(),
            ]);
    }

    public static function getNavigationLabel(): string
    {
        return __('procynia.department.resource');
    }

    public static function getEloquentQuery(): Builder
    {
        return app(CustomerContext::class)->scopeCustomerOwned(parent::getEloquentQuery());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDepartments::route('/'),
            'create' => CreateDepartment::route('/create'),
            'edit' => EditDepartment::route('/{record}/edit'),
        ];
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
