<?php

namespace App\Filament\Resources\BillingPriceResource\Pages;

use App\Filament\Resources\BillingPriceResource;
use App\Filament\Resources\BillingProductResource;
use Filament\Resources\Pages\EditRecord;

class EditBillingPrice extends EditRecord
{
    protected static string $resource = BillingPriceResource::class;

    protected static ?string $title = 'Rediger';

    /** @var array<string, mixed> */
    protected array $pendingProductUpdate = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $product = $this->getRecord()->product;

        if ($product) {
            $data['product_description'] = $product->description ?? '';
            $data['product_sort_order'] = $product->sort_order;
            $data['product_category_label'] = BillingProductResource::categoryLabel((string) ($product->category ?? ''));
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $productData = [];

        if (array_key_exists('product_description', $data)) {
            $productData['description'] = $data['product_description'] ?? '';
        }

        if (isset($data['product_sort_order'])) {
            $productData['sort_order'] = (int) $data['product_sort_order'];
        }

        $this->pendingProductUpdate = $productData;

        unset(
            $data['product_description'],
            $data['product_sort_order'],
        );

        return BillingPriceResource::normalizeFormData($data);
    }

    protected function afterSave(): void
    {
        if ($this->pendingProductUpdate !== []) {
            $product = $this->getRecord()->product;

            if ($product) {
                $product->fill($this->pendingProductUpdate)->save();
            }
        }
    }

    protected function getRedirectUrl(): ?string
    {
        return BillingPriceResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
