<?php

namespace App\Filament\Resources\BillingProductResource\Pages;

use App\Filament\Resources\BillingProductResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBillingProducts extends ListRecords
{
    protected static string $resource = BillingProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Ny tjeneste'),
        ];
    }
}
