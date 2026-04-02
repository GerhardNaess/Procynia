<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerResource;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;

    private string $initialSystemOwnerName = '';

    private string $initialSystemOwnerEmail = '';

    private string $temporaryPassword = '';

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->initialSystemOwnerName = Str::squish((string) ($data['initial_system_owner_name'] ?? ''));
        $this->initialSystemOwnerEmail = Str::lower(trim((string) ($data['initial_system_owner_email'] ?? '')));

        unset($data['initial_system_owner_name'], $data['initial_system_owner_email']);

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): Model {
            /** @var \App\Models\Customer $customer */
            $customer = static::getModel()::query()->create($data);

            $this->temporaryPassword = Str::password(16);

            User::query()->create([
                'name' => $this->initialSystemOwnerName,
                'email' => $this->initialSystemOwnerEmail,
                'password' => Hash::make($this->temporaryPassword),
                'role' => User::customerRoleForBidRole(User::BID_ROLE_SYSTEM_OWNER),
                'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
                'bid_manager_scope' => null,
                'is_active' => true,
                'customer_id' => $customer->id,
            ]);

            return $customer;
        });
    }

    protected function afterCreate(): void
    {
        Notification::make()
            ->title('Kunden ble opprettet')
            ->body("Første systemeier: {$this->initialSystemOwnerEmail}. Midlertidig passord: {$this->temporaryPassword}")
            ->success()
            ->persistent()
            ->send();
    }
}
