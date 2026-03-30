<?php

namespace App\Filament\Resources\DoffinSupplierResource\Pages;

use App\Filament\Resources\DoffinSupplierResource;
use Filament\Resources\Pages\ListRecords;

class ListDoffinSuppliers extends ListRecords
{
    protected static string $resource = DoffinSupplierResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
