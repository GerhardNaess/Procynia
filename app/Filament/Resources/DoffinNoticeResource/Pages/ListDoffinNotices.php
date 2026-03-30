<?php

namespace App\Filament\Resources\DoffinNoticeResource\Pages;

use App\Filament\Resources\DoffinNoticeResource;
use Filament\Resources\Pages\ListRecords;

class ListDoffinNotices extends ListRecords
{
    protected static string $resource = DoffinNoticeResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
