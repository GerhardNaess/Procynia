<?php

namespace App\Filament\Resources\CustomerUserServiceLevelResource\Pages;

use App\Filament\Concerns\HasAdminPageHelp;
use App\Filament\Resources\CustomerUserServiceLevelResource;
use Filament\Resources\Pages\ListRecords;

class ListCustomerUserServiceLevels extends ListRecords
{
    use HasAdminPageHelp;

    protected static string $resource = CustomerUserServiceLevelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->buildPageHelpAction(
                static::fetchPageHelp('admin.billing.user_licenses')
            ),
        ];
    }
}
