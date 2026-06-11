<?php

namespace App\Filament\Resources\AdminPageHelpResource\Pages;

use App\Filament\Resources\AdminPageHelpResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAdminPageHelp extends EditRecord
{
    protected static string $resource = AdminPageHelpResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
