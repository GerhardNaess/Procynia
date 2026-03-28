<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NoticeResource\Pages\EditNotice;
use App\Filament\Resources\NoticeResource\Pages\ListNotices;
use App\Filament\Resources\NoticeResource\Pages\ViewNotice;
use App\Models\CpvCode;
use App\Models\Department;
use App\Models\Notice;
use App\Models\NoticeCpvCode;
use App\Models\User;
use App\Services\Doffin\DoffinNoticeAttentionService;
use App\Services\Doffin\DoffinNoticeWorkflowService;
use App\Support\CustomerContext;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn as InfolistTableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NoticeResource extends Resource
{
    protected static ?string $model = Notice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('procynia.notice.internal_review_section'))
                    ->columns(2)
                    ->schema([
                        Select::make('internal_status')
                            ->label(__('procynia.notice.internal_status'))
                            ->options([
                                'new' => 'New',
                                'under_review' => 'Under review',
                                'interesting' => 'Interesting',
                                'ignored' => 'Ignored',
                            ])
                            ->default('new')
                            ->required(),
                        Select::make('assigned_to_user_id')
                            ->label(__('procynia.notice.assigned_to'))
                            ->options(fn (): array => self::assignableUserOptions())
                            ->searchable()
                            ->preload(),
                        Textarea::make('internal_comment')
                            ->label(__('procynia.notice.internal_comment'))
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                return $query
                    ->orderByRaw("
                    CASE
                        WHEN internal_status = 'new' THEN 0
                        WHEN internal_status = 'under_review' THEN 1
                        WHEN internal_status = 'interesting' THEN 2
                        WHEN internal_status = 'ignored' THEN 3
                        ELSE 4
                    END
                ")
                    ->orderByRaw(self::scoreOrderExpression().' DESC');
            })
            ->defaultSort('relevance_score', 'desc')
            ->columns([
                TextColumn::make('notice_id')
                    ->label(__('procynia.notice.notice_id'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('title')
                    ->label(__('procynia.notice.title'))
                    ->searchable()
                    ->limit(70)
                    ->wrap(),
                TextColumn::make('status')
                    ->label(__('procynia.notice.status'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('publication_date')
                    ->label(__('procynia.notice.publication_date'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                TextColumn::make('deadline')
                    ->label(__('procynia.notice.deadline'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                TextColumn::make('buyer_name')
                    ->label(__('procynia.notice.buyer_name'))
                    ->searchable()
                    ->limit(40)
                    ->wrap(),
                TextColumn::make('assignedTo.name')
                    ->label(__('procynia.notice.assigned_to'))
                    ->placeholder('Unassigned')
                    ->sortable(),
                TextColumn::make('internal_status')
                    ->label(__('procynia.notice.internal_status'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('status_changed_at')
                    ->label(__('procynia.notice.status_changed_at'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                TextColumn::make('department_relevance_score')
                    ->label(__('procynia.notice.score'))
                    ->state(fn (Notice $record): int => self::currentCustomerScore($record))
                    ->numeric()
                    ->tooltip(fn (Notice $record): ?string => self::scoreTooltip($record))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw(
                        self::scoreOrderExpression().' '.strtoupper($direction),
                    )),
                TextColumn::make('relevance_level')
                    ->badge()
                    ->sortable(),
                TextColumn::make('parsed_at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                TextColumn::make('downloaded_at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('relevance_level')
                    ->options(fn (): array => Notice::query()
                        ->whereNotNull('relevance_level')
                        ->distinct()
                        ->orderBy('relevance_level')
                        ->pluck('relevance_level', 'relevance_level')
                        ->all()),
                SelectFilter::make('status')
                    ->options(fn (): array => Notice::query()
                        ->whereNotNull('status')
                        ->distinct()
                        ->orderBy('status')
                        ->pluck('status', 'status')
                        ->all()),
                SelectFilter::make('assigned_to_user_id')
                    ->label(__('procynia.notice.assigned_to'))
                    ->options(fn (): array => self::assignableUserOptions()),
                SelectFilter::make('internal_status')
                    ->label(__('procynia.notice.internal_status'))
                    ->options([
                        'new' => 'New',
                        'under_review' => 'Under review',
                        'interesting' => 'Interesting',
                        'ignored' => 'Ignored',
                    ]),
                Filter::make('mine')
                    ->label('Only mine')
                    ->query(function (Builder $query): Builder {
                        $userId = auth()->id();

                        if ($userId === null) {
                            return $query;
                        }

                        return $query->where('assigned_to_user_id', $userId);
                    }),
                Filter::make('unassigned')
                    ->query(fn (Builder $query): Builder => $query->whereNull('assigned_to_user_id')),
                SelectFilter::make('notice_type')
                    ->label(__('procynia.notice.notice_type'))
                    ->options(fn (): array => Notice::query()
                        ->whereNotNull('notice_type')
                        ->distinct()
                        ->orderBy('notice_type')
                        ->pluck('notice_type', 'notice_type')
                        ->all()),
                Filter::make('buyer_name')
                    ->schema([
                        \Filament\Forms\Components\TextInput::make('buyer_name')
                            ->label('Buyer name'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $buyerName = trim((string) ($data['buyer_name'] ?? ''));

                        if ($buyerName === '') {
                            return $query;
                        }

                        return $query->where('buyer_name', 'ilike', "%{$buyerName}%");
                    }),
                Filter::make('publication_date')
                    ->schema([
                        \Filament\Forms\Components\DatePicker::make('published_from')
                            ->label('Published from'),
                        \Filament\Forms\Components\DatePicker::make('published_until')
                            ->label('Published until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['published_from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('publication_date', '>=', $date))
                            ->when($data['published_until'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('publication_date', '<=', $date));
                    }),
                Filter::make('deadline')
                    ->schema([
                        \Filament\Forms\Components\DatePicker::make('deadline_from')
                            ->label('Deadline from'),
                        \Filament\Forms\Components\DatePicker::make('deadline_until')
                            ->label('Deadline until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['deadline_from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('deadline', '>=', $date))
                            ->when($data['deadline_until'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('deadline', '<=', $date));
                    }),
                Filter::make('status_changed_at')
                    ->label('Status changed')
                    ->schema([
                        \Filament\Forms\Components\DatePicker::make('status_changed_from')
                            ->label('Changed from'),
                        \Filament\Forms\Components\DatePicker::make('status_changed_until')
                            ->label('Changed until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['status_changed_from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('status_changed_at', '>=', $date))
                            ->when($data['status_changed_until'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('status_changed_at', '<=', $date));
                    }),
            ])
            ->recordActions([
                \Filament\Actions\ViewAction::make(),
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\Action::make('mark_interesting')
                    ->label('Interesting')
                    ->action(function (Notice $record): void {
                        app(DoffinNoticeWorkflowService::class)->updateStatus(
                            $record,
                            'interesting',
                            null,
                            self::currentActor(),
                        );
                    }),
                \Filament\Actions\Action::make('mark_ignored')
                    ->label('Ignore')
                    ->action(function (Notice $record): void {
                        app(DoffinNoticeWorkflowService::class)->updateStatus(
                            $record,
                            'ignored',
                            null,
                            self::currentActor(),
                        );
                    }),
                \Filament\Actions\Action::make('mark_seen')
                    ->label('Mark seen')
                    ->visible(fn (): bool => self::currentActor()?->department_id !== null)
                    ->action(function (Notice $record): void {
                        $actor = self::currentActor();

                        if (! $actor instanceof User || $actor->department === null) {
                            return;
                        }

                        app(DoffinNoticeAttentionService::class)->markAsRead(
                            $record,
                            $actor->department,
                            $actor,
                        );
                    }),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('procynia.notice.notice_section'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('notice_id')->label(__('procynia.notice.notice_id')),
                        TextEntry::make('title')
                            ->label(__('procynia.notice.title'))
                            ->columnSpanFull()
                            ->wrap(),
                        TextEntry::make('description')
                            ->label(__('procynia.notice.description'))
                            ->columnSpanFull()
                            ->wrap(),
                        TextEntry::make('notice_type')->label(__('procynia.notice.notice_type')),
                        TextEntry::make('notice_subtype')->label(__('procynia.notice.notice_subtype')),
                        TextEntry::make('status')->label(__('procynia.notice.status')),
                        TextEntry::make('publication_date')->label(__('procynia.notice.publication_date'))->dateTime('Y-m-d H:i'),
                        TextEntry::make('issue_date')->label(__('procynia.notice.issue_date'))->dateTime('Y-m-d H:i'),
                        TextEntry::make('deadline')->label(__('procynia.notice.deadline'))->dateTime('Y-m-d H:i'),
                        TextEntry::make('estimated_value_amount')->label(__('procynia.notice.estimated_value_amount'))->numeric(2),
                        TextEntry::make('estimated_value_currency')->label(__('procynia.notice.estimated_value_currency')),
                    ]),
                Section::make(__('procynia.notice.buyer_section'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('buyer_name')->label(__('procynia.notice.buyer_name')),
                        TextEntry::make('buyer_org_number')->label(__('procynia.notice.buyer_org_number')),
                        TextEntry::make('buyer_city')->label(__('procynia.notice.buyer_city')),
                        TextEntry::make('buyer_postal_code')->label(__('procynia.notice.buyer_postal_code')),
                        TextEntry::make('buyer_region_code')->label(__('procynia.notice.buyer_region_code')),
                        TextEntry::make('buyer_country_code')->label(__('procynia.notice.buyer_country_code')),
                    ]),
                Section::make(__('procynia.notice.contact_section'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('contact_name')->label(__('procynia.notice.contact_name')),
                        TextEntry::make('contact_email')->label(__('procynia.notice.contact_email'))->copyable(),
                        TextEntry::make('contact_phone')->label(__('procynia.notice.contact_phone')),
                    ]),
                Section::make(__('procynia.notice.relevance_section'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('relevance_score')->label(__('procynia.notice.score'))->numeric(),
                        TextEntry::make('relevance_level')->label(__('procynia.notice.relevance_level'))->badge(),
                        TextEntry::make('downloaded_at')->label(__('procynia.notice.downloaded_at'))->dateTime('Y-m-d H:i'),
                        TextEntry::make('parsed_at')->label(__('procynia.notice.parsed_at'))->dateTime('Y-m-d H:i'),
                    ]),
                Section::make(__('procynia.notice.score_breakdown_section'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('breakdown_total_score')
                            ->label('Total score')
                            ->state(fn (Notice $record): int => (int) ($record->relevance_score ?? 0))
                            ->numeric(),
                        TextEntry::make('breakdown_level')
                            ->label('Relevance level')
                            ->state(fn (Notice $record): string => (string) ($record->relevance_level ?? 'low'))
                            ->badge(),
                        TextEntry::make('breakdown_data_note')
                            ->label('Data note')
                            ->state(fn (Notice $record): ?string => self::breakdownDataNote($record))
                            ->visible(fn (Notice $record): bool => self::breakdownDataNote($record) !== null)
                            ->columnSpanFull()
                            ->wrap(),
                        TextEntry::make('breakdown_cpv_match')
                            ->label('CPV match')
                            ->state(fn (Notice $record): int => self::breakdownNumber($record, 'cpv_match'))
                            ->numeric(),
                        TextEntry::make('breakdown_keyword_match')
                            ->label('Keyword match')
                            ->state(fn (Notice $record): int => self::breakdownNumber($record, 'keyword_match'))
                            ->numeric(),
                        TextEntry::make('breakdown_deadline_bonus')
                            ->label('Deadline bonus')
                            ->state(fn (Notice $record): int => self::breakdownNumber($record, 'deadline_bonus'))
                            ->numeric(),
                        TextEntry::make('breakdown_type_bonus')
                            ->label('Type bonus')
                            ->state(fn (Notice $record): int => self::breakdownNumber($record, 'type_bonus'))
                            ->numeric(),
                        TextEntry::make('breakdown_status_bonus')
                            ->label('Status bonus')
                            ->state(fn (Notice $record): int => self::breakdownNumber($record, 'status_bonus'))
                            ->numeric(),
                        TextEntry::make('breakdown_learning_adjustment')
                            ->label('Learning adjustment')
                            ->state(fn (Notice $record): int => self::breakdownNumber($record, 'learning_adjustment'))
                            ->numeric(),
                        TextEntry::make('breakdown_learning_sample_size')
                            ->label('Learning sample size')
                            ->state(fn (Notice $record): int => self::breakdownNumber($record, 'learning_sample_size'))
                            ->numeric(),
                        TextEntry::make('breakdown_watch_profile')
                            ->label('Watch Profile')
                            ->state(fn (Notice $record): string => self::watchProfileLabel($record)),
                        TextEntry::make('breakdown_department')
                            ->label('Department')
                            ->state(fn (Notice $record): string => self::watchProfileDepartmentLabel($record)),
                        TextEntry::make('breakdown_used_watch_profile_rules')
                            ->label('Used WatchProfile rules')
                            ->state(fn (Notice $record): string => self::usedWatchProfileRulesLabel($record)),
                        TextEntry::make('breakdown_matched_cpv_codes')
                            ->label('Matched CPV codes')
                            ->state(fn (Notice $record): string => self::breakdownList($record, 'matched_cpv_codes'))
                            ->columnSpanFull()
                            ->wrap(),
                        RepeatableEntry::make('breakdown_matched_cpv_rules')
                            ->label('Matched CPV rules')
                            ->state(fn (Notice $record): array => self::breakdownRows($record, 'matched_cpv_rules'))
                            ->contained(false)
                            ->schema([
                                TextEntry::make('code')
                                    ->label('Code')
                                    ->badge(),
                                TextEntry::make('weight')
                                    ->label('Weight')
                                    ->numeric(),
                            ])
                            ->placeholder('None')
                            ->columnSpanFull(),
                        TextEntry::make('breakdown_matched_keywords')
                            ->label('Matched keywords')
                            ->state(fn (Notice $record): string => self::breakdownList($record, 'matched_keywords'))
                            ->columnSpanFull()
                            ->wrap(),
                        RepeatableEntry::make('breakdown_matched_keyword_rules')
                            ->label('Matched keyword rules')
                            ->state(fn (Notice $record): array => self::breakdownRows($record, 'matched_keyword_rules'))
                            ->contained(false)
                            ->schema([
                                TextEntry::make('keyword')
                                    ->label('Keyword')
                                    ->wrap(),
                                TextEntry::make('weight')
                                    ->label('Weight')
                                    ->numeric(),
                            ])
                            ->placeholder('None')
                            ->columnSpanFull(),
                        TextEntry::make('breakdown_applied_rules')
                            ->label('Applied rules')
                            ->state(fn (Notice $record): string => self::breakdownList($record, 'applied_rules'))
                            ->wrap()
                            ->columnSpanFull(),
                    ]),
                Section::make(__('procynia.notice.workflow_section'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('internal_status')
                            ->label('Internal status')
                            ->badge(),
                        TextEntry::make('assignedTo.name')
                            ->label('Assigned to')
                            ->placeholder('Unassigned'),
                        TextEntry::make('status_changed_at')
                            ->label('Status changed')
                            ->dateTime('Y-m-d H:i'),
                        TextEntry::make('statusChangedBy.name')
                            ->label('Status changed by')
                            ->placeholder('Unknown'),
                        TextEntry::make('decisionByUser.name')
                            ->label('Decision by')
                            ->placeholder('Unknown'),
                        TextEntry::make('internal_comment')
                            ->label('Internal comment')
                            ->columnSpanFull()
                            ->wrap()
                            ->placeholder('No internal comment'),
                    ]),
                Section::make(__('procynia.notice.department_routing_section'))
                    ->schema([
                        TextEntry::make('visible_to_departments_display')
                            ->label('Visible to departments')
                            ->state(fn (Notice $record): string => self::departmentVisibilityDisplay($record)),
                        TextEntry::make('department_routing_note')
                            ->label('Routing note')
                            ->state(fn (Notice $record): string => self::departmentRoutingNote($record))
                            ->columnSpanFull()
                            ->wrap(),
                        RepeatableEntry::make('department_routing_rows')
                            ->label('Department scores')
                            ->state(fn (Notice $record): array => self::departmentRoutingRows($record))
                            ->contained(false)
                            ->schema([
                                TextEntry::make('department_name')
                                    ->label('Department')
                                    ->placeholder('Unknown')
                                    ->wrap(),
                                TextEntry::make('department_id')
                                    ->label('Department ID')
                                    ->numeric(),
                                TextEntry::make('score')
                                    ->label('Score')
                                    ->numeric(),
                                TextEntry::make('level')
                                    ->label('Level')
                                    ->badge()
                                    ->placeholder('None'),
                                TextEntry::make('watch_profile_id')
                                    ->label('Winning WatchProfile ID')
                                    ->placeholder('None'),
                                TextEntry::make('watch_profile_name')
                                    ->label('Winning WatchProfile name')
                                    ->placeholder('None')
                                    ->wrap(),
                                TextEntry::make('cpv_match')
                                    ->label('CPV match')
                                    ->numeric(),
                                TextEntry::make('keyword_match')
                                    ->label('Keyword match')
                                    ->numeric(),
                                TextEntry::make('explanation')
                                    ->label('Explanation')
                                    ->columnSpanFull()
                                    ->wrap(),
                            ])
                            ->placeholder('No department scores stored')
                            ->columnSpanFull(),
                    ]),
                Section::make(__('procynia.notice.debug_section'))
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        TextEntry::make('debug_score_breakdown')
                            ->label('Raw score_breakdown')
                            ->state(fn (Notice $record): string => self::jsonDisplay($record->score_breakdown, 'No score_breakdown stored'))
                            ->wrap()
                            ->columnSpanFull(),
                        TextEntry::make('debug_department_scores')
                            ->label('Raw department_scores')
                            ->state(fn (Notice $record): string => self::jsonDisplay($record->department_scores, 'No department_scores stored'))
                            ->wrap()
                            ->columnSpanFull(),
                        TextEntry::make('debug_visible_to_departments')
                            ->label('Raw visible_to_departments')
                            ->state(fn (Notice $record): string => self::jsonDisplay($record->visible_to_departments, 'No visible_to_departments stored'))
                            ->wrap()
                            ->columnSpanFull(),
                    ]),
                Section::make(__('procynia.notice.decision_history_section'))
                    ->schema([
                        RepeatableEntry::make('decision_rows')
                            ->state(fn (Notice $record): array => self::decisionRows($record))
                            ->contained(false)
                            ->schema([
                                TextEntry::make('created_at')
                                    ->label('Changed at')
                                    ->dateTime('Y-m-d H:i'),
                                TextEntry::make('user.name')
                                    ->label('User')
                                    ->placeholder('Unknown'),
                                TextEntry::make('department.name')
                                    ->label('Department')
                                    ->placeholder('Unknown'),
                                TextEntry::make('from_status')
                                    ->label('From')
                                    ->placeholder('None'),
                                TextEntry::make('to_status')
                                    ->label('To'),
                                TextEntry::make('comment')
                                    ->label('Comment')
                                    ->columnSpanFull()
                                    ->wrap()
                                    ->placeholder('No comment'),
                            ])
                            ->placeholder('No decision history stored')
                            ->columnSpanFull(),
                    ]),
                Section::make(__('procynia.notice.cpv_codes_section'))
                    ->schema([
                        RepeatableEntry::make('cpv_code_rows')
                            ->hiddenLabel()
                            ->state(fn (Notice $record): array => self::cpvRows($record))
                            ->contained(false)
                            ->table([
                                InfolistTableColumn::make('CPV'),
                            ])
                            ->schema([
                                TextEntry::make('display')
                                    ->hiddenLabel()
                                    ->wrap()
                                    ->placeholder('No catalog match found'),
                            ])
                            ->placeholder('No CPV codes stored')
                            ->columnSpanFull(),
                    ]),
                Section::make(__('procynia.notice.lots_section'))
                    ->schema([
                        RepeatableEntry::make('lots')
                            ->hiddenLabel()
                            ->contained(false)
                            ->schema([
                                TextEntry::make('lot_title')
                                    ->label('Title')
                                    ->wrap(),
                                TextEntry::make('lot_description')
                                    ->label('Description')
                                    ->columnSpanFull()
                                    ->wrap(),
                            ])
                            ->placeholder('No lots stored')
                            ->columnSpanFull(),
                    ]),
                Section::make(__('procynia.notice.raw_xml_section'))
                    ->schema([
                        TextEntry::make('rawXml.xml_content')
                            ->label('XML content')
                            ->placeholder('No raw XML stored')
                            ->copyable()
                            ->wrap()
                            ->columnSpanFull()
                            ->extraAttributes([
                                'style' => 'max-height: 32rem; overflow: auto;',
                            ]),
                    ]),
                Section::make(__('procynia.notice.sync_logs_section'))
                    ->schema([
                        RepeatableEntry::make('recent_sync_logs')
                            ->hiddenLabel()
                            ->state(fn (Notice $record): array => self::recentSyncLogs($record))
                            ->contained(false)
                            ->schema([
                                TextEntry::make('job_type')
                                    ->label('Step')
                                    ->badge(),
                                TextEntry::make('status')
                                    ->label('Result')
                                    ->badge(),
                                TextEntry::make('message')
                                    ->label('Details')
                                    ->columnSpanFull()
                                    ->wrap(),
                                TextEntry::make('started_at_display')
                                    ->label('Started'),
                                TextEntry::make('finished_at_display')
                                    ->label('Finished'),
                            ])
                            ->placeholder('No activity logged')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function getNavigationLabel(): string
    {
        return __('procynia.notice.resource');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $actor = self::currentActor();
        $context = app(CustomerContext::class);

        if (! $actor instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        if ($context->isInternalAdmin($actor)) {
            return $query;
        }

        $departmentIds = self::currentVisibleDepartmentIds($actor);

        if ($departmentIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $query) use ($departmentIds): void {
            foreach ($departmentIds as $departmentId) {
                $query->orWhereJsonContains('visible_to_departments', $departmentId);
            }
        });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNotices::route('/'),
            'view' => ViewNotice::route('/{record}'),
            'edit' => EditNotice::route('/{record}/edit'),
        ];
    }

    private static function currentCustomerScore(Notice $record): int
    {
        $scoreData = self::currentDepartmentScoreData($record);

        if ($scoreData === null) {
            return (int) ($record->relevance_score ?? 0);
        }

        return (int) data_get($scoreData, 'score', 0);
    }

    private static function scoreOrderExpression(): string
    {
        $departmentIds = self::currentVisibleDepartmentIds();

        if ($departmentIds === []) {
            return 'COALESCE(relevance_score, 0)';
        }

        if (count($departmentIds) === 1) {
            $departmentId = $departmentIds[0];

            return "COALESCE((department_scores -> '{$departmentId}' ->> 'score')::int, relevance_score, 0)";
        }

        $expressions = array_map(
            static fn (int $departmentId): string => "COALESCE((department_scores -> '{$departmentId}' ->> 'score')::int, 0)",
            $departmentIds,
        );

        return 'GREATEST('.implode(', ', $expressions).')';
    }

    private static function scoreTooltip(Notice $record): ?string
    {
        $departmentScore = self::currentDepartmentScoreData($record);

        if ($departmentScore !== null) {
            $watchProfileName = trim((string) data_get($departmentScore, 'watch_profile_name', ''));
            $departmentName = trim((string) data_get($departmentScore, 'department_name', ''));

            return implode("\n", [
                'Department: '.($departmentName !== '' ? $departmentName : 'None'),
                'Watch Profile: '.($watchProfileName !== '' ? $watchProfileName : 'None'),
                'Score: '.(int) data_get($departmentScore, 'score', $record->relevance_score ?? 0),
                'Level: '.((string) data_get($departmentScore, 'level', $record->relevance_level ?? 'low') ?: 'low'),
                'CPV: '.(int) data_get($departmentScore, 'cpv_match', 0),
                'Keywords: '.(int) data_get($departmentScore, 'keyword_match', 0),
            ]);
        }

        if (empty($record->score_breakdown)) {
            return null;
        }

        return implode("\n", [
            'Watch Profile: '.self::watchProfileLabel($record),
            'CPV: '.self::breakdownNumber($record, 'cpv_match'),
            'Keywords: '.self::breakdownNumber($record, 'keyword_match'),
            'Learning: '.self::breakdownNumber($record, 'learning_adjustment'),
            'Matched CPV: '.self::breakdownList($record, 'matched_cpv_codes'),
            'Matched keywords: '.self::breakdownList($record, 'matched_keywords'),
        ]);
    }

    private static function jsonDisplay(mixed $state, string $emptyText): string
    {
        if ($state === null || $state === [] || $state === '') {
            return $emptyText;
        }

        if (! is_array($state)) {
            return (string) $state;
        }

        return json_encode(
            $state,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ) ?: $emptyText;
    }

    private static function breakdownNumber(Notice $record, string $key): int
    {
        return (int) data_get($record->score_breakdown, $key, 0);
    }

    private static function breakdownList(Notice $record, string $key, string $emptyText = 'None'): string
    {
        $value = data_get($record->score_breakdown, $key);

        if (is_array($value)) {
            return self::listDisplay($value, $emptyText);
        }

        if (! is_string($value) || trim($value) === '' || strtolower(trim($value)) === 'none') {
            return $emptyText;
        }

        return $value;
    }

    private static function breakdownRows(Notice $record, string $key): array
    {
        $value = data_get($record->score_breakdown, $key);

        if (! is_array($value) || $value === []) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $row): bool => is_array($row)));
    }

    private static function watchProfileContext(Notice $record): array
    {
        $context = data_get($record->score_breakdown, 'watch_profile_context');

        if (is_array($context)) {
            return $context;
        }

        return [
            'watch_profile_id' => data_get($record->score_breakdown, 'scoring_context.watch_profile_id'),
            'watch_profile_name' => data_get($record->score_breakdown, 'scoring_context.watch_profile_name'),
            'department_id' => data_get($record->score_breakdown, 'scoring_context.department_id'),
            'department_name' => null,
            'used_watch_profile_rules' => (bool) data_get($record->score_breakdown, 'scoring_context.used_watch_profile', false),
        ];
    }

    private static function isLegacyBreakdown(Notice $record): bool
    {
        return is_array(data_get($record->score_breakdown, 'scoring_context'))
            && ! is_array(data_get($record->score_breakdown, 'watch_profile_context'));
    }

    private static function breakdownDataNote(Notice $record): ?string
    {
        if (! self::isLegacyBreakdown($record)) {
            return null;
        }

        return 'This notice uses a legacy score_breakdown payload. Watch Profile details and weighted rule matches were not stored when it was last scored.';
    }

    private static function watchProfileLabel(Notice $record): string
    {
        $context = self::watchProfileContext($record);
        $watchProfileId = data_get($context, 'watch_profile_id');
        $watchProfileName = trim((string) data_get($context, 'watch_profile_name', ''));

        if ($watchProfileName !== '' && $watchProfileId !== null) {
            return "{$watchProfileName} (#{$watchProfileId})";
        }

        if ($watchProfileName !== '') {
            return $watchProfileName;
        }

        if ($watchProfileId !== null) {
            return "WatchProfile #{$watchProfileId}";
        }

        if (self::isLegacyBreakdown($record)) {
            return 'Not stored in this notice';
        }

        return 'None';
    }

    private static function watchProfileDepartmentLabel(Notice $record): string
    {
        $context = self::watchProfileContext($record);
        $departmentId = data_get($context, 'department_id');
        $departmentName = trim((string) data_get($context, 'department_name', ''));

        if ($departmentName !== '' && $departmentId !== null) {
            return "{$departmentName} (#{$departmentId})";
        }

        if ($departmentName !== '') {
            return $departmentName;
        }

        if ($departmentId !== null) {
            return "Department #{$departmentId}";
        }

        if (self::isLegacyBreakdown($record)) {
            return 'Not stored in this notice';
        }

        return 'None';
    }

    private static function usedWatchProfileRulesLabel(Notice $record): string
    {
        if (self::isLegacyBreakdown($record)) {
            return 'Not stored';
        }

        return (bool) data_get(self::watchProfileContext($record), 'used_watch_profile_rules', false)
            ? 'Yes'
            : 'No';
    }

    private static function departmentVisibilityDisplay(Notice $record): string
    {
        $departmentIds = collect(is_array($record->visible_to_departments) ? $record->visible_to_departments : [])
            ->map(fn (mixed $departmentId): int => (int) $departmentId)
            ->filter(fn (int $departmentId): bool => $departmentId > 0)
            ->filter(fn (int $departmentId): bool => self::isDepartmentVisibleToCurrentCustomer($departmentId))
            ->values()
            ->all();

        if ($departmentIds === []) {
            return self::hasForeignDepartmentRouting($record) ? 'None for current customer' : 'None';
        }

        $departmentNames = self::departmentNameMap($departmentIds);

        return implode(', ', array_map(static function (int $departmentId) use ($departmentNames): string {
            $departmentName = $departmentNames[$departmentId] ?? null;

            return $departmentName !== null
                ? "{$departmentName} (#{$departmentId})"
                : "Department #{$departmentId}";
        }, $departmentIds));
    }

    private static function departmentRoutingNote(Notice $record): string
    {
        if (! is_array($record->department_scores) || $record->department_scores === []) {
            return 'No routed departments are stored for this notice. Department-level non-match reasons are not persisted in the current data model.';
        }

        if (self::departmentRoutingRows($record) === [] && self::hasForeignDepartmentRouting($record)) {
            return 'This notice has routed departments, but none belong to the current customer. Department-level non-match reasons are not persisted in the current data model.';
        }

        return 'Only departments that received this notice are stored here. Department-level non-match reasons are not persisted in the current data model.';
    }

    private static function departmentRoutingRows(Notice $record): array
    {
        if (! is_array($record->department_scores) || $record->department_scores === []) {
            return [];
        }

        $rows = [];
        $departmentNames = self::departmentNameMap(array_map('intval', array_keys($record->department_scores)));

        foreach ($record->department_scores as $departmentId => $scoreData) {
            if (! is_array($scoreData)) {
                continue;
            }

            $departmentId = (int) $departmentId;

            if (! self::isDepartmentVisibleToCurrentCustomer($departmentId)) {
                continue;
            }

            $rows[] = [
                'department_name' => $departmentNames[$departmentId] ?? null,
                'department_id' => $departmentId,
                'score' => (int) data_get($scoreData, 'score', 0),
                'level' => data_get($scoreData, 'level'),
                'watch_profile_id' => data_get($scoreData, 'watch_profile_id'),
                'watch_profile_name' => data_get($scoreData, 'watch_profile_name'),
                'cpv_match' => (int) data_get($scoreData, 'cpv_match', 0),
                'keyword_match' => (int) data_get($scoreData, 'keyword_match', 0),
                'explanation' => self::departmentRoutingExplanation($scoreData),
            ];
        }

        usort($rows, static function (array $left, array $right): int {
            return [$right['score'], $left['department_id']] <=> [$left['score'], $right['department_id']];
        });

        return $rows;
    }

    private static function departmentRoutingExplanation(array $scoreData): string
    {
        $signals = [];

        if (array_key_exists('cpv_match', $scoreData)) {
            $signals[] = 'CPV match '.(int) data_get($scoreData, 'cpv_match', 0);
        }

        if (array_key_exists('keyword_match', $scoreData)) {
            $signals[] = 'Keyword match '.(int) data_get($scoreData, 'keyword_match', 0);
        }

        if ($signals === []) {
            return 'No department-level explanation is stored for this row.';
        }

        return implode(', ', $signals);
    }

    private static function departmentNameMap(array $departmentIds): array
    {
        $departmentIds = array_values(array_unique(array_filter($departmentIds, static fn (int $departmentId): bool => $departmentId > 0)));

        if ($departmentIds === []) {
            return [];
        }

        return Department::query()
            ->whereIn('id', $departmentIds)
            ->pluck('name', 'id')
            ->mapWithKeys(static fn (mixed $name, mixed $id): array => [(int) $id => (string) $name])
            ->all();
    }

    private static function listDisplay(mixed $state, string $emptyText): string
    {
        if (! is_array($state) || $state === []) {
            return $emptyText;
        }

        return implode(', ', array_map(static fn (mixed $value): string => (string) $value, $state));
    }

    private static function cpvRows(Notice $record): array
    {
        $record->cpvCodes->loadMissing('catalogEntry');

        return $record->cpvCodes
            ->map(fn (NoticeCpvCode $cpvCode): array => [
                'display' => self::cpvDisplay($cpvCode),
            ])
            ->all();
    }

    private static function cpvDisplay(NoticeCpvCode $cpvCode): string
    {
        $code = (string) $cpvCode->cpv_code;
        $catalogEntry = $cpvCode->catalogEntry;

        if (! $catalogEntry instanceof CpvCode) {
            return $code;
        }

        $description = app(CustomerContext::class)->cpvDescription($catalogEntry);

        if ($description === null) {
            return "{$code} — Missing catalog translation";
        }

        return "{$code} — {$description}";
    }

    private static function decisionRows(Notice $record): array
    {
        $query = $record->decisions()->with(['user', 'department'])->latest('id');
        $context = app(CustomerContext::class);
        $customerId = $context->currentCustomerId();

        if (! $context->isInternalAdmin() && $customerId !== null) {
            $query->where('customer_id', $customerId);
        }

        return $query->get()
            ->map(static fn ($decision): array => [
                'created_at' => $decision->created_at,
                'user' => ['name' => $decision->user?->name],
                'department' => ['name' => $decision->department?->name],
                'from_status' => $decision->from_status,
                'to_status' => $decision->to_status,
                'comment' => $decision->comment,
            ])
            ->all();
    }

    private static function recentSyncLogs(Notice $record): array
    {
        return $record->syncLogs()
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(fn ($log): array => [
                'job_type' => (string) $log->job_type,
                'status' => (string) $log->status,
                'message' => (string) ($log->message ?? 'No details'),
                'started_at_display' => $log->started_at?->format('Y-m-d H:i') ?? 'Unknown',
                'finished_at_display' => $log->finished_at?->format('Y-m-d H:i') ?? 'Unknown',
            ])
            ->all();
    }

    private static function currentActor(): ?User
    {
        return app(CustomerContext::class)->currentUser();
    }

    private static function currentVisibleDepartmentIds(?User $user = null): array
    {
        $user ??= self::currentActor();
        $context = app(CustomerContext::class);

        if (! $user instanceof User || $context->isInternalAdmin($user)) {
            return [];
        }

        $customerDepartmentIds = $context->customerDepartmentIds($user);
        $departmentId = $user->department_id;

        if ($departmentId !== null && in_array($departmentId, $customerDepartmentIds, true)) {
            return [$departmentId];
        }

        return $customerDepartmentIds;
    }

    private static function currentDepartmentScoreData(Notice $record): ?array
    {
        if (! is_array($record->department_scores) || $record->department_scores === []) {
            return null;
        }

        $departmentIds = self::currentVisibleDepartmentIds();

        if ($departmentIds === []) {
            return null;
        }

        $departmentNames = self::departmentNameMap($departmentIds);
        $best = null;

        foreach ($departmentIds as $departmentId) {
            $scoreData = data_get($record->department_scores, (string) $departmentId);

            if (! is_array($scoreData)) {
                continue;
            }

            $candidate = $scoreData + [
                'department_id' => $departmentId,
                'department_name' => $departmentNames[$departmentId] ?? null,
            ];

            if ($best === null || (int) data_get($candidate, 'score', 0) > (int) data_get($best, 'score', 0)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    private static function isDepartmentVisibleToCurrentCustomer(int $departmentId): bool
    {
        $actor = self::currentActor();
        $context = app(CustomerContext::class);

        if (! $actor instanceof User || $context->isInternalAdmin($actor)) {
            return true;
        }

        return in_array($departmentId, $context->customerDepartmentIds($actor), true);
    }

    private static function hasForeignDepartmentRouting(Notice $record): bool
    {
        $departmentIds = collect(is_array($record->visible_to_departments) ? $record->visible_to_departments : [])
            ->map(fn (mixed $departmentId): int => (int) $departmentId)
            ->filter(fn (int $departmentId): bool => $departmentId > 0)
            ->values()
            ->all();

        if ($departmentIds === []) {
            return false;
        }

        foreach ($departmentIds as $departmentId) {
            if (self::isDepartmentVisibleToCurrentCustomer($departmentId)) {
                return false;
            }
        }

        return true;
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
