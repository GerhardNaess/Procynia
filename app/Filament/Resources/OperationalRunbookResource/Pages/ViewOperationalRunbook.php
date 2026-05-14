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
        $record = $this->getRecord();
        $category = OperationalRunbookResource::categoryLabel((string) $record->category);
        $status = $record->is_active
            ? __('procynia.operational_runbooks.fields.active')
            : __('procynia.operational_runbooks.fields.inactive');
        $updated = $record->updated_at?->format('d.m.Y H:i');
        $count = $record->attachments()->count();
        $attachmentLabel = $count === 1
            ? __('procynia.operational_runbooks.subheading.attachment_singular')
            : __('procynia.operational_runbooks.subheading.attachment_plural', ['count' => $count]);

        $categoryPrefix = __('procynia.operational_runbooks.subheading.category_prefix');
        $statusPrefix = __('procynia.operational_runbooks.subheading.status_prefix');
        $reviewedPrefix = __('procynia.operational_runbooks.subheading.reviewed_prefix');

        return implode(' · ', array_filter([
            "{$categoryPrefix}: {$category}",
            "{$statusPrefix}: {$status}",
            $updated !== null ? "{$reviewedPrefix}: {$updated}" : null,
            $attachmentLabel,
        ]));
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label(__('procynia.operational_runbooks.actions.edit')),
        ];
    }
}
