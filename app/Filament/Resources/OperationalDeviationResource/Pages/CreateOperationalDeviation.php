<?php

namespace App\Filament\Resources\OperationalDeviationResource\Pages;

use App\Filament\Resources\OperationalDeviationResource;
use App\Models\OperationalDeviation;
use Filament\Resources\Pages\CreateRecord;

class CreateOperationalDeviation extends CreateRecord
{
    protected static string $resource = OperationalDeviationResource::class;

    public function getTitle(): string
    {
        return __('procynia.operational_deviations.pages.create');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! filled($data['code'] ?? null)) {
            $data['code'] = OperationalDeviation::nextSuggestedCode();
        }

        return $data;
    }
}
