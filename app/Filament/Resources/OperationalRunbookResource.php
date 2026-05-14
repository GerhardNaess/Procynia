<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OperationalRunbookResource\Pages\CreateOperationalRunbook;
use App\Filament\Resources\OperationalRunbookResource\Pages\EditOperationalRunbook;
use App\Filament\Resources\OperationalRunbookResource\Pages\ListOperationalRunbooks;
use App\Filament\Resources\OperationalRunbookResource\Pages\ViewOperationalRunbook;
use App\Models\OperationalRunbook;
use App\Support\CustomerContext;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use UnitEnum;

class OperationalRunbookResource extends Resource
{
    protected static ?string $model = OperationalRunbook::class;

    protected static ?string $navigationLabel = null;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = null;

    protected static ?string $pluralModelLabel = null;

    public static function getNavigationLabel(): string
    {
        return __('procynia.operational_runbooks.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('procynia.operational_runbooks.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('procynia.operational_runbooks.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('procynia.operational_runbooks.plural_model_label');
    }

    protected static ?string $recordTitleAttribute = 'title';

    public static function canAccess(): bool
    {
        return app(CustomerContext::class)->isInternalAdmin();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('procynia.operational_runbooks.sections.details'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')
                            ->label(__('procynia.operational_runbooks.fields.title'))
                            ->required()
                            ->maxLength(255),
                        Select::make('category')
                            ->label(__('procynia.operational_runbooks.fields.category'))
                            ->options(fn (): array => self::categoryOptions())
                            ->default('docker')
                            ->required(),
                        TextInput::make('sort_order')
                            ->label(__('procynia.operational_runbooks.fields.sort_order'))
                            ->numeric()
                            ->default(0),
                        Toggle::make('is_active')
                            ->label(__('procynia.operational_runbooks.fields.active'))
                            ->default(true),
                        Textarea::make('summary')
                            ->label(__('procynia.operational_runbooks.fields.summary'))
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),
                Section::make(__('procynia.operational_runbooks.sections.documents'))
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('attachments')
                            ->relationship()
                            ->defaultItems(0)
                            ->hiddenLabel()
                            ->addActionLabel(__('procynia.operational_runbooks.actions.add_attachment'))
                            ->columnSpanFull()
                            ->columns(3)
                            ->itemLabel(function (array $state): string {
                                $originalName = trim((string) ($state['original_name'] ?? ''));

                                if ($originalName !== '') {
                                    return $originalName;
                                }

                                $storedPath = trim((string) ($state['stored_path'] ?? ''));

                                if ($storedPath !== '' && Str::contains($storedPath, '__')) {
                                    $label = (string) Str::after($storedPath, '__');

                                    if ($label !== '') {
                                        return $label;
                                    }
                                }

                                return __('procynia.operational_runbooks.empty_states.new_attachment');
                            })
                            ->schema([
                                FileUpload::make('stored_path')
                                    ->label(__('procynia.operational_runbooks.fields.file'))
                                    ->required()
                                    ->disk('local')
                                    ->visibility('private')
                                    ->directory(function ($livewire): string {
                                        $record = $livewire->getRecord();

                                        return self::attachmentDirectory($record instanceof OperationalRunbook ? $record : null);
                                    })
                                    ->getUploadedFileNameForStorageUsing(fn (TemporaryUploadedFile $file): string => Str::ulid().'__'.$file->getClientOriginalName())
                                    ->acceptedFileTypes(self::attachmentAcceptedMimeTypes())
                                    ->maxSize(self::attachmentMaxSizeKb())
                                    ->previewable(false)
                                    ->downloadable(false)
                                    ->openable(false)
                                    ->columnSpanFull(),
                                Textarea::make('description')
                                    ->label(__('procynia.operational_runbooks.fields.description'))
                                    ->rows(4)
                                    ->columnSpanFull(),
                                TextInput::make('sort_order')
                                    ->label(__('procynia.operational_runbooks.fields.sort_order'))
                                    ->numeric()
                                    ->default(0),
                            ])
                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => self::normalizeAttachmentData($data))
                            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => self::normalizeAttachmentData($data)),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('procynia.operational_runbooks.sections.summary'))
                    ->compact()
                    ->visible(fn (OperationalRunbook $record): bool => filled($record->summary))
                    ->schema([
                        TextEntry::make('summary')
                            ->hiddenLabel()
                            ->wrap()
                            ->columnSpanFull(),
                    ]),
                Section::make(__('procynia.operational_runbooks.sections.attachments'))
                    ->description(__('procynia.operational_runbooks.help.attachments'))
                    ->schema([
                        ViewEntry::make('attachments')
                            ->hiddenLabel()
                            ->view('filament.infolists.attachment-table')
                            ->columnSpanFull(),
                    ]),
                Section::make(__('procynia.operational_runbooks.sections.details'))
                    ->compact()
                    ->columns(4)
                    ->schema([
                        TextEntry::make('title')
                            ->label(__('procynia.operational_runbooks.fields.title'))
                            ->columnSpan(2),
                        TextEntry::make('category')
                            ->label(__('procynia.operational_runbooks.fields.category'))
                            ->badge()
                            ->state(fn (OperationalRunbook $record): string => self::categoryLabel($record->category)),
                        TextEntry::make('is_active')
                            ->label(__('procynia.operational_runbooks.fields.status'))
                            ->badge()
                            ->state(fn (OperationalRunbook $record): string => $record->is_active
                                ? __('procynia.operational_runbooks.fields.active')
                                : __('procynia.operational_runbooks.fields.inactive')),
                        TextEntry::make('created_at')
                            ->label(__('procynia.operational_runbooks.fields.created_at'))
                            ->dateTime('d.m.Y H:i'),
                        TextEntry::make('updated_at')
                            ->label(__('procynia.operational_runbooks.fields.updated_at'))
                            ->dateTime('d.m.Y H:i'),
                        TextEntry::make('sort_order')
                            ->label(__('procynia.operational_runbooks.fields.sort_order'))
                            ->numeric(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('attachments'))
            ->defaultSort('sort_order')
            ->recordUrl(fn (OperationalRunbook $record): string => static::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('title')
                    ->label(__('procynia.operational_runbooks.fields.title'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category')
                    ->label(__('procynia.operational_runbooks.fields.category'))
                    ->badge()
                    ->state(fn (OperationalRunbook $record): string => self::categoryLabel($record->category))
                    ->sortable(),
                TextColumn::make('attachments_count')
                    ->label(__('procynia.operational_runbooks.fields.attachment_count'))
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'danger')
                    ->formatStateUsing(fn (int $state): string => $state > 0
                        ? (string) __('procynia.operational_runbooks.table.attachment_badge', ['count' => $state])
                        : (string) __('procynia.operational_runbooks.table.missing_document'))
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->label(__('procynia.operational_runbooks.fields.sort_order'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('is_active')
                    ->label(__('procynia.operational_runbooks.fields.status'))
                    ->badge()
                    ->state(fn (OperationalRunbook $record): string => $record->is_active
                        ? __('procynia.operational_runbooks.fields.active')
                        : __('procynia.operational_runbooks.fields.inactive'))
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label(__('procynia.operational_runbooks.fields.updated_at'))
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label(__('procynia.operational_runbooks.fields.category'))
                    ->options(fn (): array => self::categoryOptions()),
                TernaryFilter::make('is_active')
                    ->label(__('procynia.operational_runbooks.filters.active_label'))
                    ->trueLabel(__('procynia.operational_runbooks.filters.active_true'))
                    ->falseLabel(__('procynia.operational_runbooks.filters.active_false')),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label(__('procynia.operational_runbooks.actions.view')),
                EditAction::make()
                    ->label(__('procynia.operational_runbooks.actions.edit')),
            ]);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->orderByDesc('is_active')
            ->orderBy('category')
            ->orderBy('sort_order')
            ->orderBy('title');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOperationalRunbooks::route('/'),
            'create' => CreateOperationalRunbook::route('/create'),
            'edit' => EditOperationalRunbook::route('/{record}/edit'),
            'view' => ViewOperationalRunbook::route('/{record}'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function categoryOptions(): array
    {
        $keys = ['general', 'docker', 'backup_recovery', 'deploy', 'monitoring', 'security', 'integrations', 'infrastructure', 'incidents'];

        return array_combine($keys, array_map(
            fn (string $key): string => (string) __('procynia.operational_runbooks.categories.'.$key),
            $keys,
        ));
    }

    public static function categoryLabel(string $category): string
    {
        return (string) __('procynia.operational_runbooks.categories.'.$category, [], null) ?: $category;
    }

    /**
     * @return array<int, string>
     */
    public static function attachmentAcceptedMimeTypes(): array
    {
        return [
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/pdf',
            'image/png',
            'image/jpeg',
            'image/webp',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            'text/plain',
        ];
    }

    public static function attachmentMaxSizeKb(): int
    {
        return 20 * 1024;
    }

    public static function attachmentDirectory(?OperationalRunbook $record = null): string
    {
        $baseDirectory = 'operational-runbooks';
        $scope = $record?->exists
            ? 'runbook-'.$record->getKey()
            : 'draft-'.((string) (auth()->id() ?? 'guest')).'-'.((string) (session()->getId() ?: 'session'));

        return trim($baseDirectory.'/'.$scope, '/');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeAttachmentData(array $data): array
    {
        $storedPath = trim((string) ($data['stored_path'] ?? ''));

        if ($storedPath === '') {
            return $data;
        }

        $baseName = basename($storedPath);
        $originalName = Str::contains($baseName, '__')
            ? Str::after($baseName, '__')
            : $baseName;

        $disk = Storage::disk('local');
        $exists = $disk->exists($storedPath);
        $mimeType = $exists ? (string) $disk->mimeType($storedPath) : 'application/octet-stream';
        $sizeBytes = $exists ? (int) $disk->size($storedPath) : 0;

        $data['original_name'] = $data['original_name'] ?? $originalName;
        $data['mime_type'] = $data['mime_type'] ?? $mimeType;
        $data['size_bytes'] = $data['size_bytes'] ?? $sizeBytes;
        $data['uploaded_by_user_id'] = $data['uploaded_by_user_id'] ?? auth()->id();
        $data['description'] = filled($data['description'] ?? null) ? trim((string) $data['description']) : null;
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        return $data;
    }
}
