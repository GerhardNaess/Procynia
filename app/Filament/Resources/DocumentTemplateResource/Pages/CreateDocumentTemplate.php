<?php

namespace App\Filament\Resources\DocumentTemplateResource\Pages;

use App\Filament\Resources\DocumentTemplateResource;
use App\Models\DocumentTemplate;
use Filament\Resources\Pages\CreateRecord;

class CreateDocumentTemplate extends CreateRecord
{
    protected static string $resource = DocumentTemplateResource::class;

    public function getTitle(): string
    {
        return (string) __('procynia.document_templates.pages.create');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $userId = auth()->id();

        $data['template_type'] = DocumentTemplate::TEMPLATE_TYPE_WORD_EXPORT;
        $data['file_disk'] = 'local';
        $data['created_by_user_id'] = $userId;
        $data['updated_by_user_id'] = $userId;

        return $data;
    }
}
