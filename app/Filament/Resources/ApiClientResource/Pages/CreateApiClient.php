<?php

namespace App\Filament\Resources\ApiClientResource\Pages;

use App\Filament\Resources\ApiClientResource;
use App\Services\Api\ApiClientAdministration;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateApiClient extends CreateRecord
{
    protected static string $resource = ApiClientResource::class;

    /** Through the service, which checks the administrator and records the registration. */
    protected function handleRecordCreation(array $data): Model
    {
        return app(ApiClientAdministration::class)->register(auth()->user(), $data);
    }
}
