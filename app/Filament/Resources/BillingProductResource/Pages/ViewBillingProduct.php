<?php

namespace App\Filament\Resources\BillingProductResource\Pages;

use App\Filament\Resources\BillingProductResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewBillingProduct extends ViewRecord
{
    protected static string $resource = BillingProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label('Rediger'),
        ];
    }
}
