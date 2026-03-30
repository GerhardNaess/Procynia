<?php

namespace App\Filament\Resources\DoffinSupplierResource\Pages;

use App\Filament\Resources\DoffinSupplierResource;
use App\Models\DoffinSupplier;
use Filament\Resources\Pages\ViewRecord;

/**
 * Purpose:
 * Render a compact supplier view with a scrollable linked notices panel.
 *
 * Inputs:
 * The selected Doffin supplier record from the resource route.
 *
 * Returns:
 * A Filament resource view page.
 *
 * Side effects:
 * Reads supplier-linked notices for display.
 */
class ViewDoffinSupplier extends ViewRecord
{
    protected static string $resource = DoffinSupplierResource::class;

    protected string $view = 'filament.resources.doffin-supplier-resource.pages.view-doffin-supplier';

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * Purpose:
     * Build compact linked notice rows for the current supplier view.
     *
     * Inputs:
     * None.
     *
     * Returns:
     * array<int, array<string, string|null>>
     *
     * Side effects:
     * Loads linked Doffin notice rows from the database.
     */
    public function noticeRows(): array
    {
        /** @var DoffinSupplier $supplier */
        $supplier = $this->getRecord();

        return $supplier->noticeSuppliers()
            ->with('notice')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($link): array => [
                'notice_record_id' => $link->notice?->getKey(),
                'notice_id' => $link->notice?->notice_id,
                'heading' => $link->notice?->heading,
                'buyer_name' => $link->notice?->buyer_name,
                'publication_date' => $link->notice?->publication_date?->format('Y-m-d H:i'),
                'source' => $link->source,
                'winner_lots' => filled($link->winner_lots_json)
                    ? implode(', ', $link->winner_lots_json)
                    : null,
            ])
            ->all();
    }
}
