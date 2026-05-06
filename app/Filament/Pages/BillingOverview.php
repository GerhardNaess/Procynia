<?php

namespace App\Filament\Pages;

use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\CustomerResource\Pages\ManageCustomerBilling;
use App\Models\Customer;
use App\Support\CustomerContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class BillingOverview extends Page
{
    protected string $view = 'filament.pages.billing-overview';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?int $navigationSort = 2;

    public array $customers = [];

    public function mount(): void
    {
        $this->customers = Customer::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => $customer->name,
                'stripe_id' => $customer->stripe_id,
                'subscription_plan' => $customer->subscription_plan,
                'stripe_status' => $customer->subscription('default')?->stripe_status,
                'trial_ends_at' => $customer->trial_ends_at?->format('d.m.Y'),
                'billing_url' => CustomerResource::getUrl('billing', ['record' => $customer]),
            ])
            ->all();
    }

    public static function canAccess(): bool
    {
        return app(CustomerContext::class)->isInternalAdmin();
    }

    public static function getNavigationLabel(): string
    {
        return 'Fakturering';
    }

    public function getTitle(): string
    {
        return 'Fakturering';
    }
}
