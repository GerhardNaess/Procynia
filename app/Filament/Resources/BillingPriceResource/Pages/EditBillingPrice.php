<?php

namespace App\Filament\Resources\BillingPriceResource\Pages;

use App\Filament\Resources\BillingPriceResource;
use Filament\Resources\Pages\EditRecord;

class EditBillingPrice extends EditRecord
{
    protected static string $resource = BillingPriceResource::class;

    protected static ?string $title = 'Rediger varelinje';

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return BillingPriceResource::normalizeFormData($data);
    }

    protected function getRedirectUrl(): ?string
    {
        return BillingPriceResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
