<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NoticeAttentionResource\Pages\ListNoticeAttentions;
use App\Models\NoticeAttention;
use App\Models\User;
use App\Services\Doffin\DoffinNoticeAttentionService;
use App\Support\CustomerContext;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class NoticeAttentionResource extends Resource
{
    protected static ?string $model = NoticeAttention::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                $departmentId = self::currentActor()?->department_id;

                return $query
                    ->with(['notice.assignedTo', 'department'])
                    ->when($departmentId !== null, fn (Builder $query): Builder => $query->where('department_id', $departmentId))
                    ->orderByDesc('is_new')
                    ->orderByDesc('department_score')
                    ->orderByDesc('first_seen_at');
            })
            ->columns([
                TextColumn::make('notice.notice_id')
                    ->label('Notice ID')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('notice.title')
                    ->label('Title')
                    ->searchable()
                    ->limit(70)
                    ->wrap(),
                TextColumn::make('department.name')
                    ->label('Department')
                    ->sortable(),
                TextColumn::make('department_score')
                    ->label('Score')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('relevance_level')
                    ->badge()
                    ->sortable(),
                IconColumn::make('is_new')
                    ->label('New')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('first_seen_at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                TextColumn::make('last_seen_at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                TextColumn::make('read_at')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('Unread')
                    ->sortable(),
                TextColumn::make('notice.internal_status')
                    ->label('Internal status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('notice.assignedTo.name')
                    ->label('Assigned to')
                    ->placeholder('Unassigned')
                    ->sortable(),
            ])
            ->filters([
                Filter::make('new_only')
                    ->label('New only')
                    ->query(fn (Builder $query): Builder => $query->where('is_new', true)),
                Filter::make('read_only')
                    ->label('Read only')
                    ->query(fn (Builder $query): Builder => $query->where('is_new', false)),
                Filter::make('internal_status')
                    ->schema([
                        \Filament\Forms\Components\Select::make('internal_status')
                            ->label('Internal status')
                            ->options([
                                'new' => 'New',
                                'under_review' => 'Under review',
                                'interesting' => 'Interesting',
                                'ignored' => 'Ignored',
                            ]),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $status = $data['internal_status'] ?? null;

                        if (! is_string($status) || $status === '') {
                            return $query;
                        }

                        return $query->whereHas('notice', fn (Builder $query): Builder => $query->where('internal_status', $status));
                    }),
                Filter::make('assigned_to_user_id')
                    ->label('Assigned to')
                    ->schema([
                        \Filament\Forms\Components\Select::make('assigned_to_user_id')
                            ->label('Assigned to')
                            ->options(fn (): array => self::assignableUserOptions())
                            ->searchable(),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $assignedToUserId = $data['assigned_to_user_id'] ?? null;

                        if (! is_numeric($assignedToUserId)) {
                            return $query;
                        }

                        return $query->whereHas('notice', fn (Builder $query): Builder => $query->where('assigned_to_user_id', (int) $assignedToUserId));
                    }),
                Filter::make('mine')
                    ->label('Only mine')
                    ->query(function (Builder $query): Builder {
                        $userId = auth()->id();

                        if ($userId === null) {
                            return $query;
                        }

                        return $query->whereHas('notice', fn (Builder $query): Builder => $query->where('assigned_to_user_id', $userId));
                    }),
                Filter::make('unassigned')
                    ->query(fn (Builder $query): Builder => $query->whereHas('notice', fn (Builder $query): Builder => $query->whereNull('assigned_to_user_id'))),
            ])
            ->recordActions([
                \Filament\Actions\Action::make('open_notice')
                    ->label('Open notice')
                    ->url(fn (NoticeAttention $record): string => NoticeResource::getUrl('view', ['record' => $record->notice]))
                    ->openUrlInNewTab(false),
                \Filament\Actions\Action::make('mark_as_read')
                    ->label('Mark as read')
                    ->visible(fn (NoticeAttention $record): bool => $record->is_new)
                    ->action(function (NoticeAttention $record): void {
                        app(DoffinNoticeAttentionService::class)->markAsRead(
                            $record->notice,
                            $record->department,
                            self::currentActor(),
                        );
                    }),
            ]);
    }

    public static function getNavigationLabel(): string
    {
        return __('procynia.attention.resource');
    }

    public static function getEloquentQuery(): Builder
    {
        $context = app(CustomerContext::class);
        $query = $context->scopeCustomerOwned(parent::getEloquentQuery());
        $departmentId = self::currentActor()?->department_id;

        if ($departmentId !== null) {
            $query->where('department_id', $departmentId);
        }

        return $query;
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

    public static function getNavigationBadge(): ?string
    {
        $query = app(CustomerContext::class)
            ->scopeCustomerOwned(NoticeAttention::query()->where('is_new', true));
        $departmentId = self::currentActor()?->department_id;

        if ($departmentId !== null) {
            $query->where('department_id', $departmentId);
        }

        $count = $query->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNoticeAttentions::route('/'),
        ];
    }

    private static function currentActor(): ?User
    {
        $currentUser = auth()->user();

        return $currentUser instanceof User ? $currentUser : null;
    }

    private static function assignableUserOptions(): array
    {
        $context = app(CustomerContext::class);
        $query = User::query()->orderBy('name');

        if (! $context->isInternalAdmin()) {
            $customerId = $context->currentCustomerId();

            if ($customerId !== null) {
                $query->where('customer_id', $customerId);
            }
        }

        return $query->pluck('name', 'id')->all();
    }
}
