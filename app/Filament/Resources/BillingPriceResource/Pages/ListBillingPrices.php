<?php

namespace App\Filament\Resources\BillingPriceResource\Pages;

use App\Filament\Resources\BillingPriceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBillingPrices extends ListRecords
{
    protected static string $resource = BillingPriceResource::class;

    protected static ?string $title = 'Tjenestekatalog';

    public function getSubheading(): ?string
    {
        return 'Alle varer og tjenester som kan selges til kunder.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Legg til'),
        ];
    }
}
