<?php

namespace App\Filament\Resources\AdminPageHelpResource\Pages;

use App\Filament\Resources\AdminPageHelpResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAdminPageHelps extends ListRecords
{
    protected static string $resource = AdminPageHelpResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
