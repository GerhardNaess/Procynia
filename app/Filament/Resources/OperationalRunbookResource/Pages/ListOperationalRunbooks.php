<?php

namespace App\Filament\Resources\OperationalRunbookResource\Pages;

use App\Filament\Resources\OperationalRunbookResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOperationalRunbooks extends ListRecords
{
    protected static string $resource = OperationalRunbookResource::class;

    protected static ?string $title = 'Driftsrutiner';

    public function getSubheading(): ?string
    {
        return 'Databasebaserte driftsrutiner for Procynia.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Ny driftsrutine'),
        ];
    }
}
