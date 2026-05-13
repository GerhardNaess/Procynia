<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OperationalRunbookResource\Pages\CreateOperationalRunbook;
use App\Filament\Resources\OperationalRunbookResource\Pages\EditOperationalRunbook;
use App\Filament\Resources\OperationalRunbookResource\Pages\ListOperationalRunbooks;
use App\Filament\Resources\OperationalRunbookResource\Pages\ViewOperationalRunbook;
use App\Models\OperationalRunbook;
use App\Models\OperationalRunbookAttachment;
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
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
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

    protected static ?string $navigationLabel = 'Driftsrutiner';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Drift';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'driftsrutine';

    protected static ?string $pluralModelLabel = 'driftsrutiner';

    protected static ?string $recordTitleAttribute = 'title';

    public static function canAccess(): bool
    {
        return app(CustomerContext::class)->isInternalAdmin();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Rutinedetaljer')
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')
                            ->label('Tittel')
                            ->required()
                            ->maxLength(255),
                        Select::make('category')
                            ->label('Kategori')
                            ->options(fn (): array => self::categoryOptions())
                            ->default('docker')
                            ->required(),
                        TextInput::make('sort_order')
                            ->label('Sortering')
                            ->numeric()
                            ->default(0),
                        Toggle::make('is_active')
                            ->label('Aktiv')
                            ->default(true),
                        Textarea::make('summary')
                            ->label('Sammendrag')
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),
                Section::make('Dokumenter og vedlegg')
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('attachments')
                            ->relationship()
                            ->defaultItems(0)
                            ->hiddenLabel()
                            ->addActionLabel('Legg til vedlegg')
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

                                return 'Nytt vedlegg';
                            })
                            ->schema([
                                FileUpload::make('stored_path')
                                    ->label('Fil')
                                    ->required()
                                    ->disk('local')
                                    ->visibility('private')
                                    ->directory(fn (?OperationalRunbook $record): string => self::attachmentDirectory($record))
                                    ->getUploadedFileNameForStorageUsing(fn (FileUpload $component, TemporaryUploadedFile $file): string => Str::ulid().'__'.$file->getClientOriginalName())
                                    ->acceptedFileTypes(self::attachmentAcceptedMimeTypes())
                                    ->maxSize(self::attachmentMaxSizeKb())
                                    ->previewable(false)
                                    ->downloadable(false)
                                    ->openable(false)
                                    ->columnSpanFull(),
                                TextInput::make('description')
                                    ->label('Beskrivelse')
                                    ->maxLength(255)
                                    ->columnSpanFull(),
                                TextInput::make('sort_order')
                                    ->label('Sortering')
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
            ->components([
                Section::make('Rutinedetaljer')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('title')
                            ->label('Tittel'),
                        TextEntry::make('category')
                            ->label('Kategori')
                            ->badge()
                            ->state(fn (OperationalRunbook $record): string => self::categoryLabel($record->category)),
                        TextEntry::make('sort_order')
                            ->label('Sortering')
                            ->numeric(),
                        TextEntry::make('is_active')
                            ->label('Aktiv')
                            ->badge()
                            ->state(fn (OperationalRunbook $record): string => $record->is_active ? 'Aktiv' : 'Inaktiv'),
                        TextEntry::make('summary')
                            ->label('Sammendrag')
                            ->columnSpanFull()
                            ->wrap()
                            ->placeholder('Ingen sammendrag'),
                        TextEntry::make('created_at')
                            ->label('Opprettet')
                            ->dateTime('d.m.Y H:i'),
                        TextEntry::make('updated_at')
                            ->label('Sist revidert')
                            ->dateTime('d.m.Y H:i'),
                    ]),
                Section::make('Vedlegg')
                    ->schema([
                        RepeatableEntry::make('attachments')
                            ->label('Vedlegg')
                            ->placeholder('Mangler dokument')
                            ->table([
                                TableColumn::make('Filnavn'),
                                TableColumn::make('Type'),
                                TableColumn::make('Størrelse'),
                                TableColumn::make('Opplastet'),
                                TableColumn::make('Lenke'),
                            ])
                            ->schema([
                                TextEntry::make('original_name')
                                    ->label('Filnavn')
                                    ->weight('medium')
                                    ->wrap(),
                                TextEntry::make('file_type_label')
                                    ->label('Type')
                                    ->badge(),
                                TextEntry::make('formatted_size')
                                    ->label('Størrelse')
                                    ->placeholder('Ukjent'),
                                TextEntry::make('created_at')
                                    ->label('Opplastet')
                                    ->dateTime('d.m.Y H:i'),
                                TextEntry::make('download_action')
                                    ->label('Lenke')
                                    ->state('Last ned dokument')
                                    ->color('primary')
                                    ->icon('heroicon-o-arrow-down-tray')
                                    ->url(fn (OperationalRunbookAttachment $record): string => route('admin.operational-runbook-attachments.download', ['attachment' => $record])),
                            ])
                            ->columnSpanFull(),
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
                    ->label('Tittel')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category')
                    ->label('Kategori')
                    ->badge()
                    ->state(fn (OperationalRunbook $record): string => self::categoryLabel($record->category))
                    ->sortable(),
                TextColumn::make('attachments_count')
                    ->label('Antall vedlegg')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'danger')
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? $state.' vedlegg' : 'Mangler dokument')
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->label('Sortering')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('is_active')
                    ->label('Aktiv')
                    ->badge()
                    ->state(fn (OperationalRunbook $record): string => $record->is_active ? 'Aktiv' : 'Inaktiv')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Oppdatert')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label('Kategori')
                    ->options(fn (): array => self::categoryOptions()),
                TernaryFilter::make('is_active')
                    ->label('Aktiv')
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
        return [
            'general' => 'Generelt',
            'docker' => 'Docker',
            'backup_recovery' => 'Backup og recovery',
            'deploy' => 'Deploy',
            'monitoring' => 'Overvåkning',
            'security' => 'Sikkerhet',
            'integrations' => 'Integrasjoner',
            'infrastructure' => 'Infrastruktur',
            'incidents' => 'Hendelser og beredskap',
        ];
    }

    public static function categoryLabel(string $category): string
    {
        return match ($category) {
            'general' => 'Generelt',
            'docker' => 'Docker',
            'backup_recovery' => 'Backup og recovery',
            'deploy' => 'Deploy',
            'monitoring' => 'Overvåkning',
            'security' => 'Sikkerhet',
            'integrations' => 'Integrasjoner',
            'infrastructure' => 'Infrastruktur',
            'incidents' => 'Hendelser og beredskap',
        };
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
