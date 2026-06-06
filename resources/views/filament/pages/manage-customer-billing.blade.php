<x-filament-panels::page>
    <style>
        .procynia-billing-page .fi-header {
            display: block !important;
        }

        .procynia-billing-page .fi-header > div:first-child {
            width: 100% !important;
            max-width: 100% !important;
            margin-bottom: 1rem;
        }

        .procynia-billing-page .fi-header-heading {
            max-width: 100%;
            white-space: normal;
            line-height: 1.1;
        }

        .procynia-billing-page .fi-header-subheading {
            max-width: 56rem;
            margin-top: 0.5rem;
            white-space: normal;
        }

        .procynia-billing-page .fi-header-actions-ctn {
            display: flex !important;
            flex-wrap: wrap !important;
            align-items: flex-start !important;
            justify-content: flex-start !important;
            gap: 0.75rem !important;
            width: 100% !important;
        }

        .procynia-billing-page .fi-header-actions-ctn > .fi-ac {
            flex: 1 1 15rem;
            min-width: 15rem;
        }

        .procynia-billing-page .fi-header-actions-ctn .fi-btn {
            width: 100%;
            justify-content: flex-start;
            white-space: normal;
            text-align: left;
        }
    </style>

    @php
        $hasStripeCustomer = filled($record->stripe_id);
        $hasActiveStripeSubscription = filled($subscriptionData) && in_array($subscriptionData['status'] ?? null, ['active', 'trialing'], true);
        $billingLinesCount = count($billingLines);
        $serviceLevelsCount = count($serviceLevels);
        $customerSpecificPricesCount = count($customerSpecificPrices);
        $activeCustomerSpecificPricesCount = collect($customerSpecificPrices)->filter(fn (array $line): bool => ($line['status'] ?? null) === 'active')->count();
        $activeBillingLinesCount = collect($billingLines)->filter(fn (array $line): bool => in_array($line['status'] ?? null, ['active', 'pending_cancel'], true))->count();
        $activeServiceLevelsCount = collect($serviceLevels)->filter(fn (array $level): bool => ($level['status'] ?? null) === 'active')->count();
        $pendingOneTimeLinesCount = collect($billingLines)->filter(fn (array $line): bool => ($line['interval'] ?? null) === \App\Models\BillingPrice::INTERVAL_ONE_TIME && blank($line['stripe_invoice_id'] ?? null) && ($line['status'] ?? null) === 'active')->count();
        $latestInvoice = $invoices[0] ?? null;
        $latestInvoiceLabel = $latestInvoice
            ? trim(($latestInvoice['number'] ?? $latestInvoice['id'] ?? '—') . ' · ' . ($latestInvoice['month'] ?? $latestInvoice['date'] ?? '—'))
            : null;
        $customerStatusLabel = $record->is_active ? 'Aktiv' : 'Inaktiv';
        $customerStatusClass = $record->is_active
            ? 'bg-success-100 text-success-800 dark:bg-success-500/20 dark:text-success-100'
            : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-100';
        $stripeCustomerLabel = $hasStripeCustomer ? 'Koblet' : 'Ikke opprettet';
        $stripeCustomerClass = $hasStripeCustomer
            ? 'bg-success-100 text-success-800 dark:bg-success-500/20 dark:text-success-100'
            : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-100';
        $subscriptionLabel = $hasActiveStripeSubscription ? 'Aktiv' : 'Ingen aktiv subscription';
        $subscriptionClass = $hasActiveStripeSubscription
            ? 'bg-success-100 text-success-800 dark:bg-success-500/20 dark:text-success-100'
            : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-100';
        $billingLinesLabel = $billingLinesCount > 0 ? 'Finnes' : 'Ingen';
        $billingLinesClass = $billingLinesCount > 0
            ? 'bg-success-100 text-success-800 dark:bg-success-500/20 dark:text-success-100'
            : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-100';
        $serviceLevelsLabel = $serviceLevelsCount > 0 ? 'Finnes' : 'Ingen';
        $serviceLevelsClass = $serviceLevelsCount > 0
            ? 'bg-success-100 text-success-800 dark:bg-success-500/20 dark:text-success-100'
            : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-100';
        $procyniaAccessLabel = $activeServiceLevelsCount > 0 ? 'Aktiv' : 'Ingen aktiv tilgang';
        $procyniaAccessClass = $activeServiceLevelsCount > 0
            ? 'bg-success-100 text-success-800 dark:bg-success-500/20 dark:text-success-100'
            : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-100';
        $commercialOwnerLabel = 'Ikke registrert';
        $commercialOwnerClass = 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-100';

        $billingBasis = $billingBasis ?? [];
        $billingBasisCustomer = data_get($billingBasis, 'customer', []);
        $billingBasisSummary = data_get($billingBasis, 'summary', []);
        $billingBasisLineGroups = data_get($billingBasis, 'line_groups', []);
        $billingBasisInvoices = data_get($billingBasis, 'invoices', []);
        $billingBasisReconciliation = data_get($billingBasis, 'reconciliation', []);
        $billingBasisWarnings = collect(data_get($billingBasisSummary, 'warnings', []))
            ->merge(data_get($billingBasisReconciliation, 'warnings', []))
            ->filter()
            ->unique()
            ->values();
        $billingBasisActiveBillableLines = collect(data_get($billingBasisLineGroups, 'base_subscription.lines', []))
            ->merge(data_get($billingBasisLineGroups, 'user_based_lines.lines', []))
            ->merge(data_get($billingBasisLineGroups, 'recurring_addons.lines', []))
            ->merge(data_get($billingBasisLineGroups, 'one_time_charges.lines', []))
            ->merge(data_get($billingBasisLineGroups, 'manual_or_other_lines.lines', []))
            ->values();
        $billingBasisCustomerSpecificPriceLines = collect(data_get($billingBasisLineGroups, 'customer_specific_prices.lines', []));
        $billingBasisHistoricalLines = collect(data_get($billingBasisLineGroups, 'inactive_or_historical_lines.lines', []));
        $billingBasisLatestInvoice = data_get($billingBasisInvoices, 'latest_invoice');
        $billingBasisRecentInvoices = collect(data_get($billingBasisInvoices, 'recent_invoices', []));
        $billingBasisDiscountPercent = (float) data_get($billingBasisSummary, 'discount_percent', 0);
        $billingBasisDiscountLabel = $billingBasisDiscountPercent > 0
            ? number_format($billingBasisDiscountPercent, 2, ',', ' ') . ' %'
            : 'Ingen rabatt';
        $billingBasisLatestInvoiceLabel = $billingBasisLatestInvoice
            ? trim(($billingBasisLatestInvoice['stripe_invoice_id'] ?? '—') . ' · ' . ($billingBasisLatestInvoice['invoice_date_label'] ?? '—'))
            : 'Ingen faktura funnet';
    @endphp

    <div class="space-y-6 text-left">
        <x-filament::section heading="Faktureringsgrunnlag">
            <div class="space-y-5 text-left">
                <p class="max-w-4xl text-sm text-gray-600 dark:text-gray-400">
                    Denne visningen viser Procynias interne operative faktureringsgrunnlag. Stripe-subscription viser abonnement og betalingsstatus, mens fakturalogg viser faktisk fakturert beløp. Der prisgrunnlag mangler, vises Ikke beregnbar i stedet for antatt beløp.
                </p>

                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                    <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Kunde</div>
                        <div class="mt-2 text-base font-semibold text-gray-950 dark:text-white">{{ $billingBasisCustomer['name'] ?? $record->name }}</div>
                        <div class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                            Plan/snapshot: {{ $billingBasisCustomer['plan_label'] ?? $record->planName() }}
                        </div>
                        <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                            Faktureringsintervall: {{ $billingBasisCustomer['billing_interval_label'] ?? 'Ikke satt' }}
                        </div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Rabatt</div>
                        <div class="mt-2 text-base font-semibold text-gray-950 dark:text-white">{{ $billingBasisDiscountLabel }}</div>
                        <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                            Kunde-level rabatt fra Procynia.
                        </div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Status for interne linjer</div>
                        <div class="mt-2 text-base font-semibold text-gray-950 dark:text-white">{{ $billingBasisSummary['basis_status_label'] ?? 'Ikke beregnbar' }}</div>
                        <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                            {{ $billingBasisSummary['can_calculate_expected_total'] ? 'Kun aktive interne linjer kan beregnes sikkert.' : 'Grunnlaget kan ikke beregnes sikkert.' }}
                        </div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Sum aktive interne linjer</div>
                        <div class="mt-2 text-base font-semibold text-gray-950 dark:text-white">{{ $billingBasisSummary['expected_total_label'] ?? 'Ikke beregnbar' }}</div>
                        <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                            {{ $billingBasisSummary['can_calculate_expected_total'] ? 'Kun beregnbare interne linjer er inkludert her.' : 'Ikke beregnbar.' }}
                        </div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Rabattbeløp</div>
                        <div class="mt-2 text-base font-semibold text-gray-950 dark:text-white">{{ $billingBasisSummary['discount_amount_label'] ?? 'Ikke beregnbar' }}</div>
                        <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">Beregnes kun når grunnlaget er sikkert.</div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Sum etter rabatt</div>
                        <div class="mt-2 text-base font-semibold text-gray-950 dark:text-white">{{ $billingBasisSummary['total_after_discount_label'] ?? 'Ikke beregnbar' }}</div>
                        <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">Vises bare når grunnlaget er beregnbart.</div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Siste faktura</div>
                        <div class="mt-2 text-base font-semibold text-gray-950 dark:text-white">{{ $billingBasisLatestInvoiceLabel }}</div>
                        @if ($billingBasisLatestInvoice)
                            <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $billingBasisLatestInvoice['amount_paid_label'] ?? 'Ikke beregnbar' }}</div>
                        @else
                            <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">Ingen faktura funnet.</div>
                        @endif
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Avstemmingsstatus</div>
                        <div class="mt-2 text-base font-semibold text-gray-950 dark:text-white">{{ $billingBasisReconciliation['reconciliation_status_label'] ?? 'Kan ikke avstemmes' }}</div>
                        <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $billingBasisReconciliation['has_line_to_invoice_links'] ? 'Minst én linje kan kobles direkte til en faktura.' : 'Kan ikke avstemmes.' }}</div>
                    </div>
                </div>

                @if ($billingBasisWarnings->isNotEmpty())
                    <div class="rounded-2xl border border-warning-300 bg-warning-50 px-5 py-4 text-sm text-warning-900 shadow-sm dark:border-warning-500/40 dark:bg-warning-500/10 dark:text-warning-100">
                        <div class="font-semibold">Varsler</div>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            @foreach ($billingBasisWarnings as $warning)
                                <li>{{ $warning }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="space-y-3">
                    <div class="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Beregnbare interne linjer</h3>
                            <p class="text-sm text-gray-600 dark:text-gray-400">Interne linjer som kan beregnes sikkert, inkludert abonnement, tillegg og engangslinjer.</p>
                        </div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">
                            {{ $billingBasisActiveBillableLines->count() }} aktive linjer
                        </div>
                    </div>

                    @if ($billingBasisActiveBillableLines->isNotEmpty())
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead class="border-b border-gray-200 dark:border-gray-700">
                                    <tr>
                                        <th class="pb-2 font-medium text-gray-500">Gruppe</th>
                                        <th class="pb-2 font-medium text-gray-500">Beskrivelse</th>
                                        <th class="pb-2 font-medium text-gray-500">Kilde</th>
                                        <th class="pb-2 font-medium text-gray-500">Produkt</th>
                                        <th class="pb-2 font-medium text-gray-500">Pris</th>
                                        <th class="pb-2 font-medium text-gray-500">Antall</th>
                                        <th class="pb-2 font-medium text-gray-500">Intervall</th>
                                        <th class="pb-2 font-medium text-gray-500">Status</th>
                                        <th class="pb-2 font-medium text-gray-500">Beregnet linjesum</th>
                                        <th class="pb-2 font-medium text-gray-500">Varsel</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                    @foreach ($billingBasisActiveBillableLines as $line)
                                        @php
                                            $lineWarnings = collect($line['warnings'] ?? []);
                                            $lineStatus = (string) ($line['calculation_status_label'] ?? 'Ikke beregnbar');
                                            $lineStatusClass = match ($line['calculation_status'] ?? null) {
                                                \App\Services\Billing\CustomerBillingBasisService::CALCULATION_STATUS_COMPLETE => 'bg-success-100 text-success-800 dark:bg-success-500/20 dark:text-success-100',
                                                \App\Services\Billing\CustomerBillingBasisService::CALCULATION_STATUS_PARTIAL => 'bg-warning-100 text-warning-800 dark:bg-warning-500/20 dark:text-warning-100',
                                                default => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-100',
                                            };
                                            $sourceClass = match ($line['source'] ?? null) {
                                                'webhook' => 'bg-info-100 text-info-800 dark:bg-info-500/20 dark:text-info-100',
                                                'manual' => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-100',
                                                'system', 'admin' => 'bg-primary-100 text-primary-800 dark:bg-primary-500/20 dark:text-primary-100',
                                                \App\Models\CustomerBillingLine::SOURCE_CUSTOMER_PRICE => 'bg-violet-100 text-violet-800 dark:bg-violet-500/20 dark:text-violet-100',
                                                default => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-100',
                                            };
                                        @endphp
                                        <tr>
                                            <td class="py-2 text-xs font-medium text-gray-700 dark:text-gray-300">{{ $line['group_label'] ?? '—' }}</td>
                                            <td class="py-2 font-medium">{{ $line['description'] ?? '—' }}</td>
                                            <td class="py-2">
                                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $sourceClass }}">
                                                    {{ $line['source_label'] ?? 'Ukjent' }}
                                                </span>
                                            </td>
                                            <td class="py-2">{{ $line['billing_price_name'] ?? $line['billing_product_name'] ?? '—' }}</td>
                                            <td class="py-2">{{ $line['amount_label'] ?? 'Ikke beregnbar' }}</td>
                                            <td class="py-2">{{ $line['quantity'] ?? '—' }}</td>
                                            <td class="py-2">{{ $line['billing_price_interval'] ?? '—' }}</td>
                                            <td class="py-2">
                                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $lineStatusClass }}">
                                                    {{ $lineStatus }}
                                                </span>
                                            </td>
                                            <td class="py-2">{{ $line['line_total_label'] ?? 'Ikke beregnbar' }}</td>
                                            <td class="py-2">
                                                {{ $lineWarnings->isNotEmpty() ? $lineWarnings->implode(' · ') : '—' }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="text-sm text-gray-500">Ingen aktive interne billing-linjer som kan beregnes sikkert.</p>
                    @endif
                </div>

                <div class="space-y-3">
                    <div class="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Kundespesifikke priser</h3>
                            <p class="text-sm text-gray-600 dark:text-gray-400">Standardpris, avtalt pris og differanse for aktive kundespesifikke prislinjer.</p>
                        </div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">
                            {{ $billingBasisCustomerSpecificPriceLines->count() }} aktive priser
                        </div>
                    </div>

                    @if ($billingBasisCustomerSpecificPriceLines->isNotEmpty())
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead class="border-b border-gray-200 dark:border-gray-700">
                                    <tr>
                                        <th class="pb-2 font-medium text-gray-500">Tjeneste</th>
                                        <th class="pb-2 font-medium text-gray-500">Standardpris</th>
                                        <th class="pb-2 font-medium text-gray-500">Avtalt pris</th>
                                        <th class="pb-2 font-medium text-gray-500">Differanse</th>
                                        <th class="pb-2 font-medium text-gray-500">Status</th>
                                        <th class="pb-2 font-medium text-gray-500">Periode</th>
                                        <th class="pb-2 font-medium text-gray-500">Antall</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                    @foreach ($billingBasisCustomerSpecificPriceLines as $line)
                                        @php
                                            $lineStatus = (string) ($line['calculation_status'] ?? 'not_calculable');
                                            $lineStatusClass = match ($lineStatus) {
                                                \App\Services\Billing\CustomerBillingBasisService::CALCULATION_STATUS_COMPLETE => 'bg-success-100 text-success-800 dark:bg-success-500/20 dark:text-success-100',
                                                \App\Services\Billing\CustomerBillingBasisService::CALCULATION_STATUS_PARTIAL => 'bg-warning-100 text-warning-800 dark:bg-warning-500/20 dark:text-warning-100',
                                                default => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-100',
                                            };
                                        @endphp
                                        <tr>
                                            <td class="py-2 font-medium">{{ $line['billing_price_name'] ?? $line['billing_product_name'] ?? '—' }}</td>
                                            <td class="py-2">{{ $line['standard_amount_label'] ?? 'Ikke beregnbar' }}</td>
                                            <td class="py-2">{{ $line['custom_amount_label'] ?? 'Ikke beregnbar' }}</td>
                                            <td class="py-2">{{ $line['difference_amount_label'] ?? 'Ikke beregnbar' }}</td>
                                            <td class="py-2">
                                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $lineStatusClass }}">
                                                    {{ $line['calculation_status_label'] ?? 'Ikke beregnbar' }}
                                                </span>
                                            </td>
                                            <td class="py-2">{{ $line['period_label'] ?? '—' }}</td>
                                            <td class="py-2">{{ $line['quantity'] ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="text-sm text-gray-500">Ingen aktive kundespesifikke priser registrert ennå.</p>
                    @endif
                </div>

                <div class="space-y-3">
                    <div class="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Faktisk fakturert og avstemming</h3>
                            <p class="text-sm text-gray-600 dark:text-gray-400">Stripe-fakturaer og status for kobling mot interne billing-linjer.</p>
                        </div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">
                            {{ data_get($billingBasisInvoices, 'invoice_period_label', 'Siste fakturaer') }}
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Avstemmingsstatus</div>
                            <div class="mt-2 text-base font-semibold text-gray-950 dark:text-white">{{ $billingBasisReconciliation['reconciliation_status_label'] ?? 'Kan ikke avstemmes' }}</div>
                            <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $billingBasisReconciliation['linked_line_count'] ?? 0 }} linjer med direkte kobling</div>
                        </div>

                        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Faktisk fakturert</div>
                            <div class="mt-2 text-base font-semibold text-gray-950 dark:text-white">{{ $billingBasisInvoices['invoice_total_label'] ?? 'Ikke beregnbar' }}</div>
                            <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">Sum av viste invoice_logs i perioden.</div>
                        </div>

                        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Siste faktura</div>
                            <div class="mt-2 text-base font-semibold text-gray-950 dark:text-white">{{ $billingBasisLatestInvoiceLabel }}</div>
                            <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                {{ $billingBasisLatestInvoice['amount_paid_label'] ?? 'Ingen faktura' }}
                            </div>
                        </div>

                        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Siste fakturaer</div>
                            <div class="mt-2 text-base font-semibold text-gray-950 dark:text-white">{{ $billingBasisRecentInvoices->count() }}</div>
                            <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">Viser de mest relevante invoice_logs som allerede er lagret lokalt.</div>
                        </div>
                    </div>

                    @if ($billingBasisRecentInvoices->isNotEmpty())
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead class="border-b border-gray-200 dark:border-gray-700">
                                    <tr>
                                        <th class="pb-2 font-medium text-gray-500">Stripe-faktura</th>
                                        <th class="pb-2 font-medium text-gray-500">Dato</th>
                                        <th class="pb-2 font-medium text-gray-500">Status</th>
                                        <th class="pb-2 font-medium text-gray-500">Beløp</th>
                                        <th class="pb-2 font-medium text-gray-500">Valuta</th>
                                        <th class="pb-2 font-medium text-gray-500">Linjekobling</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                    @foreach ($billingBasisRecentInvoices as $invoice)
                                        <tr>
                                            <td class="py-2 font-mono text-xs">{{ $invoice['stripe_invoice_id'] ?? $invoice['id'] }}</td>
                                            <td class="py-2">{{ $invoice['invoice_date_label'] ?? '—' }}</td>
                                            <td class="py-2">{{ $invoice['status_label'] ?? $invoice['status'] ?? '—' }}</td>
                                            <td class="py-2">{{ $invoice['amount_paid_label'] ?? 'Ikke beregnbar' }}</td>
                                            <td class="py-2">{{ $invoice['currency'] ?? '—' }}</td>
                                            <td class="py-2">{{ $invoice['line_link_label'] ?? 'Ikke beregnbar' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="text-sm text-gray-500">Ingen invoice_logs tilgjengelig for avstemming.</p>
                    @endif
                </div>

                <div class="space-y-3">
                    <div>
                        <h3 class="text-base font-semibold text-gray-950 dark:text-white">Historiske linjer</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Avsluttede eller historiske billing-linjer som fortsatt kan være nyttige som sporbarhet.</p>
                    </div>

                    @if ($billingBasisHistoricalLines->isNotEmpty())
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead class="border-b border-gray-200 dark:border-gray-700">
                                    <tr>
                                        <th class="pb-2 font-medium text-gray-500">Gruppe</th>
                                        <th class="pb-2 font-medium text-gray-500">Beskrivelse</th>
                                        <th class="pb-2 font-medium text-gray-500">Status</th>
                                        <th class="pb-2 font-medium text-gray-500">Periode</th>
                                        <th class="pb-2 font-medium text-gray-500">Beløp</th>
                                        <th class="pb-2 font-medium text-gray-500">Varsel</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                    @foreach ($billingBasisHistoricalLines as $line)
                                        <tr>
                                            <td class="py-2 text-xs font-medium text-gray-700 dark:text-gray-300">{{ $line['group_label'] ?? 'Historiske linjer' }}</td>
                                            <td class="py-2 font-medium">{{ $line['description'] ?? '—' }}</td>
                                            <td class="py-2">{{ $line['status_label'] ?? '—' }}</td>
                                            <td class="py-2">{{ $line['period_label'] ?? '—' }}</td>
                                            <td class="py-2">{{ $line['line_total_label'] ?? 'Ikke beregnbar' }}</td>
                                            <td class="py-2">{{ collect($line['warnings'] ?? [])->isNotEmpty() ? collect($line['warnings'])->implode(' · ') : '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="text-sm text-gray-500">Ingen historiske billing-linjer registrert.</p>
                    @endif
                </div>
            </div>
        </x-filament::section>

        <x-filament::section heading="Økonomisk status fra Stripe">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Stripe-kunde</div>
                            <div class="mt-2 text-base font-semibold text-gray-950 dark:text-white">
                                {{ $hasStripeCustomer ? 'Stripe-kunde registrert' : 'Ikke opprettet' }}
                            </div>
                        </div>
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $stripeCustomerClass }}">
                            {{ $stripeCustomerLabel }}
                        </span>
                    </div>
                    <div class="mt-3 text-xs font-medium uppercase tracking-wide text-gray-500">Kilde: Stripe</div>
                    <div class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                        @if ($hasStripeCustomer)
                            <code class="break-all font-mono text-xs">Stripe-kunde-ID: {{ $record->stripe_id }}</code>
                        @else
                            Ikke tilgjengelig fra Stripe
                        @endif
                    </div>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Stripe-subscription</div>
                            <div class="mt-2 text-base font-semibold text-gray-950 dark:text-white">
                                {{ $hasActiveStripeSubscription ? 'Aktiv' : 'Ingen aktiv Stripe-subscription' }}
                            </div>
                        </div>
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $subscriptionClass }}">
                            {{ $subscriptionLabel }}
                        </span>
                    </div>
                    <div class="mt-3 text-xs font-medium uppercase tracking-wide text-gray-500">Kilde: Stripe</div>
                    <div class="mt-2 space-y-1 text-sm text-gray-600 dark:text-gray-400">
                        @if ($hasActiveStripeSubscription)
                            <div>Stripe-status: {{ $subscriptionData['status_label'] ?? 'Ikke tilgjengelig fra Stripe' }}</div>
                            <div>Stripe-plan: {{ $subscriptionData['plan_label'] ?: '—' }}</div>
                            <div>Stripe-faktureringsintervall: {{ $subscriptionData['billing_interval_label'] ?? '—' }}</div>
                            <div>Neste Stripe-fakturering: {{ $subscriptionData['current_period_end'] ?? 'Ikke tilgjengelig fra Stripe' }}</div>
                            <div>Stripe-trial: {{ $subscriptionData['trial_ends_at'] ?? 'Ikke tilgjengelig fra Stripe' }}</div>
                            <div>Planinkluderte brukere: {{ $subscriptionData['included_users'] ?? 'Ikke tilgjengelig fra Stripe' }}</div>
                            <div>Planinkluderte KI-tilbud: {{ $subscriptionData['included_ai_credits'] ?? 'Ikke tilgjengelig fra Stripe' }}</div>
                        @elseif ($hasStripeCustomer)
                            <div>Ingen aktiv Stripe-subscription funnet. Stripe er fasit for aktivt abonnement og betalingsstatus. Kunden har Stripe-kunde registrert, men ingen aktiv Stripe-subscription.</div>
                        @else
                            <div>Ikke tilgjengelig fra Stripe.</div>
                        @endif
                    </div>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Siste Stripe-faktura</div>
                            <div class="mt-2 text-base font-semibold text-gray-950 dark:text-white">
                                {{ $latestInvoiceLabel ?? 'Ingen faktura funnet' }}
                            </div>
                        </div>
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $latestInvoice ? 'bg-success-100 text-success-800 dark:bg-success-500/20 dark:text-success-100' : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-100' }}">
                            {{ $latestInvoice ? 'Stripe-faktura tilgjengelig' : 'Ingen Stripe-faktura' }}
                        </span>
                    </div>
                    <div class="mt-3 text-xs font-medium uppercase tracking-wide text-gray-500">Kilde: Stripe</div>
                    <div class="mt-2 space-y-1 text-sm text-gray-600 dark:text-gray-400">
                        <div>Åpne Stripe-fakturaer: {{ $openStripeInvoicesCount }}</div>
                        @if ($latestInvoice)
                            <div>Stripe-status: {{ $latestInvoice['stripe_status_label'] ?? 'Ikke tilgjengelig fra Stripe' }}</div>
                        @else
                            <div>Ingen faktura funnet.</div>
                        @endif
                    </div>
                </div>

            </div>
        </x-filament::section>

        <x-filament::section heading="Procynia-konto og tilgang">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Kunde</div>
                            <div class="mt-2 text-base font-semibold text-gray-950 dark:text-white">{{ $record->name }}</div>
                        </div>
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $customerStatusClass }}">
                            {{ $customerStatusLabel }}
                        </span>
                    </div>
                    <div class="mt-3 text-xs font-medium uppercase tracking-wide text-gray-500">Kilde: Procynia</div>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Kommersiell eier</div>
                    <div class="mt-2 text-base font-semibold text-gray-950 dark:text-white">{{ $commercialOwnerLabel }}</div>
                    <div class="mt-3 text-xs font-medium uppercase tracking-wide text-gray-500">Kilde: Procynia</div>
                    <div class="mt-2 text-sm text-gray-600 dark:text-gray-400">Kommersiell eier er ikke registrert i dagens datamodell.</div>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Aktive Procynia-brukere</div>
                    <div class="mt-2 text-3xl font-semibold text-gray-950 dark:text-white">{{ $activeUsersCount }}</div>
                    <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">Procynia-brukere som er aktive på kunden.</div>
                    <div class="mt-3 text-xs font-medium uppercase tracking-wide text-gray-500">Kilde: Procynia</div>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Procynia-brukernivåer</div>
                    <div class="mt-2 text-3xl font-semibold text-gray-950 dark:text-white">{{ $serviceLevelsCount }}</div>
                    <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">Brukerbaserte nivåer og tilgang i Procynia.</div>
                    <div class="mt-3 text-xs font-medium uppercase tracking-wide text-gray-500">Kilde: Procynia</div>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Interne Procynia-billing-linjer</div>
                    <div class="mt-2 text-3xl font-semibold text-gray-950 dark:text-white">{{ $billingLinesCount }}</div>
                    <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">Interne linjer for tjenester og tilgang. Ikke økonomisk fasit.</div>
                    <div class="mt-3 text-xs font-medium uppercase tracking-wide text-gray-500">Kilde: Procynia</div>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Kundespesifikke priser</div>
                    <div class="mt-2 text-3xl font-semibold text-gray-950 dark:text-white">{{ $customerSpecificPricesCount }}</div>
                    <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">Avtalte prislinjer som ikke endrer katalogprisene.</div>
                    <div class="mt-3 text-xs font-medium uppercase tracking-wide text-gray-500">Kilde: Procynia</div>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Aktive kundespesifikke priser</div>
                    <div class="mt-2 text-3xl font-semibold text-gray-950 dark:text-white">{{ $activeCustomerSpecificPricesCount }}</div>
                    <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">Aktive avtaler som fortsatt gjelder for kunden.</div>
                    <div class="mt-3 text-xs font-medium uppercase tracking-wide text-gray-500">Kilde: Procynia</div>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Ventende interne engangslinjer</div>
                    <div class="mt-2 text-3xl font-semibold text-gray-950 dark:text-white">{{ $pendingOneTimeLinesCount }}</div>
                    <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">Interne Procynia-linjer som kan sendes til Stripe.</div>
                    <div class="mt-3 text-xs font-medium uppercase tracking-wide text-gray-500">Kilde: Procynia</div>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Tilgangsstatus</div>
                            <div class="mt-2 text-base font-semibold text-gray-950 dark:text-white">{{ $procyniaAccessLabel }}</div>
                        </div>
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $procyniaAccessClass }}">
                            {{ $procyniaAccessLabel }}
                        </span>
                    </div>
                    <div class="mt-3 text-xs font-medium uppercase tracking-wide text-gray-500">Kilde: Procynia</div>
                    <div class="mt-2 text-sm text-gray-600 dark:text-gray-400">Basert på aktive Procynia-brukernivåer og intern tilgang.</div>
                </div>

            </div>
        </x-filament::section>

        @php
            $discountPercent = (float) ($record->billing_discount_percent ?? 0);
            $discountLabel = $discountPercent > 0
                ? number_format($discountPercent, 2, ',', ' ') . ' %'
                : 'Ingen rabatt';
        @endphp
        <x-filament::section heading="Kunderabatt">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <div class="text-2xl font-bold text-gray-950 dark:text-white">{{ $discountLabel }}</div>
                    <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">Kilde: Procynia — gjelder kundens abonnementer og priser.</div>
                </div>
                <x-filament::button wire:click="mountAction('edit_discount')" color="gray" size="sm" icon="heroicon-m-pencil-square">
                    Endre kunderabatt
                </x-filament::button>
            </div>
        </x-filament::section>

        <x-filament::section heading="Utestående fra Stripe">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Utestående totalt fra Stripe</div>
                    <div class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">{{ $stripeOutstandingTotalLabel }}</div>
                    <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">Bare Stripe-data er økonomisk fasit.</div>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Forfalt beløp fra Stripe</div>
                    <div class="mt-2 text-2xl font-semibold text-gray-950 dark:text-white">{{ $stripeOverdueTotalLabel }}</div>
                    <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">Bare Stripe-data er økonomisk fasit.</div>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Åpne Stripe-fakturaer</div>
                    <div class="mt-2 text-3xl font-semibold text-gray-950 dark:text-white">{{ $openStripeInvoicesCount }}</div>
                    <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">Hentet fra Stripe-fakturahistorikk.</div>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Eldste åpne Stripe-faktura</div>
                    <div class="mt-2 text-base font-semibold text-gray-950 dark:text-white">
                        {{ $oldestOpenStripeInvoiceLabel ?? 'Ingen åpne Stripe-fakturaer' }}
                    </div>
                    <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        @if ($openStripeInvoicesCount > 0)
                            Åpen faktura fra Stripe
                        @else
                            Ikke tilgjengelig fra Stripe
                        @endif
                    </div>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Siste betalingsfeil fra Stripe</div>
                    <div class="mt-2 text-base font-semibold text-gray-950 dark:text-white">{{ $stripeLastPaymentFailureLabel }}</div>
                    <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">Hvis Stripe ikke har synkronisert en feil, vises trygg tomverdi.</div>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Sist synkronisert fra Stripe</div>
                    <div class="mt-2 text-base font-semibold text-gray-950 dark:text-white">{{ $stripeSyncedAtLabel }}</div>
                    <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">Viser kun Stripe-data dersom de allerede er tilgjengelige.</div>
                </div>
            </div>
        </x-filament::section>

        @php
            $billingIssues = [];

            if ($billingLinesCount > 0 && ! $hasStripeCustomer) {
                $billingIssues[] = [
                    'type' => 'Krever oppfølging',
                    'tone' => 'warning',
                    'title' => 'Kunden har interne Procynia-billing-linjer, men mangler Stripe-kunde.',
                    'body' => 'Opprett Stripe-kunde før Stripe-subscription, fakturaer eller betalingsstatus kan administreres. Interne Procynia-linjer er ikke økonomisk fasit.',
                ];
            }

            if ($billingLinesCount > 0 && ! $hasActiveStripeSubscription) {
                $billingIssues[] = [
                    'type' => 'Avvik',
                    'tone' => 'danger',
                    'title' => 'Kunden har interne Procynia-billing-linjer, men ingen aktiv Stripe-subscription.',
                    'body' => 'Interne linjer beskriver tjenester eller tilgang i Procynia, men faktisk fakturering og betalingsstatus må komme fra Stripe.',
                ];
            }

            if ($hasStripeCustomer && ! $hasActiveStripeSubscription) {
                $billingIssues[] = [
                    'type' => 'Informasjon',
                    'tone' => 'info',
                    'title' => 'Kunden har Stripe-kunde, men ingen aktiv Stripe-subscription.',
                    'body' => 'Stripe-kunde finnes, men det er ikke satt opp et aktivt abonnement i Stripe ennå.',
                ];
            }

            if (count($invoices) === 0) {
                $billingIssues[] = [
                    'type' => 'Informasjon',
                    'tone' => 'gray',
                    'title' => 'Ingen faktura funnet.',
                    'body' => 'Fakturaer, PDF-er, betaling, utestående og forfall hentes fra Stripe når dette finnes.',
                ];
            }

            if ($serviceLevelsCount > 0 && $billingLinesCount === 0) {
                $billingIssues[] = [
                    'type' => 'Krever oppfølging',
                    'tone' => 'warning',
                    'title' => 'Kunden har Procynia-brukernivåer, men ingen interne Procynia-billing-linjer.',
                    'body' => 'Brukernivåene finnes i Procynia, men det finnes ingen interne billing-linjer som beskriver kommersielt grunnlag eller faktureringslinjer.',
                ];
            }
        @endphp

        <x-filament::section heading="Avvik og oppfølgingspunkter">
            <div class="space-y-3 text-left">
                <div class="rounded-2xl border border-gray-200 bg-gray-50 px-5 py-4 text-sm text-gray-700 shadow-sm dark:border-gray-700 dark:bg-gray-800/40 dark:text-gray-200">
                    <div class="font-semibold">Informasjon</div>
                    <p class="mt-2">
                        Stripe-basert utestående er ikke tilgjengelig fra dagens Stripe-data. Denne siden viser kun Stripe-data som allerede er synkronisert. Ikke beregn økonomisk utestående fra interne Procynia-billing-linjer.
                    </p>
                </div>

                @if (count($billingIssues) > 0)
                    <div class="grid gap-3">
                        @foreach ($billingIssues as $issue)
                            @php
                                $issueClass = match ($issue['tone']) {
                                    'danger' => 'border-danger-300 bg-danger-50 text-danger-900 dark:border-danger-500/40 dark:bg-danger-500/10 dark:text-danger-100',
                                    'warning' => 'border-warning-300 bg-warning-50 text-warning-900 dark:border-warning-500/40 dark:bg-warning-500/10 dark:text-warning-100',
                                    'info' => 'border-info-300 bg-info-50 text-info-900 dark:border-info-500/40 dark:bg-info-500/10 dark:text-info-100',
                                    default => 'border-gray-200 bg-gray-50 text-gray-900 dark:border-gray-700 dark:bg-gray-800/40 dark:text-gray-100',
                                };
                            @endphp
                            <div class="rounded-2xl border px-5 py-4 shadow-sm {{ $issueClass }}">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="font-semibold">{{ $issue['title'] }}</div>
                                    <span class="inline-flex w-fit items-center rounded-full bg-white/70 px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wide">
                                        {{ $issue['type'] }}
                                    </span>
                                </div>
                                <p class="mt-2 text-sm leading-6">{{ $issue['body'] }}</p>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="rounded-2xl border border-gray-200 bg-gray-50 px-5 py-4 text-sm text-gray-700 shadow-sm dark:border-gray-700 dark:bg-gray-800/40 dark:text-gray-200">
                        Ingen kjente avvik basert på tilgjengelige Stripe- og Procynia-data.
                    </div>
                @endif
            </div>
        </x-filament::section>

        <x-filament::section heading="Stripe-subscription">
            @if ($subscriptionData)
                <dl class="grid grid-cols-1 gap-4 text-left text-sm md:grid-cols-2 xl:grid-cols-3">
                    <div>
                        <dt class="font-medium text-gray-500">Stripe-status</dt>
                        <dd class="mt-1">
                            <span @class([
                                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                'bg-success-100 text-success-800' => $subscriptionData['status'] === 'active',
                                'bg-warning-100 text-warning-800' => $subscriptionData['status'] === 'trialing',
                                'bg-danger-100 text-danger-800' => in_array($subscriptionData['status'], ['past_due', 'canceled', 'incomplete']),
                                'bg-gray-100 text-gray-800' => ! in_array($subscriptionData['status'], ['active', 'trialing', 'past_due', 'canceled', 'incomplete']),
                            ])>
                                {{ $subscriptionData['status'] }}
                            </span>
                            @if ($subscriptionData['cancel_at_period_end'])
                                <span class="ml-2 inline-flex items-center rounded-full bg-warning-100 px-2 py-0.5 text-xs font-medium text-warning-800">
                                    Avsluttes ved periodeslutt
                                </span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-500">Stripe-plan</dt>
                        <dd class="mt-1">{{ $subscriptionData['plan_label'] ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-500">Stripe-faktureringsintervall</dt>
                        <dd class="mt-1">{{ $subscriptionData['billing_interval_label'] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-500">Neste Stripe-fakturering</dt>
                        <dd class="mt-1">{{ $subscriptionData['current_period_end'] ?? 'Ikke tilgjengelig fra Stripe' }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-500">Inkluderte brukere</dt>
                        <dd class="mt-1">{{ $subscriptionData['included_users'] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-500">Inkluderte KI-tilbud</dt>
                        <dd class="mt-1">{{ $subscriptionData['included_ai_credits'] ?? '—' }}</dd>
                    </div>
                    @if ($subscriptionData['trial_ends_at'])
                        <div>
                            <dt class="font-medium text-gray-500">Stripe-trial</dt>
                            <dd class="mt-1">{{ $subscriptionData['trial_ends_at'] }}</dd>
                        </div>
                    @endif
                </dl>
            @else
                <div class="space-y-2 text-left text-sm text-gray-600 dark:text-gray-400">
                    @if ($hasStripeCustomer)
                        <p>Ingen aktiv Stripe-subscription funnet. Stripe er fasit for aktivt abonnement og betalingsstatus. Kunden har Stripe-kunde registrert, men ingen aktiv subscription. Interne Procynia-linjer vises under og kan brukes som grunnlag for abonnement eller manuell fakturering, men de er ikke økonomisk fasit.</p>
                    @else
                        <p>Ingen Stripe-kunde er opprettet for denne kunden. Ingen aktiv Stripe-subscription er tilgjengelig ennå. Bruk «Opprett Stripe-kunde» i handlingene over før Stripe-subscription eller Stripe-fakturering administreres.</p>
                    @endif
                </div>
            @endif
        </x-filament::section>

        <x-filament::section heading="Interne Procynia-billing-linjer">
            <div class="space-y-3 text-left">
                <p class="max-w-3xl text-sm text-gray-600 dark:text-gray-400">
                    Dette er interne Procynia-linjer for tjenester, tilgang eller faktureringsgrunnlag. Faktisk faktura, betalingsstatus og utestående kommer fra Stripe.
                </p>

                @if (count($billingLines) > 0)
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b border-gray-200 dark:border-gray-700">
                                <tr>
                                    <th class="pb-2 font-medium text-gray-500">Produkt</th>
                                    <th class="pb-2 font-medium text-gray-500">Beskrivelse</th>
                                    <th class="pb-2 font-medium text-gray-500">Type</th>
                                    <th class="pb-2 font-medium text-gray-500">Antall</th>
                                    <th class="pb-2 font-medium text-gray-500">Bruker</th>
                                    <th class="pb-2 font-medium text-gray-500">Intern status</th>
                                    <th class="pb-2 font-medium text-gray-500">Kilde</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach ($billingLines as $line)
                                    @php
                                        $lineType = ($line['interval'] ?? null) === \App\Models\BillingPrice::INTERVAL_ONE_TIME ? 'Engang' : 'Løpende';
                                        $lineStatus = (string) ($line['status'] ?? '—');
                                        $lineStatusClass = match ($lineStatus) {
                                            'active' => 'bg-success-100 text-success-800 dark:bg-success-500/20 dark:text-success-100',
                                            'pending_cancel' => 'bg-warning-100 text-warning-800 dark:bg-warning-500/20 dark:text-warning-100',
                                            'cancelled', 'ended' => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-100',
                                            default => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-100',
                                        };
                                        $lineSource = (string) ($line['source'] ?? '—');
                                        $lineSourceLabel = match ($lineSource) {
                                            'webhook' => 'Stripe',
                                            'system', 'admin', 'manual' => 'Procynia',
                                            default => 'Ikke tilgjengelig',
                                        };
                                        $lineSourceClass = match ($lineSource) {
                                            'webhook' => 'bg-info-100 text-info-800 dark:bg-info-500/20 dark:text-info-100',
                                            'manual' => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-100',
                                            'system', 'admin' => 'bg-primary-100 text-primary-800 dark:bg-primary-500/20 dark:text-primary-100',
                                            default => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-100',
                                        };
                                        $lineTypeClass = $lineType === 'Engang'
                                            ? 'bg-warning-100 text-warning-800 dark:bg-warning-500/20 dark:text-warning-100'
                                            : 'bg-success-100 text-success-800 dark:bg-success-500/20 dark:text-success-100';
                                    @endphp
                                    <tr>
                                        <td class="py-2 font-medium">{{ $line['billing_price'] ?? $line['billing_product'] ?? '—' }}</td>
                                        <td class="py-2">{{ $line['description'] ?? '—' }}</td>
                                        <td class="py-2">
                                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $lineTypeClass }}">
                                                {{ $lineType }}
                                            </span>
                                        </td>
                                        <td class="py-2">{{ $line['quantity'] ?? 1 }}</td>
                                        <td class="py-2">{{ $line['user_name'] ?? 'Kunde' }}</td>
                                        <td class="py-2">
                                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $lineStatusClass }}">
                                                {{ $lineStatus }}
                                            </span>
                                        </td>
                                        <td class="py-2">
                                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $lineSourceClass }}">
                                                {{ $lineSourceLabel }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-sm text-gray-500">Ingen interne Procynia-billing-linjer registrert ennå.</p>
                @endif
            </div>
        </x-filament::section>

        <x-filament::section heading="Kundespesifikke priser">
            <div class="space-y-3 text-left">
                <p class="max-w-3xl text-sm text-gray-600 dark:text-gray-400">
                    Her ligger avtalte priser per kunde. Standard katalogpris beholdes uendret, mens avtalte beløp ligger på kundens egne billing-linjer.
                </p>

                @if (count($customerSpecificPrices) > 0)
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b border-gray-200 dark:border-gray-700">
                                <tr>
                                    <th class="pb-2 font-medium text-gray-500">Tjeneste</th>
                                    <th class="pb-2 font-medium text-gray-500">Standardpris</th>
                                    <th class="pb-2 font-medium text-gray-500">Avtalt pris</th>
                                    <th class="pb-2 font-medium text-gray-500">Intervall</th>
                                    <th class="pb-2 font-medium text-gray-500">Mengde</th>
                                    <th class="pb-2 font-medium text-gray-500">Bruker</th>
                                    <th class="pb-2 font-medium text-gray-500">Status</th>
                                    <th class="pb-2 font-medium text-gray-500">Periode</th>
                                    <th class="pb-2 font-medium text-gray-500">Notat</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach ($customerSpecificPrices as $line)
                                    @php
                                        $lineStatus = (string) ($line['status'] ?? '—');
                                        $lineStatusClass = match ($lineStatus) {
                                            'active' => 'bg-success-100 text-success-800 dark:bg-success-500/20 dark:text-success-100',
                                            'pending_cancel' => 'bg-warning-100 text-warning-800 dark:bg-warning-500/20 dark:text-warning-100',
                                            'cancelled', 'ended' => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-100',
                                            default => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-100',
                                        };
                                    @endphp
                                    <tr>
                                        <td class="py-2 font-medium">{{ $line['billing_price'] ?? $line['billing_product'] ?? '—' }}</td>
                                        <td class="py-2">{{ $line['standard_amount_label'] ?? 'Ikke satt' }}</td>
                                        <td class="py-2">{{ $line['custom_amount_label'] ?? 'Ikke satt' }}</td>
                                        <td class="py-2">{{ $line['interval_label'] ?? '—' }}</td>
                                        <td class="py-2">{{ $line['quantity'] ?? 1 }}</td>
                                        <td class="py-2">{{ $line['user_name'] ?? 'Kunde' }}</td>
                                        <td class="py-2">
                                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $lineStatusClass }}">
                                                {{ $line['status_label'] ?? $lineStatus }}
                                            </span>
                                        </td>
                                        <td class="py-2">
                                            <div class="text-xs text-gray-600 dark:text-gray-400">
                                                <div>Start: {{ $line['starts_at'] ?? '—' }}</div>
                                                <div>Slutt: {{ $line['ends_at'] ?? 'Aktiv' }}</div>
                                            </div>
                                        </td>
                                        <td class="py-2">
                                            {{ filled($line['notes'] ?? null) ? $line['notes'] : '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-sm text-gray-500">Ingen kundespesifikke priser registrert ennå.</p>
                @endif
            </div>
        </x-filament::section>

        <x-filament::section heading="Procynia-brukernivåer">
            <div class="space-y-3 text-left">
                <p class="max-w-3xl text-sm text-gray-600 dark:text-gray-400">
                    Dette viser brukerbaserte nivåer og tilgang i Procynia. Eventuell økonomisk fakturering av disse må avstemmes mot Stripe.
                </p>

                @if (count($serviceLevels) > 0)
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b border-gray-200 dark:border-gray-700">
                                <tr>
                                    <th class="pb-2 font-medium text-gray-500">Bruker</th>
                                    <th class="pb-2 font-medium text-gray-500">Tjeneste</th>
                                    <th class="pb-2 font-medium text-gray-500">Nivå</th>
                                    <th class="pb-2 font-medium text-gray-500">Procynia-status</th>
                                    <th class="pb-2 font-medium text-gray-500">Tildelt av</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach ($serviceLevels as $level)
                                    @php
                                        $levelStatus = (string) ($level['status'] ?? '—');
                                        $levelStatusClass = match ($levelStatus) {
                                            'active' => 'bg-success-100 text-success-800 dark:bg-success-500/20 dark:text-success-100',
                                            'pending_cancel' => 'bg-warning-100 text-warning-800 dark:bg-warning-500/20 dark:text-warning-100',
                                            'cancelled', 'ended' => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-100',
                                            default => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-100',
                                        };
                                    @endphp
                                    <tr>
                                        <td class="py-2 font-medium">{{ $level['user_name'] ?? '—' }}</td>
                                        <td class="py-2">{{ $level['billing_price'] ?? $level['billing_product'] ?? '—' }}</td>
                                        <td class="py-2">
                                            <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-800 dark:bg-gray-700 dark:text-gray-100">
                                                {{ $level['level_key'] ?? '—' }}
                                            </span>
                                        </td>
                                        <td class="py-2">
                                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $levelStatusClass }}">
                                                {{ $levelStatus }}
                                            </span>
                                        </td>
                                        <td class="py-2">{{ $level['assigned_by'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-sm text-gray-500">Ingen aktive brukernivåer.</p>
                @endif
            </div>
        </x-filament::section>

        <x-filament::section heading="Stripe-fakturahistorikk">
            <div class="mb-3 flex flex-col gap-3 text-left text-sm text-gray-600 md:flex-row md:items-end md:justify-between dark:text-gray-400">
                <p class="max-w-3xl">
                    Dette er Stripe-fakturahistorikk og ikke en intern faktura. Fakturaer, PDF-er, betaling, utestående og forfall hentes fra Stripe når dette finnes.
                </p>

                <label class="flex flex-col gap-1 font-medium text-gray-700 dark:text-gray-300">
                    <span>Sorter Stripe-fakturaer</span>
                    <select wire:model.live="invoiceSort" class="min-w-[14rem] rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                        <option value="month_desc">Måned, nyeste først</option>
                        <option value="month_asc">Måned, eldste først</option>
                        <option value="date_desc">Dato, nyeste først</option>
                        <option value="date_asc">Dato, eldste først</option>
                    </select>
                </label>
            </div>

            @if (count($invoices) > 0)
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-gray-200 dark:border-gray-700">
                            <tr>
                                <th class="pb-2 font-medium text-gray-500">Stripe-fakturanr.</th>
                                <th class="pb-2 font-medium text-gray-500">Måned</th>
                                <th class="pb-2 font-medium text-gray-500">Dato</th>
                                <th class="pb-2 font-medium text-gray-500">Stripe-beløp</th>
                                <th class="pb-2 font-medium text-gray-500">Stripe-status</th>
                                <th class="pb-2 font-medium text-gray-500">PDF</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($invoices as $invoice)
                                    <tr>
                                        <td class="py-2 font-mono text-xs">{{ $invoice['number'] ?? $invoice['id'] }}</td>
                                        <td class="py-2">{{ $invoice['month'] ?? '—' }}</td>
                                        <td class="py-2">{{ $invoice['date'] }}</td>
                                        <td class="py-2">{{ $invoice['amount'] }} {{ $invoice['currency'] }}</td>
                                        <td class="py-2">{{ $invoice['stripe_status_label'] ?? 'Ikke tilgjengelig fra Stripe' }}</td>
                                        <td class="py-2">
                                            @if ($invoice['pdf_url'])
                                                <a href="{{ $invoice['pdf_url'] }}" target="_blank" class="text-primary-600 hover:underline text-xs">
                                                Last ned
                                            </a>
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="space-y-2 text-sm text-gray-600 dark:text-gray-400">
                    <p>Ingen faktura funnet.</p>
                    <p>Fakturaer, PDF-er, betaling, utestående og forfall hentes fra Stripe når dette finnes.</p>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
