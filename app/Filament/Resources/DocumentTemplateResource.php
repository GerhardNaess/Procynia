<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DocumentTemplateResource\Pages\CreateDocumentTemplate;
use App\Filament\Resources\DocumentTemplateResource\Pages\EditDocumentTemplate;
use App\Filament\Resources\DocumentTemplateResource\Pages\ListDocumentTemplates;
use App\Models\DocumentTemplate;
use App\Support\CustomerContext;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use UnitEnum;

class DocumentTemplateResource extends Resource
{
    protected static ?string $model = DocumentTemplate::class;

    protected static ?string $navigationLabel = null;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = null;

    protected static ?string $pluralModelLabel = null;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return __('procynia.document_templates.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('procynia.document_templates.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('procynia.document_templates.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('procynia.document_templates.plural_model_label');
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
                Section::make(__('procynia.document_templates.sections.details'))
                    ->columns(2)
                    ->schema([
                        Select::make('customer_id')
                            ->label(__('procynia.document_templates.fields.customer'))
                            ->relationship('customer', 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->helperText(fn (string $operation): ?string => $operation === 'edit'
                                ? __('procynia.document_templates.help.customer_locked')
                                : null)
                            ->disabled(fn (string $operation): bool => $operation === 'edit'),
                        TextInput::make('name')
                            ->label(__('procynia.document_templates.fields.name'))
                            ->required()
                            ->maxLength(255),
                        Textarea::make('description')
                            ->label(__('procynia.document_templates.fields.description'))
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),
                Section::make(__('procynia.document_templates.sections.file'))
                    ->columns(2)
                    ->schema([
                        FileUpload::make('file_path')
                            ->label(__('procynia.document_templates.fields.file'))
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->disk('local')
                            ->visibility('private')
                            ->directory(fn (Get $get): string => self::templateDirectory($get('customer_id')))
                            ->storeFileNamesIn('original_filename')
                            ->getUploadedFileNameForStorageUsing(fn (TemporaryUploadedFile $file): string => Str::ulid().'__'.$file->getClientOriginalName())
                            ->acceptedFileTypes(self::acceptedMimeTypes())
                            ->deletable(false)
                            ->downloadable(false)
                            ->openable(false)
                            ->previewable(false)
                            ->columnSpanFull()
                            ->helperText(__('procynia.document_templates.help.file')),
                    ]),
                Section::make(__('procynia.document_templates.sections.status'))
                    ->columns(2)
                    ->schema([
                        Toggle::make('is_active')
                            ->label(__('procynia.document_templates.fields.active'))
                            ->default(true),
                        Toggle::make('is_default')
                            ->label(__('procynia.document_templates.fields.default'))
                            ->default(false)
                            ->helperText(__('procynia.document_templates.help.default')),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('customer.name')
                    ->label(__('procynia.document_templates.fields.customer'))
                    ->searchable()
                    ->wrap(),
                TextColumn::make('name')
                    ->label(__('procynia.document_templates.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('original_filename')
                    ->label(__('procynia.document_templates.fields.original_filename'))
                    ->searchable()
                    ->placeholder(__('procynia.common.not_available'))
                    ->wrap(),
                TextColumn::make('template_type')
                    ->label(__('procynia.document_templates.fields.template_type'))
                    ->badge()
                    ->state(fn (DocumentTemplate $record): string => DocumentTemplate::templateTypeLabel($record->template_type)),
                TextColumn::make('is_active')
                    ->label(__('procynia.document_templates.fields.active'))
                    ->badge()
                    ->state(fn (DocumentTemplate $record): string => $record->is_active ? __('procynia.common.yes') : __('procynia.common.no')),
                TextColumn::make('is_default')
                    ->label(__('procynia.document_templates.fields.default'))
                    ->badge()
                    ->state(fn (DocumentTemplate $record): string => $record->is_default ? __('procynia.common.yes') : __('procynia.common.no')),
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
                EditAction::make()
                    ->label(__('procynia.document_templates.actions.edit')),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['customer', 'createdBy', 'updatedBy'])
            ->orderByDesc('is_default')
            ->orderByDesc('updated_at');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocumentTemplates::route('/'),
            'create' => CreateDocumentTemplate::route('/create'),
            'edit' => EditDocumentTemplate::route('/{record}/edit'),
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function acceptedMimeTypes(): array
    {
        return [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
            'application/x-zip-compressed',
            'application/octet-stream',
        ];
    }

    private static function templateDirectory(mixed $customerId): string
    {
        $id = is_numeric($customerId) ? (int) $customerId : null;

        return $id !== null && $id > 0
            ? "document-templates/customer-{$id}"
            : 'document-templates/unassigned';
    }
}
