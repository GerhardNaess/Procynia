<?php

namespace App\Filament\Resources\OperationalRunbookResource\Pages;

use App\Filament\Concerns\HasAdminPageHelp;
use App\Filament\Resources\OperationalRunbookResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOperationalRunbooks extends ListRecords
{
    use HasAdminPageHelp;

    protected static string $resource = OperationalRunbookResource::class;

    protected static ?string $title = 'Driftsrutiner';

    public function getSubheading(): ?string
    {
        return 'Databasebaserte driftsrutiner for Procynia.';
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->buildPageHelpAction(
                static::fetchPageHelp('admin.operational_runbooks')
            ),
            CreateAction::make()
                ->label('Ny driftsrutine'),
        ];
    }
}
