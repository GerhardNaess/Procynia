<?php

namespace App\Filament\Resources\OperationalRunbookResource\Pages;

use App\Filament\Resources\OperationalRunbookResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewOperationalRunbook extends ViewRecord
{
    protected static string $resource = OperationalRunbookResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->title;
    }

    public function getSubheading(): ?string
    {
        return 'Kategori: '.OperationalRunbookResource::categoryLabel((string) $this->getRecord()->category);
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label('Rediger'),
        ];
    }
}
