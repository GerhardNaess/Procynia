<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AdminPageHelpResource\Pages\CreateAdminPageHelp;
use App\Filament\Resources\AdminPageHelpResource\Pages\EditAdminPageHelp;
use App\Filament\Resources\AdminPageHelpResource\Pages\ListAdminPageHelps;
use App\Models\AdminPageHelp;
use App\Support\CustomerContext;
use BackedEnum;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class AdminPageHelpResource extends Resource
{
    protected static ?string $model = AdminPageHelp::class;

    protected static ?string $navigationLabel = 'Hjelpetekster';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Drift';

    protected static ?int $navigationSort = 9;

    protected static ?string $modelLabel = 'hjelpetekst';

    protected static ?string $pluralModelLabel = 'hjelpetekster';

    public static function canAccess(): bool
    {
        return app(CustomerContext::class)->isInternalAdmin();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identitet')
                    ->columns(2)
                    ->schema([
                        TextInput::make('page_key')
                            ->label('Sidenøkkel')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->placeholder('admin.ai_forbruk')
                            ->helperText('Teknisk nøkkel som kobler hjelpeteksten til en admin-side.')
                            ->columnSpanFull(),
                        TextInput::make('title')
                            ->label('Tittel')
                            ->required()
                            ->maxLength(255),
                        Toggle::make('is_active')
                            ->label('Aktiv')
                            ->default(true),
                    ]),

                Section::make('Innhold')
                    ->schema([
                        Textarea::make('description')
                            ->label('Kort beskrivelse')
                            ->rows(2)
                            ->maxLength(500),
                        Textarea::make('intro')
                            ->label('Intro-tekst')
                            ->rows(3)
                            ->maxLength(1000),
                    ]),

                Section::make('Seksjoner')
                    ->schema([
                        Repeater::make('sections')
                            ->label('')
                            ->schema([
                                TextInput::make('title')
                                    ->label('Seksjonstitel')
                                    ->required()
                                    ->maxLength(255),
                                Repeater::make('items')
                                    ->label('Punkter')
                                    ->schema([
                                        TextInput::make('title')
                                            ->label('Punkt-tittel')
                                            ->maxLength(255),
                                        Textarea::make('text')
                                            ->label('Tekst')
                                            ->required()
                                            ->rows(2)
                                            ->maxLength(1000),
                                    ])
                                    ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                                    ->collapsible()
                                    ->collapsed()
                                    ->reorderable()
                                    ->addActionLabel('Legg til punkt'),
                            ])
                            ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                            ->collapsible()
                            ->collapsed()
                            ->reorderable()
                            ->addActionLabel('Legg til seksjon'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('page_key')
            ->columns([
                TextColumn::make('page_key')
                    ->label('Sidenøkkel')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('title')
                    ->label('Tittel')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Aktiv')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Sist oppdatert')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListAdminPageHelps::route('/'),
            'create' => CreateAdminPageHelp::route('/create'),
            'edit'   => EditAdminPageHelp::route('/{record}/edit'),
        ];
    }
}
