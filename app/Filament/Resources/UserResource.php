<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Support\CustomerContext;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('procynia.user.section'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('procynia.common.name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label(__('procynia.user.email'))
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('password')
                            ->label(__('procynia.user.password'))
                            ->password()
                            ->revealable()
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->maxLength(255),
                        Select::make('role')
                            ->label(__('procynia.user.role'))
                            ->options(fn (): array => self::roleOptionsForActor())
                            ->required()
                            ->live(),
                        Select::make('customer_id')
                            ->label(__('procynia.common.customer'))
                            ->options(fn (): array => self::customerOptionsForActor())
                            ->required(fn (Get $get): bool => in_array((string) $get('role'), [User::ROLE_CUSTOMER_ADMIN, User::ROLE_USER], true))
                            ->visible(fn (): bool => self::currentActor()?->isSuperAdmin() ?? false)
                            ->searchable()
                            ->preload()
                            ->live(),
                        Select::make('department_id')
                            ->label(__('procynia.common.department'))
                            ->options(fn (Get $get): array => self::departmentOptionsForActor($get))
                            ->searchable()
                            ->preload()
                            ->visible(fn (Get $get): bool => (string) $get('role') !== User::ROLE_SUPER_ADMIN),
                        Select::make('nationality_id')
                            ->label(__('procynia.user.nationality'))
                            ->options(fn (): array => self::nationalityOptions())
                            ->searchable()
                            ->preload()
                            ->default(fn (): ?int => Nationality::query()->where('code', 'NO')->value('id')),
                        Select::make('preferred_language_id')
                            ->label(__('procynia.user.preferred_language'))
                            ->options(fn (): array => self::languageOptions())
                            ->searchable()
                            ->preload()
                            ->placeholder(__('procynia.user.use_customer_default')),
                        Toggle::make('is_active')
                            ->label(__('procynia.user.is_active'))
                            ->default(true),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label(__('procynia.common.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label(__('procynia.user.email'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('role')
                    ->label(__('procynia.user.role'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('customer.name')
                    ->label(__('procynia.common.customer'))
                    ->searchable()
                    ->sortable()
                    ->placeholder(__('procynia.user.internal_user')),
                TextColumn::make('department.name')
                    ->label(__('procynia.common.department'))
                    ->sortable()
                    ->placeholder(__('procynia.common.none')),
                TextColumn::make('nationality.name_no')
                    ->label(__('procynia.user.nationality'))
                    ->state(fn (User $record): string => self::nationalityLabel($record->nationality))
                    ->sortable()
                    ->placeholder(__('procynia.common.none')),
                TextColumn::make('preferredLanguage.name_no')
                    ->label(__('procynia.user.preferred_language'))
                    ->state(fn (User $record): string => $record->preferredLanguage?->name_no ?? __('procynia.user.use_customer_default'))
                    ->sortable(),
                TextColumn::make('is_active')
                    ->label(__('procynia.user.is_active'))
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('procynia.common.created_at'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->label(__('procynia.user.role'))
                    ->options(fn (): array => self::roleOptionsForActor()),
                SelectFilter::make('customer_id')
                    ->label(__('procynia.common.customer'))
                    ->options(fn (): array => self::customerOptionsForActor())
                    ->visible(fn (): bool => self::currentActor()?->isSuperAdmin() ?? false),
                TernaryFilter::make('is_active')
                    ->label(__('procynia.user.is_active'))
                    ->boolean(),
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make(),
            ]);
    }

    public static function canAccess(): bool
    {
        return app(CustomerContext::class)->canManageUsers();
    }

    public static function canCreate(): bool
    {
        return self::canAccess();
    }

    public static function canEdit(Model $record): bool
    {
        $actor = self::currentActor();

        if (! $actor instanceof User || ! $record instanceof User) {
            return false;
        }

        if ($actor->isSuperAdmin()) {
            return true;
        }

        if (! $actor->isCustomerAdmin()) {
            return false;
        }

        return $record->customer_id !== null
            && (int) $record->customer_id === (int) $actor->customer_id
            && ! $record->isSuperAdmin();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getNavigationLabel(): string
    {
        return __('procynia.user.resource');
    }

    public static function getEloquentQuery(): Builder
    {
        $actor = self::currentActor();
        $query = parent::getEloquentQuery()->with(['customer', 'department', 'nationality', 'preferredLanguage']);

        if (! $actor instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        if ($actor->isSuperAdmin()) {
            return $query;
        }

        if ($actor->isCustomerAdmin() && $actor->customer_id !== null) {
            return $query->where('customer_id', $actor->customer_id);
        }

        return $query->whereRaw('1 = 0');
    }

    public static function sanitizeFormData(array $data, ?User $record = null): array
    {
        $actor = self::currentActor();

        if (! $actor instanceof User || ! $actor->canManageUsers()) {
            throw new AuthorizationException('You are not allowed to manage users.');
        }

        if ($record instanceof User && ! self::canEdit($record)) {
            throw new AuthorizationException('You are not allowed to edit this user.');
        }

        $role = (string) ($data['role'] ?? $record?->role ?? '');
        $customerId = isset($data['customer_id']) && $data['customer_id'] !== '' ? (int) $data['customer_id'] : null;
        $departmentId = isset($data['department_id']) && $data['department_id'] !== '' ? (int) $data['department_id'] : null;
        $nationalityId = isset($data['nationality_id']) && $data['nationality_id'] !== '' ? (int) $data['nationality_id'] : null;
        $preferredLanguageId = isset($data['preferred_language_id']) && $data['preferred_language_id'] !== '' ? (int) $data['preferred_language_id'] : null;

        if (! in_array($role, array_keys(self::roleOptionsForActor()), true)) {
            throw ValidationException::withMessages([
                'role' => __('procynia.user.invalid_role'),
            ]);
        }

        $submittedCustomerId = $customerId;

        if ($actor->isCustomerAdmin()) {
            if ($role === User::ROLE_SUPER_ADMIN) {
                throw ValidationException::withMessages([
                    'role' => __('procynia.user.customer_admin_cannot_create_super_admin'),
                ]);
            }

            if ($submittedCustomerId !== null && $submittedCustomerId !== (int) $actor->customer_id) {
                throw ValidationException::withMessages([
                    'customer_id' => __('procynia.user.customer_must_match_actor'),
                ]);
            }

            $customerId = (int) $actor->customer_id;
        }

        if ($role === User::ROLE_SUPER_ADMIN) {
            $customerId = null;
            $departmentId = null;
        } else {
            if ($customerId === null) {
                throw ValidationException::withMessages([
                    'customer_id' => __('procynia.user.customer_required'),
                ]);
            }
        }

        if ($customerId !== null && ! Customer::query()->whereKey($customerId)->exists()) {
            throw ValidationException::withMessages([
                'customer_id' => __('procynia.user.customer_not_found'),
            ]);
        }

        if ($nationalityId !== null && ! Nationality::query()->whereKey($nationalityId)->exists()) {
            throw ValidationException::withMessages([
                'nationality_id' => __('procynia.user.nationality_not_found'),
            ]);
        }

        if ($preferredLanguageId !== null && ! Language::query()->whereKey($preferredLanguageId)->exists()) {
            throw ValidationException::withMessages([
                'preferred_language_id' => __('procynia.user.language_not_found'),
            ]);
        }

        if ($departmentId !== null) {
            $department = Department::query()->find($departmentId);

            if (! $department instanceof Department) {
                throw ValidationException::withMessages([
                    'department_id' => __('procynia.user.department_not_found'),
                ]);
            }

            if ($customerId === null || (int) $department->customer_id !== $customerId) {
                throw ValidationException::withMessages([
                    'department_id' => __('procynia.user.department_customer_mismatch'),
                ]);
            }
        }

        if ($record instanceof User && $actor->isCustomerAdmin() && $record->customer_id !== $actor->customer_id) {
            throw new AuthorizationException('You are not allowed to edit users from another customer.');
        }

        $data['role'] = $role;
        $data['customer_id'] = $customerId;
        $data['department_id'] = $departmentId;
        $data['nationality_id'] = $nationalityId;
        $data['preferred_language_id'] = $preferredLanguageId;
        $data['is_active'] = (bool) ($data['is_active'] ?? $record?->is_active ?? true);

        if (array_key_exists('password', $data) && blank($data['password'])) {
            unset($data['password']);
        }

        return $data;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    public static function roleOptionsForActor(): array
    {
        $actor = self::currentActor();

        if (! $actor instanceof User) {
            return [];
        }

        if ($actor->isSuperAdmin()) {
            return User::roleOptions();
        }

        if ($actor->isCustomerAdmin()) {
            return [
                User::ROLE_CUSTOMER_ADMIN => User::roleOptions()[User::ROLE_CUSTOMER_ADMIN],
                User::ROLE_USER => User::roleOptions()[User::ROLE_USER],
            ];
        }

        return [];
    }

    public static function customerOptionsForActor(): array
    {
        $actor = self::currentActor();

        if (! $actor instanceof User) {
            return [];
        }

        $query = Customer::query()
            ->where('is_active', true)
            ->orderBy('name');

        if ($actor->isCustomerAdmin()) {
            $query->whereKey($actor->customer_id);
        }

        if (! $actor->isSuperAdmin() && ! $actor->isCustomerAdmin()) {
            return [];
        }

        return $query->pluck('name', 'id')->all();
    }

    public static function departmentOptionsForActor(Get $get): array
    {
        $actor = self::currentActor();

        if (! $actor instanceof User) {
            return [];
        }

        $role = (string) $get('role');

        if ($role === User::ROLE_SUPER_ADMIN) {
            return [];
        }

        $query = Department::query()->orderBy('name');

        if ($actor->isSuperAdmin()) {
            $customerId = $get('customer_id');

            if (! is_numeric($customerId)) {
                return [];
            }

            $query->where('customer_id', (int) $customerId);
        } elseif ($actor->isCustomerAdmin() && $actor->customer_id !== null) {
            $query->where('customer_id', $actor->customer_id);
        } else {
            return [];
        }

        return $query->pluck('name', 'id')->all();
    }

    public static function nationalityOptions(): array
    {
        return Nationality::query()
            ->orderBy('name_no')
            ->get()
            ->mapWithKeys(fn (Nationality $nationality): array => [
                $nationality->id => self::nationalityLabel($nationality),
            ])
            ->all();
    }

    public static function languageOptions(): array
    {
        return Language::query()
            ->orderBy('name_no')
            ->pluck('name_no', 'id')
            ->all();
    }

    public static function currentActor(): ?User
    {
        return app(CustomerContext::class)->currentUser();
    }

    private static function nationalityLabel(?Nationality $nationality): string
    {
        if (! $nationality instanceof Nationality) {
            return __('procynia.common.none');
        }

        return trim("{$nationality->flag_emoji} {$nationality->name_no}");
    }
}
