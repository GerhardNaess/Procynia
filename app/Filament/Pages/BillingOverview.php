<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HasAdminPageHelp;
use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\CustomerResource\Pages\ManageCustomerBilling;
use App\Models\Customer;
use App\Support\CustomerContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class BillingOverview extends Page
{
    use HasAdminPageHelp;

    protected string $view = 'filament.pages.billing-overview';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?string $navigationLabel = 'Oversikt';

    protected static string|UnitEnum|null $navigationGroup = 'Fakturering';

    protected static ?int $navigationSort = 1;

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

    protected function getHeaderActions(): array
    {
        return [
            $this->buildPageHelpAction(
                static::fetchPageHelp('admin.billing.overview')
            ),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return 'Oversikt';
    }

    public function getTitle(): string
    {
        return 'Fakturering';
    }
}
