<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ApiClientResource\Pages\CreateApiClient;
use App\Filament\Resources\ApiClientResource\Pages\EditApiClient;
use App\Filament\Resources\ApiClientResource\Pages\ListApiClients;
use App\Models\ApiClient;
use App\Services\Api\ApiClientAdministration;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Tekniske klientapplikasjoner for det eksterne API-et (D13, punkt 1).
 *
 * Interne administratorer registrerer, endrer navn på, aktiverer og deaktiverer en applikasjon. Alle
 * skrivinger går gjennom ApiClientAdministration, som selv krever intern administrator og
 * registrerer endringen. Registrering gir ingen tilgang til kundedata: det krever kundens egen
 * tildeling i Kundemiljø → Integrasjoner. En applikasjon slettes aldri, den deaktiveres.
 */
class ApiClientResource extends Resource
{
    protected static ?string $model = ApiClient::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Kunder';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return __('procynia.api_clients.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('procynia.api_clients.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('procynia.api_clients.plural_model_label');
    }

    public static function canAccess(): bool
    {
        return app(ApiClientAdministration::class)->mayAdminister(auth()->user());
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('procynia.api_clients.fields.name'))
                ->required()
                ->maxLength(255),
            Textarea::make('description')
                ->label(__('procynia.api_clients.fields.description'))
                ->maxLength(2000)
                ->columnSpanFull(),
            TextInput::make('entra_app_id')
                ->label(__('procynia.api_clients.fields.entra_app_id'))
                ->helperText(__('procynia.api_clients.help.entra_app_id'))
                ->required()
                // Fixed once registered: grants were given to this application.
                ->disabled(fn (string $operation): bool => $operation === 'edit')
                ->dehydrated(fn (string $operation): bool => $operation === 'create'),
            TextInput::make('home_tenant_id')
                ->label(__('procynia.api_clients.fields.home_tenant_id'))
                ->helperText(__('procynia.api_clients.help.home_tenant_id'))
                ->required()
                ->disabled(fn (string $operation): bool => $operation === 'edit')
                ->dehydrated(fn (string $operation): bool => $operation === 'create'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount([
                'grants as active_grants_count' => fn (Builder $grants) => $grants->whereNull('revoked_at'),
            ]))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('procynia.api_clients.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('entra_app_id')
                    ->label(__('procynia.api_clients.fields.entra_app_id'))
                    ->copyable(),
                TextColumn::make('home_tenant_id')
                    ->label(__('procynia.api_clients.fields.home_tenant_id'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('is_enabled')
                    ->label(__('procynia.api_clients.fields.status'))
                    ->badge()
                    ->color(fn (ApiClient $record): string => $record->is_enabled ? 'success' : 'gray')
                    ->state(fn (ApiClient $record): string => $record->is_enabled
                        ? __('procynia.api_clients.status.enabled')
                        : __('procynia.api_clients.status.disabled')),
                TextColumn::make('active_grants_count')
                    ->label(__('procynia.api_clients.fields.active_grants'))
                    ->numeric(),
                TextColumn::make('created_at')
                    ->label(__('procynia.api_clients.fields.created_at'))
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make()
                    ->label(__('procynia.api_clients.actions.edit')),
                Action::make('disable')
                    ->label(__('procynia.api_clients.actions.disable'))
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('procynia.api_clients.confirm.disable'))
                    ->visible(fn (ApiClient $record): bool => $record->is_enabled)
                    ->action(fn (ApiClient $record) => app(ApiClientAdministration::class)->setEnabled(auth()->user(), $record, false)),
                Action::make('enable')
                    ->label(__('procynia.api_clients.actions.enable'))
                    ->requiresConfirmation()
                    ->modalDescription(__('procynia.api_clients.confirm.enable'))
                    ->visible(fn (ApiClient $record): bool => ! $record->is_enabled)
                    ->action(fn (ApiClient $record) => app(ApiClientAdministration::class)->setEnabled(auth()->user(), $record, true)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApiClients::route('/'),
            'create' => CreateApiClient::route('/create'),
            'edit' => EditApiClient::route('/{record}/edit'),
        ];
    }
}
