<?php

namespace App\Filament\Resources\SyncLogResource\Pages;

use App\Filament\Concerns\HasAdminPageHelp;
use App\Filament\Resources\SyncLogResource;
use Filament\Resources\Pages\ListRecords;

class ListSyncLogs extends ListRecords
{
    use HasAdminPageHelp;

    protected static string $resource = SyncLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->buildPageHelpAction(
                static::fetchPageHelp('admin.sync_logs')
            ),
        ];
    }
}
