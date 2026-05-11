<?php

namespace App\Filament\Resources\BillingPriceResource\Pages;

use App\Filament\Resources\BillingPriceResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Str;

class ViewBillingPrice extends ViewRecord
{
    protected static string $resource = BillingPriceResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->name;
    }

    public function getSubheading(): ?string
    {
        return BillingPriceResource::lineItemTypeLabel($this->getRecord());
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label('Rediger'),
            Action::make('duplicate')
                ->label('Dupliser')
                ->action(fn (): mixed => $this->duplicateLineItem()),
            Action::make('deactivate')
                ->label('Sett inaktiv')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->getRecord()->is_active)
                ->action(fn (): mixed => $this->deactivateLineItem()),
        ];
    }

    public function duplicateLineItem(): mixed
    {
        $record = $this->getRecord();
        $duplicate = $record->replicate();
        $duplicate->key = $this->duplicateKey($record->key);
        $duplicate->stripe_price_id = null;
        $duplicate->is_active = false;
        $duplicate->save();

        Notification::make()
            ->title('Tjeneste duplisert')
            ->success()
            ->body('Duplikatet er opprettet som en inaktiv oppføring uten Stripe Price ID.')
            ->send();

        return $this->redirect(BillingPriceResource::getUrl('edit', ['record' => $duplicate]), navigate: true);
    }

    public function deactivateLineItem(): mixed
    {
        $record = $this->getRecord();
        $record->forceFill([
            'is_active' => false,
        ])->save();

        Notification::make()
            ->title('Tjeneste deaktivert')
            ->success()
            ->send();

        return $this->redirect(BillingPriceResource::getUrl('view', ['record' => $record]), navigate: true);
    }

    private function duplicateKey(string $key): string
    {
        $duplicateKey = $key . '_copy_' . Str::lower(Str::random(6));

        return substr($duplicateKey, 0, 255);
    }
}
