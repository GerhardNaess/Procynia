<?php

namespace App\Filament\Resources\CustomerUserServiceLevelResource\Pages;

use App\Filament\Resources\CustomerUserServiceLevelResource;
use Filament\Resources\Pages\ViewRecord;

class ViewCustomerUserServiceLevel extends ViewRecord
{
    protected static string $resource = CustomerUserServiceLevelResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
