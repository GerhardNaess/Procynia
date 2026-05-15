<?php

namespace App\Filament\Resources\OperationalDeviationResource\Pages;

use App\Filament\Resources\OperationalDeviationResource;
use App\Models\OperationalDeviation;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewOperationalDeviation extends ViewRecord
{
    protected static string $resource = OperationalDeviationResource::class;

    public function getTitle(): string
    {
        $record = $this->getRecord();

        return trim((string) ($record->code.' · '.$record->title));
    }

    public function getSubheading(): ?string
    {
        $record = $this->getRecord();

        return implode(' · ', array_filter([
            __('procynia.operational_deviations.fields.category').': '.OperationalDeviation::categoryLabel($record->category),
            __('procynia.operational_deviations.fields.severity').': '.OperationalDeviation::severityLabel($record->severity),
            __('procynia.operational_deviations.fields.status').': '.OperationalDeviation::statusLabel($record->status),
            $record->updated_at?->format('d.m.Y H:i') ? __('procynia.common.updated_at').': '.$record->updated_at?->format('d.m.Y H:i') : null,
        ]));
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label(__('procynia.operational_deviations.actions.edit')),
        ];
    }
}
