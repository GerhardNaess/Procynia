<?php

namespace App\Filament\Resources\DocumentTemplateResource\Pages;

use App\Filament\Resources\DocumentTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDocumentTemplates extends ListRecords
{
    protected static string $resource = DocumentTemplateResource::class;

    public function getTitle(): string
    {
        return (string) __('procynia.document_templates.pages.list');
    }

    public function getSubheading(): ?string
    {
        return (string) __('procynia.document_templates.subheading.list');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('procynia.document_templates.pages.create')),
        ];
    }
}
