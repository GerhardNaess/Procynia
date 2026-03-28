<?php

namespace App\Filament\Resources\WatchProfileResource\Pages;

use App\Filament\Resources\WatchProfileResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWatchProfile extends EditRecord
{
    protected static string $resource = WatchProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
