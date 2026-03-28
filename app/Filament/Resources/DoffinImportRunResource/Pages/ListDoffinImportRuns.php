<?php

namespace App\Filament\Resources\DoffinImportRunResource\Pages;

use App\Filament\Resources\DoffinImportRunResource;
use Filament\Resources\Pages\ListRecords;

class ListDoffinImportRuns extends ListRecords
{
    protected static string $resource = DoffinImportRunResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
