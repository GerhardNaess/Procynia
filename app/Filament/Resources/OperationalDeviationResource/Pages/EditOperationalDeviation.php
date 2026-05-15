<?php

namespace App\Filament\Resources\OperationalDeviationResource\Pages;

use App\Filament\Resources\OperationalDeviationResource;
use Filament\Resources\Pages\EditRecord;

class EditOperationalDeviation extends EditRecord
{
    protected static string $resource = OperationalDeviationResource::class;

    public function getTitle(): string
    {
        return __('procynia.operational_deviations.pages.edit');
    }

    protected function getRedirectUrl(): ?string
    {
        return OperationalDeviationResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
