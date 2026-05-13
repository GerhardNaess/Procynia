<?php

namespace App\Filament\Resources\OperationalRunbookResource\Pages;

use App\Filament\Resources\OperationalRunbookResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOperationalRunbook extends CreateRecord
{
    protected static string $resource = OperationalRunbookResource::class;

    protected static ?string $title = 'Ny driftsrutine';

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['category'] = $data['category'] ?? 'docker';

        return $data;
    }
}
