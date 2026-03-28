<?php

namespace App\Filament\Resources\WatchProfileResource\Pages;

use App\Filament\Resources\WatchProfileResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWatchProfiles extends ListRecords
{
    protected static string $resource = WatchProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
