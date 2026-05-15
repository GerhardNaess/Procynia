<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OperationalDeviationResource\Pages\CreateOperationalDeviation;
use App\Filament\Resources\OperationalDeviationResource\Pages\EditOperationalDeviation;
use App\Filament\Resources\OperationalDeviationResource\Pages\ListOperationalDeviations;
use App\Filament\Resources\OperationalDeviationResource\Pages\ViewOperationalDeviation;
use App\Models\OperationalDeviation;
use App\Support\CustomerContext;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class OperationalDeviationResource extends Resource
{
    protected static ?string $model = OperationalDeviation::class;

    protected static ?string $navigationLabel = null;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = null;

    protected static ?string $pluralModelLabel = null;

    protected static ?string $recordTitleAttribute = 'code';

    public static function getNavigationLabel(): string
    {
        return __('procynia.operational_deviations.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('procynia.operational_deviations.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('procynia.operational_deviations.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('procynia.operational_deviations.plural_model_label');
    }

    public static function canAccess(): bool
    {
        return app(CustomerContext::class)->isInternalAdmin();
    }

    public static function canCreate(): bool
    {
        return self::canAccess();
    }

    public static function canEdit(Model $record): bool
    {
        return self::canAccess();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('procynia.operational_deviations.sections.identity'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('code')
                            ->label(__('procynia.operational_deviations.fields.code'))
                            ->helperText(__('procynia.operational_deviations.help.code'))
                            ->default(fn (): string => OperationalDeviation::nextSuggestedCode())
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(32),
                        TextInput::make('title')
                            ->label(__('procynia.operational_deviations.fields.title'))
                            ->required()
                            ->maxLength(255),
                        Select::make('category')
                            ->label(__('procynia.operational_deviations.fields.category'))
                            ->options(fn (): array => OperationalDeviation::categoryOptions())
                            ->default(OperationalDeviation::CATEGORY_OTHER)
                            ->required()
                            ->searchable()
                            ->preload(),
                        Select::make('severity')
                            ->label(__('procynia.operational_deviations.fields.severity'))
                            ->options(fn (): array => OperationalDeviation::severityOptions())
                            ->default(OperationalDeviation::SEVERITY_MEDIUM)
                            ->required()
                            ->searchable()
                            ->preload(),
                        Select::make('status')
                            ->label(__('procynia.operational_deviations.fields.status'))
                            ->options(fn (): array => OperationalDeviation::statusOptions())
                            ->default(OperationalDeviation::STATUS_NEW)
                            ->required()
                            ->searchable()
                            ->preload()
                            ->helperText(__('procynia.operational_deviations.help.status')),
                    ]),
                Section::make(__('procynia.operational_deviations.sections.description'))
                    ->columns(2)
                    ->schema([
                        Textarea::make('description')
                            ->label(__('procynia.operational_deviations.fields.description'))
                            ->required()
                            ->rows(5)
                            ->columnSpanFull(),
                        Textarea::make('impact')
                            ->label(__('procynia.operational_deviations.fields.impact'))
                            ->rows(4)
                            ->columnSpanFull(),
                        Textarea::make('recommended_action')
                            ->label(__('procynia.operational_deviations.fields.recommended_action'))
                            ->rows(4)
                            ->columnSpanFull(),
                        Textarea::make('acceptance_criteria')
                            ->label(__('procynia.operational_deviations.fields.acceptance_criteria'))
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),
                Section::make(__('procynia.operational_deviations.sections.responsibility'))
                    ->columns(2)
                    ->schema([
                        Select::make('owner_user_id')
                            ->label(__('procynia.operational_deviations.fields.owner'))
                            ->relationship('owner', 'name')
                            ->searchable()
                            ->preload(),
                        TextInput::make('source')
                            ->label(__('procynia.operational_deviations.fields.source'))
                            ->maxLength(255),
                        DatePicker::make('source_date')
                            ->label(__('procynia.operational_deviations.fields.source_date')),
                        DateTimePicker::make('due_at')
                            ->label(__('procynia.operational_deviations.fields.due_at')),
                        TextInput::make('commit_hash')
                            ->label(__('procynia.operational_deviations.fields.commit_hash'))
                            ->maxLength(128)
                            ->columnSpanFull(),
                    ]),
                Section::make(__('procynia.operational_deviations.sections.verification'))
                    ->description(__('procynia.operational_deviations.help.lifecycle'))
                    ->columns(2)
                    ->schema([
                        Textarea::make('verification_notes')
                            ->label(__('procynia.operational_deviations.fields.verification_notes'))
                            ->rows(4)
                            ->columnSpanFull(),
                        DateTimePicker::make('started_at')
                            ->label(__('procynia.operational_deviations.fields.started_at')),
                        DateTimePicker::make('ready_for_verification_at')
                            ->label(__('procynia.operational_deviations.fields.ready_for_verification_at')),
                        DateTimePicker::make('verified_at')
                            ->label(__('procynia.operational_deviations.fields.verified_at')),
                        DateTimePicker::make('closed_at')
                            ->label(__('procynia.operational_deviations.fields.closed_at')),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('procynia.operational_deviations.sections.identity'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('code')
                            ->label(__('procynia.operational_deviations.fields.code')),
                        TextEntry::make('title')
                            ->label(__('procynia.operational_deviations.fields.title'))
                            ->columnSpanFull(),
                        TextEntry::make('category')
                            ->label(__('procynia.operational_deviations.fields.category'))
                            ->badge()
                            ->state(fn (OperationalDeviation $record): string => OperationalDeviation::categoryLabel($record->category)),
                        TextEntry::make('severity')
                            ->label(__('procynia.operational_deviations.fields.severity'))
                            ->badge()
                            ->state(fn (OperationalDeviation $record): string => OperationalDeviation::severityLabel($record->severity)),
                        TextEntry::make('status')
                            ->label(__('procynia.operational_deviations.fields.status'))
                            ->badge()
                            ->state(fn (OperationalDeviation $record): string => OperationalDeviation::statusLabel($record->status)),
                        TextEntry::make('owner.name')
                            ->label(__('procynia.operational_deviations.fields.owner'))
                            ->placeholder(__('procynia.common.none')),
                        TextEntry::make('source')
                            ->label(__('procynia.operational_deviations.fields.source'))
                            ->placeholder(__('procynia.common.none')),
                        TextEntry::make('source_date')
                            ->label(__('procynia.operational_deviations.fields.source_date'))
                            ->date('d.m.Y')
                            ->placeholder(__('procynia.common.none')),
                        TextEntry::make('due_at')
                            ->label(__('procynia.operational_deviations.fields.due_at'))
                            ->dateTime('d.m.Y H:i')
                            ->placeholder(__('procynia.common.none')),
                        TextEntry::make('commit_hash')
                            ->label(__('procynia.operational_deviations.fields.commit_hash'))
                            ->placeholder(__('procynia.common.none')),
                        TextEntry::make('createdBy.name')
                            ->label(__('procynia.operational_deviations.fields.created_by'))
                            ->placeholder(__('procynia.common.none')),
                        TextEntry::make('updatedBy.name')
                            ->label(__('procynia.operational_deviations.fields.updated_by'))
                            ->placeholder(__('procynia.common.none')),
                        TextEntry::make('created_at')
                            ->label(__('procynia.common.created_at'))
                            ->dateTime('d.m.Y H:i'),
                        TextEntry::make('updated_at')
                            ->label(__('procynia.common.updated_at'))
                            ->dateTime('d.m.Y H:i'),
                    ]),
                Section::make(__('procynia.operational_deviations.sections.description'))
                    ->columns(1)
                    ->schema([
                        TextEntry::make('description')
                            ->label(__('procynia.operational_deviations.fields.description'))
                            ->wrap()
                            ->columnSpanFull(),
                        TextEntry::make('impact')
                            ->label(__('procynia.operational_deviations.fields.impact'))
                            ->wrap()
                            ->placeholder(__('procynia.common.none')),
                        TextEntry::make('recommended_action')
                            ->label(__('procynia.operational_deviations.fields.recommended_action'))
                            ->wrap()
                            ->placeholder(__('procynia.common.none')),
                        TextEntry::make('acceptance_criteria')
                            ->label(__('procynia.operational_deviations.fields.acceptance_criteria'))
                            ->wrap()
                            ->placeholder(__('procynia.common.none')),
                        TextEntry::make('verification_notes')
                            ->label(__('procynia.operational_deviations.fields.verification_notes'))
                            ->wrap()
                            ->placeholder(__('procynia.common.none')),
                    ]),
                Section::make(__('procynia.operational_deviations.sections.verification'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('started_at')
                            ->label(__('procynia.operational_deviations.fields.started_at'))
                            ->dateTime('d.m.Y H:i')
                            ->placeholder(__('procynia.common.none')),
                        TextEntry::make('ready_for_verification_at')
                            ->label(__('procynia.operational_deviations.fields.ready_for_verification_at'))
                            ->dateTime('d.m.Y H:i')
                            ->placeholder(__('procynia.common.none')),
                        TextEntry::make('verified_at')
                            ->label(__('procynia.operational_deviations.fields.verified_at'))
                            ->dateTime('d.m.Y H:i')
                            ->placeholder(__('procynia.common.none')),
                        TextEntry::make('closed_at')
                            ->label(__('procynia.operational_deviations.fields.closed_at'))
                            ->dateTime('d.m.Y H:i')
                            ->placeholder(__('procynia.common.none')),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['owner', 'createdBy', 'updatedBy'])
                ->orderByRaw(OperationalDeviation::openFirstOrderExpression())
                ->orderByRaw(OperationalDeviation::severityOrderExpression())
                ->orderByDesc('updated_at'))
            ->columns([
                TextColumn::make('code')
                    ->label(__('procynia.operational_deviations.fields.code'))
                    ->searchable()
                    ->sortable()
                    ->badge(),
                TextColumn::make('title')
                    ->label(__('procynia.operational_deviations.fields.title'))
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('description')
                    ->label(__('procynia.operational_deviations.fields.description'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->wrap(),
                TextColumn::make('category')
                    ->label(__('procynia.operational_deviations.fields.category'))
                    ->badge()
                    ->state(fn (OperationalDeviation $record): string => OperationalDeviation::categoryLabel($record->category))
                    ->sortable(),
                TextColumn::make('severity')
                    ->label(__('procynia.operational_deviations.fields.severity'))
                    ->badge()
                    ->state(fn (OperationalDeviation $record): string => OperationalDeviation::severityLabel($record->severity))
                    ->color(fn (OperationalDeviation $record): string => match ($record->severity) {
                        OperationalDeviation::SEVERITY_CRITICAL => 'danger',
                        OperationalDeviation::SEVERITY_HIGH => 'warning',
                        OperationalDeviation::SEVERITY_MEDIUM => 'info',
                        OperationalDeviation::SEVERITY_LOW => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('procynia.operational_deviations.fields.status'))
                    ->badge()
                    ->state(fn (OperationalDeviation $record): string => OperationalDeviation::statusLabel($record->status))
                    ->color(fn (OperationalDeviation $record): string => match ($record->status) {
                        OperationalDeviation::STATUS_NEW => 'gray',
                        OperationalDeviation::STATUS_PLANNED => 'info',
                        OperationalDeviation::STATUS_IN_PROGRESS => 'warning',
                        OperationalDeviation::STATUS_READY_FOR_VERIFICATION => 'primary',
                        OperationalDeviation::STATUS_VERIFIED => 'success',
                        OperationalDeviation::STATUS_CLOSED => 'gray',
                        OperationalDeviation::STATUS_POSTPONED => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('owner.name')
                    ->label(__('procynia.operational_deviations.fields.owner'))
                    ->placeholder(__('procynia.common.none'))
                    ->sortable(),
                TextColumn::make('due_at')
                    ->label(__('procynia.operational_deviations.fields.due_at'))
                    ->dateTime('d.m.Y H:i')
                    ->placeholder(__('procynia.common.none'))
                    ->sortable(),
                TextColumn::make('closed_at')
                    ->label(__('procynia.operational_deviations.fields.closed_at'))
                    ->dateTime('d.m.Y H:i')
                    ->placeholder(__('procynia.common.none'))
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label(__('procynia.common.updated_at'))
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('procynia.operational_deviations.fields.status'))
                    ->options(fn (): array => OperationalDeviation::statusOptions()),
                SelectFilter::make('severity')
                    ->label(__('procynia.operational_deviations.fields.severity'))
                    ->options(fn (): array => OperationalDeviation::severityOptions()),
                SelectFilter::make('category')
                    ->label(__('procynia.operational_deviations.fields.category'))
                    ->options(fn (): array => OperationalDeviation::categoryOptions()),
                SelectFilter::make('owner_user_id')
                    ->label(__('procynia.operational_deviations.fields.owner'))
                    ->relationship('owner', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('open')
                    ->label(__('procynia.operational_deviations.filters.open'))
                    ->query(fn (Builder $query): Builder => $query->where('status', '!=', OperationalDeviation::STATUS_CLOSED)),
                Filter::make('closed')
                    ->label(__('procynia.operational_deviations.filters.closed'))
                    ->query(fn (Builder $query): Builder => $query->where('status', OperationalDeviation::STATUS_CLOSED)),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label(__('procynia.operational_deviations.actions.view')),
                EditAction::make()
                    ->label(__('procynia.operational_deviations.actions.edit')),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['owner', 'createdBy', 'updatedBy'])
            ->orderByRaw(OperationalDeviation::openFirstOrderExpression())
            ->orderByRaw(OperationalDeviation::severityOrderExpression())
            ->orderByDesc('updated_at');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOperationalDeviations::route('/'),
            'create' => CreateOperationalDeviation::route('/create'),
            'edit' => EditOperationalDeviation::route('/{record}/edit'),
            'view' => ViewOperationalDeviation::route('/{record}'),
        ];
    }
}
