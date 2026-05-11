<?php

namespace App\Filament\Resources\BillingPriceResource\Pages;

use App\Filament\Resources\BillingPriceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBillingPrice extends CreateRecord
{
    protected static string $resource = BillingPriceResource::class;

    protected static ?string $title = 'Ny tjeneste';

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (blank($data['billing_product_id'] ?? null)) {
            $selectedProductId = request()->integer('billing_product_id');

            if ($selectedProductId) {
                $data['billing_product_id'] = $selectedProductId;
            }
        }

        return BillingPriceResource::normalizeFormData($data);
    }
}
