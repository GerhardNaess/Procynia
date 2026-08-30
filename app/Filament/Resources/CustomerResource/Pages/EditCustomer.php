<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('billing')
                ->label('Abonnement og tjenester')
                ->icon('heroicon-o-credit-card')
                ->url(fn () => CustomerResource::getUrl('billing', ['record' => $this->record])),
            Action::make('ai_control')
                ->label(__('procynia.ai_admin.actions.open'))
                ->icon('heroicon-o-cpu-chip')
                ->url(fn () => CustomerResource::getUrl('ai-control', ['record' => $this->record])),
        ];
    }
}
