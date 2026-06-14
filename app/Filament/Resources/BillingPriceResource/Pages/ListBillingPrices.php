<?php

namespace App\Filament\Resources\BillingPriceResource\Pages;

use App\Filament\Concerns\HasAdminPageHelp;
use App\Filament\Resources\BillingPriceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBillingPrices extends ListRecords
{
    use HasAdminPageHelp;

    protected static string $resource = BillingPriceResource::class;

    protected static ?string $title = 'Tjenestekatalog';

    public function getSubheading(): ?string
    {
        return 'Alle varer og tjenester som kan selges til kunder.';
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->buildPageHelpAction(
                static::fetchPageHelp('admin.tjenestekatalog')
            ),
            CreateAction::make()
                ->label('Legg til'),
        ];
    }
}
