<?php

namespace App\Filament\Resources\ApiClientResource\Pages;

use App\Filament\Resources\ApiClientResource;
use App\Models\ApiClient;
use App\Services\Api\ApiClientAdministration;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditApiClient extends EditRecord
{
    protected static string $resource = ApiClientResource::class;

    /** Name and description only, through the service; the Entra identity is fixed. */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var ApiClient $record */
        return app(ApiClientAdministration::class)->update(auth()->user(), $record, $data);
    }
}
