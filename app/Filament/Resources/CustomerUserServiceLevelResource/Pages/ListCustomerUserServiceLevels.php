<?php

namespace App\Filament\Resources\CustomerUserServiceLevelResource\Pages;

use App\Filament\Resources\CustomerUserServiceLevelResource;
use Filament\Resources\Pages\ListRecords;

class ListCustomerUserServiceLevels extends ListRecords
{
    protected static string $resource = CustomerUserServiceLevelResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
