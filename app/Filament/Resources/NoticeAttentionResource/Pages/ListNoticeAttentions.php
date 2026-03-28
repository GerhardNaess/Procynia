<?php

namespace App\Filament\Resources\NoticeAttentionResource\Pages;

use App\Filament\Resources\NoticeAttentionResource;
use Filament\Resources\Pages\ListRecords;

class ListNoticeAttentions extends ListRecords
{
    protected static string $resource = NoticeAttentionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
