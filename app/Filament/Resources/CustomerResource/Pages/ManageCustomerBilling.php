<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerResource;
use App\Models\Customer;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;

class ManageCustomerBilling extends Page
{
    protected static string $resource = CustomerResource::class;

    protected string $view = 'filament.pages.manage-customer-billing';

    public Customer $record;

    public ?array $subscriptionData = null;

    public array $invoices = [];

    public string $selectedPlan = 'monthly';

    public function mount(Customer $record): void
    {
        $this->record = $record;
        $this->loadStripeData();
    }

    public function getTitle(): string
    {
        return 'Abonnement — ' . $this->record->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('assign_plan')
                ->label('Tildel abonnement')
                ->icon('heroicon-o-credit-card')
                ->form([
                    \Filament\Forms\Components\Select::make('plan')
                        ->label('Plan')
                        ->options(collect(config('billing.plans'))->mapWithKeys(
                            fn ($p, $k) => [$k => $p['label']]
                        ))
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $planKey = $data['plan'];
                    $priceId = config("billing.plans.{$planKey}.stripe_price_id");

                    if (! $priceId) {
                        Notification::make()
                            ->title('Stripe Price ID mangler i konfigurasjon')
                            ->danger()
                            ->send();
                        return;
                    }

                    $customer = $this->record;

                    if ($customer->subscribed('default')) {
                        $customer->subscription('default')->swap($priceId);
                    } else {
                        $customer->newSubscription('default', $priceId)->create();
                    }

                    $customer->update(['subscription_plan' => $planKey]);

                    $this->loadStripeData();

                    Notification::make()
                        ->title('Abonnement oppdatert')
                        ->success()
                        ->send();
                })
                ->visible(fn () => filled(config('billing.plans.monthly.stripe_price_id'))),

            Action::make('cancel_subscription')
                ->label('Avslutt abonnement')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record->subscription('default')?->cancel();
                    $this->loadStripeData();

                    Notification::make()
                        ->title('Abonnement avsluttes ved periodeslutt')
                        ->success()
                        ->send();
                })
                ->visible(fn () => $this->record->subscribed('default')
                    && ! ($this->subscriptionData['cancel_at_period_end'] ?? false)),

            Action::make('resume_subscription')
                ->label('Gjenoppta abonnement')
                ->icon('heroicon-o-arrow-path')
                ->color('success')
                ->action(function (): void {
                    $this->record->subscription('default')?->resume();
                    $this->loadStripeData();

                    Notification::make()
                        ->title('Abonnement gjenopptatt')
                        ->success()
                        ->send();
                })
                ->visible(fn () => $this->record->subscription('default')?->onGracePeriod() ?? false),

            Action::make('sync_stripe_customer')
                ->label('Opprett Stripe-kunde')
                ->icon('heroicon-o-user-plus')
                ->action(function (): void {
                    $this->record->createOrGetStripeCustomer([
                        'name' => $this->record->name,
                    ]);
                    $this->loadStripeData();

                    Notification::make()
                        ->title('Stripe-kunde synkronisert')
                        ->success()
                        ->send();
                })
                ->visible(fn () => blank($this->record->stripe_id)),
        ];
    }

    private function loadStripeData(): void
    {
        $customer = $this->record->fresh();
        $subscription = $customer->subscription('default');

        if ($subscription) {
            $stripeSubscription = rescue(
                fn () => $subscription->asStripeSubscription(),
                null,
                false,
            );

            $this->subscriptionData = [
                'status' => $subscription->stripe_status,
                'plan' => $customer->subscription_plan,
                'plan_label' => config("billing.plans.{$customer->subscription_plan}.label", $customer->subscription_plan),
                'current_period_end' => $stripeSubscription
                    ? date('d.m.Y', $stripeSubscription->current_period_end)
                    : null,
                'cancel_at_period_end' => $stripeSubscription?->cancel_at_period_end ?? false,
                'trial_ends_at' => $customer->trial_ends_at?->format('d.m.Y'),
                'stripe_id' => $customer->stripe_id,
            ];
        }

        $this->invoices = rescue(
            fn () => $customer->invoices()->map(fn ($invoice) => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'amount' => $invoice->total(),
                'currency' => strtoupper($invoice->rawInvoice()->currency),
                'status' => $invoice->paid ? 'Betalt' : 'Utestående',
                'date' => $invoice->date()->format('d.m.Y'),
                'pdf_url' => $invoice->invoice_pdf,
            ])->values()->all(),
            [],
            false,
        );
    }
}
