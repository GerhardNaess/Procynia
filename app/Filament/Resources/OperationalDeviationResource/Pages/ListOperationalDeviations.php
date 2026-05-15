<?php

namespace App\Filament\Resources\OperationalDeviationResource\Pages;

use App\Filament\Resources\OperationalDeviationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOperationalDeviations extends ListRecords
{
    protected static string $resource = OperationalDeviationResource::class;

    public function getTitle(): string
    {
        return __('procynia.operational_deviations.pages.list');
    }

    public function getSubheading(): ?string
    {
        return __('procynia.operational_deviations.subheading.list');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('procynia.operational_deviations.actions.create')),
        ];
    }
}
