<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerResource;
use App\Models\BillingPrice;
use App\Models\BillingProduct;
use App\Models\CustomerBillingLine;
use App\Models\Customer;
use App\Models\User;
use App\Services\Billing\CustomerBillingBasisService;
use App\Services\Billing\BillingService;
use App\Services\SubscriptionService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Throwable;

class ManageCustomerBilling extends Page
{
    protected static string $resource = CustomerResource::class;

    protected string $view = 'filament.pages.manage-customer-billing';

    public Customer $record;

    public ?array $subscriptionData = null;

    public array $invoices = [];

    public array $billingLines = [];

    public array $serviceLevels = [];

    public array $customerSpecificPrices = [];

    public array $billingBasis = [];

    public string $invoiceSort = 'month_desc';

    public int $activeUsersCount = 0;

    public int $openStripeInvoicesCount = 0;

    public ?string $oldestOpenStripeInvoiceLabel = null;

    public int $customerSpecificPricesCount = 0;

    public int $activeCustomerSpecificPricesCount = 0;

    public string $stripeOutstandingTotalLabel = 'Ikke tilgjengelig fra Stripe';

    public string $stripeOverdueTotalLabel = 'Ikke tilgjengelig fra Stripe';

    public string $stripeLastPaymentFailureLabel = 'Ikke tilgjengelig fra Stripe';

    public string $stripeSyncedAtLabel = 'Ikke tilgjengelig fra Stripe';

    public function mount(Customer $record): void
    {
        $this->record = $record;
        $this->loadStripeData();
        $this->loadBillingBasisData();
    }

    public function getTitle(): string
    {
        return 'Abonnement og fakturering — ' . $this->record->name;
    }

    public function getSubheading(): string
    {
        return 'Stripe er økonomisk fasit for subscription, fakturaer, betaling, utestående og forfall. Procynia er fasit for kunde, brukere, tilgang og interne billing-linjer.';
    }

    public function getPageClasses(): array
    {
        return [
            'procynia-billing-page',
            'procynia-billing-overview-page',
        ];
    }

    public function updatedInvoiceSort(): void
    {
        $this->loadStripeData();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync_stripe_customer')
                ->label('Opprett Stripe-kunde')
                ->icon('heroicon-o-credit-card')
                ->action(function (): void {
                    app(BillingService::class)->ensureStripeCustomer($this->record);
                    $this->record = $this->record->fresh();
                    $this->loadStripeData();

                    Notification::make()
                        ->title('Stripe-kunde opprettet')
                        ->success()
                        ->send();
                })
                ->visible(fn () => blank($this->record->stripe_id)),

            ActionGroup::make([
                Action::make('assign_plan')
                    ->label('Endre Stripe-subscription')
                    ->icon('heroicon-o-credit-card')
                    ->form([
                        Select::make('plan')
                            ->label('Stripe-plan')
                            ->options($this->planOptions())
                            ->required()
                            ->default($this->record->subscription_plan ?? 'free'),
                        Radio::make('interval')
                            ->label('Stripe-faktureringsintervall')
                            ->options([
                                'monthly' => 'Månedlig',
                                'yearly' => 'Årlig (spar 33%)',
                            ])
                            ->default($this->record->billing_interval ?? 'monthly')
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $plan = $data['plan'];
                        $interval = $data['interval'];

                        if ($plan === 'enterprise_contact') {
                            Notification::make()
                                ->title('Enterprise håndteres manuelt')
                                ->warning()
                                ->send();
                            return;
                        }

                        try {
                            $service = app(SubscriptionService::class);

                            if (! $this->record->stripe_id) {
                                app(BillingService::class)->ensureStripeCustomer($this->record);
                                $this->record = $this->record->fresh();
                            }

                            $service->subscribe($this->record, $plan, $interval);
                            $this->record = $this->record->fresh();
                            $this->loadStripeData();

                            Notification::make()
                                ->title('Stripe-subscription oppdatert')
                                ->success()
                                ->send();
                        } catch (Throwable $e) {
                            Notification::make()
                                ->title('Feil ved endring av Stripe-subscription')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
                ->label('Stripe-subscription')
                ->icon('heroicon-o-adjustments-horizontal')
                ->button(),

            ActionGroup::make([
                Action::make('assign_service_level')
                    ->label('Tildel Procynia-brukernivå')
                    ->icon('heroicon-o-user-plus')
                    ->form([
                        Select::make('user_id')
                            ->label('Bruker')
                            ->options($this->customerUserOptions())
                            ->searchable()
                            ->required(),
                        Select::make('billing_price_id')
                            ->label('Tjeneste')
                            ->options($this->serviceLevelPriceOptions())
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        try {
                            $service = app(BillingService::class);
                            $price = BillingPrice::query()->findOrFail((int) $data['billing_price_id']);
                            $user = User::query()->whereKey((int) $data['user_id'])->firstOrFail();
                            $assignedBy = auth()->user();

                            $service->assignUserServiceLevel($this->record, $user, $price, $assignedBy instanceof User ? $assignedBy : null);
                            $this->refreshBillingData();

                            Notification::make()
                                ->title('Procynia-brukernivå tildelt')
                                ->success()
                                ->send();
                        } catch (Throwable $e) {
                            Notification::make()
                                ->title('Feil ved tildeling av Procynia-brukernivå')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('remove_service_level')
                    ->label('Fjern Procynia-brukernivå')
                    ->icon('heroicon-o-user-minus')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->form([
                        Select::make('user_id')
                            ->label('Bruker')
                            ->options($this->customerUserOptions())
                            ->searchable()
                            ->required(),
                        Select::make('billing_price_id')
                            ->label('Tjeneste')
                            ->options($this->serviceLevelPriceOptions())
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        try {
                            $service = app(BillingService::class);
                            $price = BillingPrice::query()->findOrFail((int) $data['billing_price_id']);
                            $user = User::query()->whereKey((int) $data['user_id'])->firstOrFail();

                            $service->removeUserServiceLevel($this->record, $user, $price);
                            $this->refreshBillingData();

                            Notification::make()
                                ->title('Procynia-brukernivå fjernet')
                                ->success()
                                ->send();
                        } catch (Throwable $e) {
                            Notification::make()
                                ->title('Feil ved fjerning av Procynia-brukernivå')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
                ->label('Procynia-tilgang')
                ->icon('heroicon-o-users')
                ->button(),

            ActionGroup::make([
                Action::make('add_recurring_line')
                    ->label('Legg til intern billing-linje')
                    ->icon('heroicon-o-plus')
                    ->form([
                        Select::make('billing_price_id')
                            ->label('Pris')
                            ->options($this->recurringServicePriceOptions())
                            ->searchable()
                            ->required(),
                        Select::make('user_id')
                            ->label('Bruker')
                            ->options($this->customerUserOptions())
                            ->searchable(),
                        TextInput::make('quantity')
                            ->label('Antall')
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        try {
                            $service = app(BillingService::class);
                            $price = BillingPrice::query()->findOrFail((int) $data['billing_price_id']);
                            $user = ! blank($data['user_id'] ?? null)
                                ? User::query()->whereKey((int) $data['user_id'])->firstOrFail()
                                : null;

                            $service->addRecurringLine(
                                $this->record,
                                $price,
                                (int) $data['quantity'],
                                $user,
                            );
                            $this->refreshBillingData();

                            Notification::make()
                                ->title('Intern billing-linje lagt til')
                                ->success()
                                ->send();
                        } catch (Throwable $e) {
                            Notification::make()
                                ->title('Feil ved intern billing-linje')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('add_one_time_charge')
                    ->label('Legg til intern engangslinje')
                    ->icon('heroicon-o-receipt-percent')
                    ->form([
                        Select::make('billing_price_id')
                            ->label('Pris')
                            ->options($this->oneTimePriceOptions())
                            ->searchable()
                            ->required(),
                        TextInput::make('description')
                            ->label('Beskrivelse')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('quantity')
                            ->label('Antall')
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required(),
                        Toggle::make('invoice_immediately')
                            ->label('Fakturer umiddelbart')
                            ->default(true),
                    ])
                    ->action(function (array $data): void {
                        try {
                            $service = app(BillingService::class);
                            $price = BillingPrice::query()->findOrFail((int) $data['billing_price_id']);

                            $line = $service->addOneTimeCharge(
                                $this->record,
                                $price,
                                (int) $data['quantity'],
                                (string) $data['description'],
                                (bool) ($data['invoice_immediately'] ?? true),
                            );
                            $this->refreshBillingData();

                            Notification::make()
                                ->title($line->stripe_invoice_id ? 'Intern engangslinje fakturert via Stripe' : 'Intern engangslinje lagt til')
                                ->success()
                                ->send();
                        } catch (Throwable $e) {
                            Notification::make()
                                ->title('Feil ved intern engangslinje')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('invoice_one_time_charges')
                    ->label('Fakturer ventende interne engangslinjer via Stripe')
                    ->icon('heroicon-o-currency-dollar')
                    ->color('success')
                    ->action(function (): void {
                        try {
                            $result = app(BillingService::class)->invoiceOneTimeCharges($this->record);
                            $this->refreshBillingData();

                            Notification::make()
                                ->title($result['invoice'] ? 'Interne engangslinjer ble fakturert via Stripe' : 'Ingen ventende interne engangslinjer')
                                ->success()
                                ->send();
                        } catch (Throwable $e) {
                            Notification::make()
                                ->title('Feil ved fakturering av interne engangslinjer')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
                ->label('Interne billing-linjer')
                ->icon('heroicon-o-banknotes')
                ->button(),

            ActionGroup::make([
                Action::make('add_customer_specific_price')
                    ->label('Legg til kundespesifikk pris')
                    ->icon('heroicon-o-tag')
                    ->form([
                        Select::make('billing_price_id')
                            ->label('Tjeneste / standardpris')
                            ->options($this->customerSpecificPriceOptions())
                            ->searchable()
                            ->preload()
                            ->live()
                            ->required(),
                        Select::make('user_id')
                            ->label('Bruker (valgfritt)')
                            ->options($this->customerUserOptions())
                            ->searchable()
                            ->preload(),
                        Placeholder::make('standard_price_display')
                            ->label('Standardpris')
                            ->content(fn (Get $get): string => $this->selectedStandardPriceLabel($get('billing_price_id')))
                            ->columnSpanFull(),
                        Placeholder::make('standard_interval_display')
                            ->label('Intervall')
                            ->content(fn (Get $get): string => $this->selectedStandardIntervalLabel($get('billing_price_id')))
                            ->columnSpanFull(),
                        TextInput::make('custom_amount')
                            ->label('Avtalt kundepris (øre)')
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->helperText('Bruk øre. 149000 = 1 490 kr.'),
                        TextInput::make('quantity')
                            ->label('Antall')
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required(),
                        Textarea::make('notes')
                            ->label('Internt notat')
                            ->rows(3)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                    ])
                    ->action(function (array $data): void {
                        try {
                            $service = app(BillingService::class);
                            $price = BillingPrice::query()->findOrFail((int) $data['billing_price_id']);
                            $user = ! blank($data['user_id'] ?? null)
                                ? User::query()->whereKey((int) $data['user_id'])->firstOrFail()
                                : null;

                            $service->addCustomerSpecificPrice(
                                $this->record,
                                $price,
                                (int) $data['custom_amount'],
                                (int) $data['quantity'],
                                $user,
                                filled($data['notes'] ?? null) ? (string) $data['notes'] : null,
                            );

                            $this->refreshBillingData();

                            Notification::make()
                                ->title('Kundespesifikk pris lagt til')
                                ->success()
                                ->send();
                        } catch (Throwable $e) {
                            Notification::make()
                                ->title('Feil ved kundespesifikk pris')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('replace_customer_specific_price')
                    ->label('Oppdater kundespesifikk pris')
                    ->icon('heroicon-o-pencil-square')
                    ->form([
                        Select::make('customer_billing_line_id')
                            ->label('Eksisterende prislinje')
                            ->options($this->customerSpecificPriceLineOptions())
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('custom_amount')
                            ->label('Ny avtalt kundepris (øre)')
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->helperText('Bruk øre. 149000 = 1 490 kr.'),
                        TextInput::make('quantity')
                            ->label('Antall')
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required(),
                        Textarea::make('notes')
                            ->label('Internt notat')
                            ->rows(3)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                    ])
                    ->action(function (array $data): void {
                        try {
                            $line = $this->record->billingLines()
                                ->customerSpecificPrice()
                                ->whereKey((int) $data['customer_billing_line_id'])
                                ->firstOrFail();

                            app(BillingService::class)->replaceCustomerSpecificPrice(
                                $line,
                                (int) $data['custom_amount'],
                                (int) $data['quantity'],
                                filled($data['notes'] ?? null) ? (string) $data['notes'] : null,
                            );

                            $this->refreshBillingData();

                            Notification::make()
                                ->title('Kundespesifikk pris oppdatert')
                                ->success()
                                ->send();
                        } catch (Throwable $e) {
                            Notification::make()
                                ->title('Feil ved oppdatering av kundespesifikk pris')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('deactivate_customer_specific_price')
                    ->label('Deaktiver kundespesifikk pris')
                    ->icon('heroicon-o-power')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->form([
                        Select::make('customer_billing_line_id')
                            ->label('Aktiv prislinje')
                            ->options($this->activeCustomerSpecificPriceLineOptions())
                            ->searchable()
                            ->preload()
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        try {
                            $line = $this->record->billingLines()
                                ->customerSpecificPrice()
                                ->where('status', 'active')
                                ->whereKey((int) $data['customer_billing_line_id'])
                                ->firstOrFail();

                            app(BillingService::class)->deactivateCustomerSpecificPrice($line);
                            $this->refreshBillingData();

                            Notification::make()
                                ->title('Kundespesifikk pris deaktivert')
                                ->success()
                                ->send();
                        } catch (Throwable $e) {
                            Notification::make()
                                ->title('Feil ved deaktivering av kundespesifikk pris')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
                ->label('Kundespesifikke priser')
                ->icon('heroicon-o-tag')
                ->button(),

                Action::make('edit_discount')
                ->label('Endre kunderabatt')
                ->icon('heroicon-o-receipt-percent')
                ->button()
                ->form([
                    TextInput::make('billing_discount_percent')
                        ->label('Kunderabatt')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->step(0.01)
                        ->suffix('%')
                        ->default((float) ($this->record->billing_discount_percent ?? 0))
                        ->helperText('Rabatt som gjelder for kundens abonnementer og priser.')
                        ->required(),
                ])
                ->fillForm(['billing_discount_percent' => (float) ($this->record->billing_discount_percent ?? 0)])
                ->action(function (array $data): void {
                    $percent = round((float) $data['billing_discount_percent'], 2);

                    $this->record->forceFill([
                        'billing_discount_percent' => $percent,
                    ])->save();

                    $this->refreshBillingData();

                    Notification::make()
                        ->title('Kunderabatt oppdatert')
                        ->success()
                        ->send();
                }),

            ActionGroup::make([
                Action::make('remove_recurring_line')
                    ->label('Fjern intern billing-linje')
                    ->icon('heroicon-o-minus')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->form([
                        Select::make('billing_line_id')
                            ->label('Linje')
                            ->options($this->activeRecurringLineOptions())
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        try {
                            $line = $this->record->billingLines()->whereKey((int) $data['billing_line_id'])->firstOrFail();
                            app(BillingService::class)->removeRecurringLine($this->record, $line);
                            $this->refreshBillingData();

                            Notification::make()
                                ->title('Intern billing-linje fjernet')
                                ->success()
                                ->send();
                        } catch (Throwable $e) {
                            Notification::make()
                                ->title('Feil ved fjerning av intern billing-linje')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('cancel_subscription')
                    ->label('Avslutt Stripe-subscription')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (): void {
                        app(SubscriptionService::class)->cancel($this->record);
                        $this->record = $this->record->fresh();
                        $this->loadStripeData();

                        Notification::make()
                            ->title('Stripe-subscription avsluttes ved periodeslutt')
                            ->success()
                            ->send();
                    })
                    ->visible(fn () => $this->record->subscribed('default')
                        && ! ($this->subscriptionData['cancel_at_period_end'] ?? false)),

                Action::make('resume_subscription')
                    ->label('Gjenoppta Stripe-subscription')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->action(function (): void {
                        app(SubscriptionService::class)->resume($this->record);
                        $this->record = $this->record->fresh();
                        $this->loadStripeData();

                        Notification::make()
                            ->title('Stripe-subscription gjenopptatt')
                            ->success()
                            ->send();
                    })
                    ->visible(fn () => $this->record->subscription('default')?->onGracePeriod() ?? false),
            ])
                ->label('Risikohandlinger')
                ->icon('heroicon-o-exclamation-triangle')
                ->button(),
        ];
    }

    public function discountButtonLabel(): string
    {
        $percent = (float) ($this->record->billing_discount_percent ?? 0);

        if ($percent <= 0) {
            return 'Kunderabatt: Ingen rabatt';
        }

        return 'Kunderabatt: ' . number_format($percent, 2, ',', ' ') . ' %';
    }

    private function planOptions(): array
    {
        $options = ['free' => 'Gratis (0 kr)'];

        foreach (config('procynia_plans', []) as $key => $plan) {
            if ($key === 'free') {
                continue;
            }

            if ($key === 'enterprise') {
                $options['enterprise_contact'] = 'Enterprise — kontakt oss';
                continue;
            }

            $options[$key] = "{$plan['name']} ({$plan['monthly_price_nok']} kr/mnd · {$plan['yearly_price_nok']} kr/år)";
        }

        return $options;
    }

    private function recurringServicePriceOptions(): array
    {
        return array_merge(
            app(BillingService::class)->recurringPriceOptions(BillingProduct::CATEGORY_USER_SEAT),
            app(BillingService::class)->recurringPriceOptions(BillingProduct::CATEGORY_USER_SERVICE),
            app(BillingService::class)->recurringPriceOptions(BillingProduct::CATEGORY_ADDON),
        );
    }

    private function serviceLevelPriceOptions(): array
    {
        return $this->recurringServicePriceOptions();
    }

    private function oneTimePriceOptions(): array
    {
        return app(BillingService::class)->oneTimePriceOptions();
    }

    private function customerUserOptions(): array
    {
        return $this->record->users()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private function activeRecurringLineOptions(): array
    {
        return collect($this->billingLines)
            ->filter(fn (array $line): bool => ($line['interval'] ?? null) !== BillingPrice::INTERVAL_ONE_TIME)
            ->mapWithKeys(function (array $line): array {
                $label = trim(implode(' · ', array_filter([
                    $line['billing_price'] ?? $line['billing_product'] ?? 'Løpende linje',
                    isset($line['user_name']) ? 'Bruker: '.$line['user_name'] : null,
                    isset($line['quantity']) ? 'Antall: '.$line['quantity'] : null,
                ])));

                return [$line['id'] => $label];
            })
            ->all();
    }

    private function customerSpecificPriceOptions(): array
    {
        return app(BillingService::class)->recurringPriceOptions();
    }

    private function customerSpecificPriceLineOptions(bool $activeOnly = false): array
    {
        return collect($this->customerSpecificPrices)
            ->filter(fn (array $line): bool => ! $activeOnly || ($line['status'] ?? null) === 'active')
            ->mapWithKeys(function (array $line): array {
                $standard = $line['billing_price'] ?? $line['billing_product'] ?? 'Kundespesifikk pris';
                $standardAmount = $line['standard_amount_label'] ?? 'Ikke satt';
                $customAmount = $line['custom_amount_label'] ?? 'Ikke satt';
                $status = $line['status_label'] ?? 'Ukjent';
                $user = filled($line['user_name'] ?? null) ? 'Bruker: ' . $line['user_name'] : null;

                return [
                    $line['id'] => trim(implode(' · ', array_filter([
                        $standard,
                        "Standard: {$standardAmount}",
                        "Avtalt: {$customAmount}",
                        $user,
                        $status,
                    ]))),
                ];
            })
            ->all();
    }

    private function activeCustomerSpecificPriceLineOptions(): array
    {
        return $this->customerSpecificPriceLineOptions(true);
    }

    private function selectedStandardPriceLabel(mixed $billingPriceId): string
    {
        if (blank($billingPriceId)) {
            return 'Velg en standardpris for å se utgangspunktet.';
        }

        $price = BillingPrice::query()->with('product')->find($billingPriceId);

        if (! $price instanceof BillingPrice) {
            return 'Fant ikke valgt standardpris.';
        }

        $amount = $this->formatAmount($price->unit_amount);
        $interval = $this->billingIntervalLabel($price->interval);

        return trim("{$price->name} · {$amount} / {$interval}");
    }

    private function selectedStandardIntervalLabel(mixed $billingPriceId): string
    {
        if (blank($billingPriceId)) {
            return 'Intervall følger valgt standardpris.';
        }

        $price = BillingPrice::query()->find($billingPriceId);

        if (! $price instanceof BillingPrice) {
            return 'Fant ikke valgt standardpris.';
        }

        return $this->billingIntervalLabel($price->interval);
    }

    private function billingIntervalLabel(?string $interval): string
    {
        return match ($interval) {
            BillingPrice::INTERVAL_YEARLY => 'Årlig',
            BillingPrice::INTERVAL_ONE_TIME => 'Engangs',
            BillingPrice::INTERVAL_MONTHLY => 'Månedlig',
            default => filled($interval) ? ucfirst((string) $interval) : 'Ikke satt',
        };
    }

    private function customerSpecificStatusLabel(?string $status): string
    {
        return match ($status) {
            'active' => 'Aktiv',
            'pending_cancel' => 'Avsluttes',
            'ended' => 'Inaktiv',
            'cancelled' => 'Inaktiv',
            'draft' => 'Kladd',
            default => filled($status) ? ucfirst($status) : 'Ukjent',
        };
    }

    private function formatAmount(mixed $amount): string
    {
        if (! is_int($amount) && ! is_numeric($amount)) {
            return 'Ikke satt';
        }

        return number_format(((int) $amount) / 100, 0, ',', ' ') . ' kr';
    }

    private function refreshBillingData(): void
    {
        $this->record = $this->record->fresh();
        $this->loadStripeData();
        $this->loadBillingBasisData();
    }

    private function loadStripeData(): void
    {
        $customer = $this->record->fresh();
        $billingService = app(BillingService::class);
        $this->activeUsersCount = $customer->users()->where('is_active', true)->count();
        $subscription = $customer->subscription('default');
        $this->subscriptionData = null;

        if ($subscription) {
            $stripeSubscription = rescue(
                fn () => $subscription->asStripeSubscription(),
                null,
                false,
            );

            $this->subscriptionData = [
                'status' => $subscription->stripe_status,
                'plan' => $customer->subscription_plan,
                'plan_label' => $customer->planName(),
                'billing_interval' => $customer->billing_interval,
                'billing_interval_label' => $customer->billing_interval === Customer::BILLING_YEARLY
                    ? 'Årlig'
                    : 'Månedlig',
                'included_users' => $customer->included_users,
                'included_ai_credits' => $customer->included_ai_credits,
                'current_period_end' => $stripeSubscription
                    ? date('d.m.Y', $stripeSubscription->current_period_end)
                    : null,
                'cancel_at_period_end' => $stripeSubscription?->cancel_at_period_end ?? false,
                'trial_ends_at' => $customer->trial_ends_at?->format('d.m.Y'),
                'stripe_id' => $customer->stripe_id,
                'status_label' => $this->stripeSubscriptionStatusLabel($subscription->stripe_status),
            ];
        }

        $this->billingLines = $billingService->activeBillingLines($customer)
            ->map(fn ($line) => [
                'id' => $line->id,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'status' => $line->status,
                'source' => $line->source,
                'user_name' => $line->user?->name,
                'billing_product' => $line->billingProduct?->name,
                'billing_price' => $line->billingPrice?->name,
                'interval' => $line->billingPrice?->interval,
                'stripe_subscription_item_id' => $line->stripe_subscription_item_id,
                'stripe_invoice_id' => $line->stripe_invoice_id,
            ])
            ->values()
            ->all();

        $this->serviceLevels = $billingService->activeServiceLevels($customer)
            ->map(fn ($level) => [
                'id' => $level->id,
                'user_name' => $level->user?->name,
                'billing_product' => $level->billingProduct?->name,
                'billing_price' => $level->billingPrice?->name,
                'level_key' => $level->level_key,
                'status' => $level->status,
                'assigned_by' => $level->assignedByUser?->name,
            ])
            ->values()
            ->all();

        $this->customerSpecificPrices = $billingService->customerSpecificPriceLines($customer)
            ->map(function (CustomerBillingLine $line): array {
                $price = $line->billingPrice;
                $standardAmount = $price?->unit_amount;
                $customAmount = data_get($line->metadata, 'custom_unit_amount');

                return [
                    'id' => $line->id,
                    'user_name' => $line->user?->name,
                    'billing_product' => $line->billingProduct?->name,
                    'billing_price' => $line->billingPrice?->name,
                    'billing_price_key' => $line->billingPrice?->key,
                    'quantity' => $line->quantity,
                    'status' => $line->status,
                    'status_label' => $this->customerSpecificStatusLabel($line->status),
                    'interval' => $line->billingPrice?->interval,
                    'interval_label' => $this->billingIntervalLabel($line->billingPrice?->interval),
                    'standard_amount' => $standardAmount,
                    'standard_amount_label' => $this->formatAmount($standardAmount),
                    'custom_amount' => is_numeric($customAmount) ? (int) $customAmount : null,
                    'custom_amount_label' => $this->formatAmount(is_numeric($customAmount) ? (int) $customAmount : null),
                    'currency' => strtoupper((string) ($price?->currency ?? 'nok')),
                    'notes' => data_get($line->metadata, 'notes'),
                    'starts_at' => $line->starts_at?->format('Y-m-d H:i'),
                    'ends_at' => $line->ends_at?->format('Y-m-d H:i'),
                ];
            })
            ->values()
            ->all();

        $this->customerSpecificPricesCount = count($this->customerSpecificPrices);
        $this->activeCustomerSpecificPricesCount = collect($this->customerSpecificPrices)
            ->filter(fn (array $line): bool => ($line['status'] ?? null) === 'active')
            ->count();

        $this->invoices = rescue(
            fn () => $customer->invoices()->map(function ($invoice) {
                $stripeInvoice = $invoice->rawInvoice();
                $stripeStatus = $stripeInvoice->status ?? null;

                return [
                    'id' => $invoice->id,
                    'number' => $invoice->number,
                    'amount' => $invoice->total(),
                    'currency' => strtoupper($stripeInvoice->currency),
                    'stripe_status' => $stripeStatus,
                    'stripe_status_label' => $this->stripeInvoiceStatusLabel($stripeStatus),
                    'date' => $invoice->date()->format('d.m.Y'),
                    'date_sort' => $invoice->date()->timestamp,
                    'month' => $invoice->date()->locale('nb')->translatedFormat('F Y'),
                    'month_sort' => $invoice->date()->format('Y-m'),
                    'pdf_url' => $invoice->invoice_pdf,
                ];
            })->values()->all(),
            [],
            false,
        );

        $this->invoices = $this->sortInvoices($this->invoices);
        $openStripeInvoices = collect($this->invoices)->where('stripe_status', 'open');
        $this->openStripeInvoicesCount = $openStripeInvoices->count();

        $oldestOpenStripeInvoice = $openStripeInvoices->sortBy('date_sort')->first();
        $this->oldestOpenStripeInvoiceLabel = $oldestOpenStripeInvoice
            ? trim(($oldestOpenStripeInvoice['number'] ?? $oldestOpenStripeInvoice['id'] ?? '—') . ' · ' . ($oldestOpenStripeInvoice['month'] ?? $oldestOpenStripeInvoice['date'] ?? '—'))
            : null;
    }

    private function loadBillingBasisData(): void
    {
        $this->billingBasis = app(CustomerBillingBasisService::class)->build($this->record->fresh());
    }

    private function stripeSubscriptionStatusLabel(?string $status): string
    {
        return match ($status) {
            'active' => 'Aktiv',
            'trialing' => 'Prøveperiode',
            'past_due' => 'Forfalt',
            'canceled' => 'Kansellert',
            'incomplete' => 'Ufullstendig',
            'incomplete_expired' => 'Utløpt',
            'unpaid' => 'Ubetalt',
            'paused' => 'Pausert',
            default => filled($status) ? ucfirst($status) : 'Ikke tilgjengelig fra Stripe',
        };
    }

    private function stripeInvoiceStatusLabel(?string $status): string
    {
        return match ($status) {
            'paid' => 'Betalt',
            'open' => 'Åpen',
            'draft' => 'Kladd',
            'void' => 'Annullert',
            'uncollectible' => 'Ikke innkrevbar',
            default => filled($status) ? ucfirst($status) : 'Ikke tilgjengelig fra Stripe',
        };
    }

    private function sortInvoices(array $invoices): array
    {
        usort($invoices, function (array $left, array $right): int {
            $leftDate = (int) ($left['date_sort'] ?? 0);
            $rightDate = (int) ($right['date_sort'] ?? 0);
            $leftMonth = (string) ($left['month_sort'] ?? '');
            $rightMonth = (string) ($right['month_sort'] ?? '');

            $comparison = match ($this->invoiceSort) {
                'month_asc' => $leftMonth <=> $rightMonth,
                'date_asc' => $leftDate <=> $rightDate,
                'date_desc' => $rightDate <=> $leftDate,
                default => $rightMonth <=> $leftMonth,
            };

            if ($comparison === 0 && in_array($this->invoiceSort, ['month_asc', 'month_desc'], true)) {
                $comparison = match ($this->invoiceSort) {
                    'month_asc' => $leftDate <=> $rightDate,
                    default => $rightDate <=> $leftDate,
                };
            }

            return $comparison;
        });

        return array_values($invoices);
    }
}
