<?php

namespace App\Filament\Resources\OperationalRunbookResource\Pages;

use App\Filament\Resources\OperationalRunbookResource;
use Filament\Resources\Pages\EditRecord;

class EditOperationalRunbook extends EditRecord
{
    protected static string $resource = OperationalRunbookResource::class;

    protected static ?string $title = 'Rediger driftsrutine';

    protected function getRedirectUrl(): ?string
    {
        return OperationalRunbookResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
