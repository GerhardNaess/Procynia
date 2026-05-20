<?php

namespace App\Filament\Resources\DocumentTemplateResource\Pages;

use App\Filament\Resources\DocumentTemplateResource;
use App\Models\DocumentTemplate;
use Filament\Resources\Pages\EditRecord;

class EditDocumentTemplate extends EditRecord
{
    protected static string $resource = DocumentTemplateResource::class;

    public function getTitle(): string
    {
        return (string) __('procynia.document_templates.pages.edit');
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $userId = auth()->id();

        $data['customer_id'] = $this->record->customer_id;
        $data['template_type'] = DocumentTemplate::TEMPLATE_TYPE_WORD_EXPORT;
        $data['file_disk'] = 'local';
        $data['created_by_user_id'] = $this->record->created_by_user_id;
        $data['updated_by_user_id'] = $userId;

        return $data;
    }
}
