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
        $billingBasis = $billingBasis ?? [];
        $billingBasisCustomer = data_get($billingBasis, 'customer', []);
        $billingBasisLineGroups = data_get($billingBasis, 'line_groups', []);
        $billingBasisInvoices = data_get($billingBasis, 'invoices', []);
        $billingBasisReconciliation = data_get($billingBasis, 'reconciliation', []);

        $billingBusinessDiscountPercent = (float) data_get($billingBasisCustomer, 'billing_discount_percent', 0);

        $billingBusinessCustomerSpecificPriceLines = collect(data_get($billingBasisLineGroups, 'customer_specific_prices.lines', []));
        $billingBusinessAdditionalLines = collect(data_get($billingBasisLineGroups, 'recurring_addons.lines', []))
            ->merge(data_get($billingBasisLineGroups, 'one_time_charges.lines', []))
            ->values();
        $billingBusinessHasCustomerSpecificPrices = $billingBusinessCustomerSpecificPriceLines->isNotEmpty();
        $billingBusinessCustomerSpecificPriceDetail = $billingBusinessCustomerSpecificPriceLines->first();

        $billingBusinessActiveLines = collect(data_get($billingBasisLineGroups, 'base_subscription.lines', []))
            ->merge(data_get($billingBasisLineGroups, 'user_based_lines.lines', []))
            ->merge(data_get($billingBasisLineGroups, 'recurring_addons.lines', []))
            ->merge(data_get($billingBasisLineGroups, 'one_time_charges.lines', []))
            ->merge(data_get($billingBasisLineGroups, 'manual_or_other_lines.lines', []))
            ->merge($billingBusinessCustomerSpecificPriceLines)
            ->values();

        $billingBusinessCalculableLines = $billingBusinessActiveLines
            ->filter(fn (array $line): bool => ($line['calculation_status'] ?? null) === \App\Services\Billing\CustomerBillingBasisService::CALCULATION_STATUS_COMPLETE)
            ->values();

        $billingBusinessCurrencyGroups = $billingBusinessCalculableLines
            ->groupBy(fn (array $line): string => (string) ($line['currency'] ?? ''))
            ->filter(fn ($lines, string $currency): bool => $currency !== '' && collect($lines)->isNotEmpty())
            ->map(function ($lines, string $currency) use ($billingBusinessDiscountPercent): array {
                $lines = collect($lines);
                $beforeDiscount = (int) $lines->sum(fn (array $line): int => (int) ($line['line_total'] ?? 0));
                $discountAmount = (int) round($beforeDiscount * ($billingBusinessDiscountPercent / 100));
                $afterDiscount = max(0, $beforeDiscount - $discountAmount);

                return [
                    'currency' => strtoupper($currency),
                    'currency_label' => strtoupper($currency),
                    'line_count' => $lines->count(),
                    'before_discount_label' => number_format($beforeDiscount / 100, 0, ',', ' ') . ' ' . strtoupper($currency),
                    'discount_amount_label' => number_format($discountAmount / 100, 0, ',', ' ') . ' ' . strtoupper($currency),
                    'after_discount_label' => number_format($afterDiscount / 100, 0, ',', ' ') . ' ' . strtoupper($currency),
                ];
            })
            ->values();

        $billingBusinessTotals = [
            'currency_label' => 'Ikke beregnbar',
            'before_discount_label' => 'Ikke beregnbar',
            'discount_percent_label' => $billingBusinessDiscountPercent > 0 ? number_format($billingBusinessDiscountPercent, 2, ',', ' ') . ' %' : 'Ingen rabatt',
            'discount_amount_label' => 'Ikke beregnbar',
            'after_discount_label' => 'Ikke beregnbar',
            'has_multiple_currencies' => $billingBusinessCurrencyGroups->count() > 1,
            'currency_groups' => $billingBusinessCurrencyGroups,
        ];

        if ($billingBusinessCurrencyGroups->isNotEmpty()) {
            if (! $billingBusinessTotals['has_multiple_currencies']) {
                $billingBusinessTotals['currency_label'] = (string) data_get($billingBusinessCurrencyGroups[0], 'currency_label', 'Ikke beregnbar');
                $billingBusinessTotals['before_discount_label'] = (string) data_get($billingBusinessCurrencyGroups[0], 'before_discount_label', 'Ikke beregnbar');
                $billingBusinessTotals['discount_amount_label'] = (string) data_get($billingBusinessCurrencyGroups[0], 'discount_amount_label', 'Ikke beregnbar');
                $billingBusinessTotals['after_discount_label'] = (string) data_get($billingBusinessCurrencyGroups[0], 'after_discount_label', 'Ikke beregnbar');
            } else {
                $billingBusinessTotals['currency_label'] = 'Ikke beregnbar på tvers av valuta';
                $billingBusinessTotals['before_discount_label'] = 'Ikke beregnbar på tvers av valuta';
                $billingBusinessTotals['discount_amount_label'] = 'Ikke beregnbar på tvers av valuta';
                $billingBusinessTotals['after_discount_label'] = 'Ikke beregnbar på tvers av valuta';
            }
        }

        $billingBusinessLineTypeLabel = static function (?string $groupKey): string {
            return match ($groupKey) {
                'base_subscription' => 'Abonnement',
                'user_based_lines' => 'Bruker',
                'recurring_addons' => 'Tillegg',
                'one_time_charges' => 'Engangstjeneste',
                'customer_specific_prices' => 'Kundespesifikk pris',
                default => 'Annet',
            };
        };

        $billingBusinessLineReason = static function (array $line): string {
            $warnings = collect($line['warnings'] ?? [])->filter()->values();

            if ($warnings->contains('Kundespesifikk pris mangler avtalt beløp.') || $warnings->contains('Avtalt pris kan ikke beregnes sikkert.')) {
                return 'Kundespesifikk pris mangler';
            }

            if ($warnings->contains('Linjen mangler prisgrunnlag.')) {
                return 'Pris mangler';
            }

            if ($warnings->contains('Linjen mangler valuta.')) {
                return 'Valuta mangler';
            }

            if ($warnings->contains('Linjen mangler gyldig antall.')) {
                return 'Antall mangler';
            }

            return 'Ikke beregnbar';
        };

        $billingBusinessHasStripeCustomer = filled($record->stripe_id);
        $billingBusinessHasInvoice = filled(data_get($billingBasisInvoices, 'latest_invoice'));
        $billingBusinessHasActiveLines = $billingBusinessActiveLines->isNotEmpty();
        $billingBusinessHasNonCalculableLines = $billingBusinessHasActiveLines
            && $billingBusinessActiveLines->count() !== $billingBusinessCalculableLines->count();
        $billingBusinessHasPartialLines = $billingBusinessHasNonCalculableLines;
        $billingHasLineToInvoiceLinks = (bool) data_get($billingBasisReconciliation, 'has_line_to_invoice_links', false);

        if (! $billingBusinessHasStripeCustomer) {
            $billingBusinessStatusLabel = 'Mangler fakturaoppsett';
            $billingBusinessStatusText = 'Kunden er ikke koblet til fakturasystemet.';
            $billingBusinessStatusClass = 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-100';
        } elseif ($billingBusinessHasNonCalculableLines) {
            $billingBusinessStatusLabel = 'Ikke beregnbar';
            $billingBusinessStatusText = 'En eller flere linjer mangler pris, valuta eller antall.';
            $billingBusinessStatusClass = 'bg-warning-100 text-warning-800 dark:bg-warning-500/20 dark:text-warning-100';
        } elseif ($billingBusinessHasInvoice) {
            $billingBusinessStatusLabel = 'Faktura funnet';
            $billingBusinessStatusText = 'Procynia har registrert faktura fra Stripe.';
            $billingBusinessStatusClass = 'bg-success-100 text-success-800 dark:bg-success-500/20 dark:text-success-100';
        } elseif ($billingBusinessHasActiveLines) {
            $billingBusinessStatusLabel = 'Må kontrolleres';
            $billingBusinessStatusText = 'Kunden har registrerte fakturalinjer i Procynia, men ingen faktura er funnet.';
            $billingBusinessStatusClass = 'bg-warning-100 text-warning-800 dark:bg-warning-500/20 dark:text-warning-100';
        } else {
            $billingBusinessStatusLabel = 'Må kontrolleres';
            $billingBusinessStatusText = 'Kunden har ikke et tydelig fakturabilde ennå.';
            $billingBusinessStatusClass = 'bg-warning-100 text-warning-800 dark:bg-warning-500/20 dark:text-warning-100';
        }

        $billingLatestInvoice = data_get($billingBasisInvoices, 'latest_invoice');
        $billingLatestInvoiceStatus = (string) data_get($billingLatestInvoice, 'status', '');
        $billingLatestInvoiceStatusLabel = match ($billingLatestInvoiceStatus) {
            'paid' => 'Betalt',
            'open', 'draft', 'past_due' => 'Ikke betalt',
            default => filled($billingLatestInvoice) ? 'Ikke betalt' : 'Ingen faktura funnet',
        };
        $billingLatestInvoiceLabel = filled($billingLatestInvoice)
            ? trim((string) data_get($billingLatestInvoice, 'stripe_invoice_id', data_get($billingLatestInvoice, 'id', '—')) . ' · ' . (string) data_get($billingLatestInvoice, 'invoice_date_label', '—'))
            : 'Ingen faktura funnet';
        $billingLatestInvoiceAmountLabel = filled($billingLatestInvoice)
            ? (string) data_get($billingLatestInvoice, 'amount_paid_label', 'Ikke beregnbar')
            : 'Ingen faktura funnet';
        $billingLatestInvoiceDateLabel = filled($billingLatestInvoice)
            ? (string) data_get($billingLatestInvoice, 'invoice_date_label', '—')
            : '—';
        $billingLatestInvoiceStatusText = filled($billingLatestInvoice)
            ? ($billingLatestInvoiceStatusLabel === 'Betalt' ? 'Betalt' : 'Ikke betalt')
            : 'Ingen faktura funnet';

        $billingBusinessCustomerSpecificPriceNames = $billingBusinessCustomerSpecificPriceLines
            ->pluck('description')
            ->filter()
            ->take(3)
            ->values();
        $billingBusinessAdditionalNames = $billingBusinessAdditionalLines
            ->pluck('description')
            ->filter()
            ->take(3)
            ->values();

        $billingBusinessActiveLineRows = $billingBusinessActiveLines
            ->map(function (array $line) use ($billingBusinessLineTypeLabel, $billingBusinessLineReason): array {
                $status = (string) ($line['calculation_status'] ?? 'not_calculable');

                return [
                    'description' => $line['description'] ?? '—',
                    'type_label' => $billingBusinessLineTypeLabel($line['group_key'] ?? null),
                    'quantity' => $line['quantity'] ?? null,
                    'price_label' => $line['amount_label'] ?? 'Ikke beregnbar',
                    'sum_label' => $line['line_total_label'] ?? 'Ikke beregnbar',
                    'status_label' => match ($status) {
                        \App\Services\Billing\CustomerBillingBasisService::CALCULATION_STATUS_COMPLETE => 'Beregnet',
                        \App\Services\Billing\CustomerBillingBasisService::CALCULATION_STATUS_PARTIAL => 'Delvis beregnet',
                        default => 'Ikke beregnbar',
                    },
                    'reason' => $status === \App\Services\Billing\CustomerBillingBasisService::CALCULATION_STATUS_COMPLETE
                        ? null
                        : $billingBusinessLineReason($line),
                ];
            })
            ->values();
    @endphp

    <div class="space-y-6 text-left">
        <x-filament::section heading="Kundens avtale og fakturering">
            <div class="space-y-6">
                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                    <div class="space-y-2">
                        <div class="text-base font-semibold uppercase tracking-wide text-gray-600">Status</div>
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="inline-flex items-center rounded-full px-3 py-1.5 text-base font-semibold leading-6 {{ $billingBusinessStatusClass }}">
                                {{ $billingBusinessStatusLabel }}
                            </span>
                            <span class="text-base leading-6 text-gray-600 dark:text-gray-400">{{ $billingBusinessStatusText }}</span>
                        </div>
                    </div>
                </div>

                <div class="space-y-3">
                    <div>
                        <h3 class="text-base font-semibold text-gray-950 dark:text-white">Kundens avtale</h3>
                        <p class="text-base leading-6 text-gray-600 dark:text-gray-400">Kort oversikt over avtalen og linjene som ligger til grunn.</p>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800/40">
                            <div class="text-base font-semibold uppercase tracking-wide text-gray-600">Plan</div>
                            <div class="mt-2 text-base font-semibold text-gray-950 dark:text-white">{{ $billingBusinessCustomer['plan_label'] ?? $record->planName() }}</div>
                        </div>

                        <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800/40">
                            <div class="text-base font-semibold uppercase tracking-wide text-gray-600">Faktureres</div>
                            <div class="mt-2 text-base font-semibold text-gray-950 dark:text-white">{{ $billingBusinessCustomer['billing_interval_label'] ?? 'Ikke satt' }}</div>
                        </div>

                        <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800/40">
                            <div class="text-base font-semibold uppercase tracking-wide text-gray-600">Kunderabatt</div>
                            <div class="mt-2 text-base font-semibold text-gray-950 dark:text-white">
                                {{ $billingBusinessDiscountPercent > 0 ? number_format($billingBusinessDiscountPercent, 2, ',', ' ') . ' %' : 'Ingen rabatt' }}
                            </div>
                        </div>

                        <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800/40">
                            <div class="text-base font-semibold uppercase tracking-wide text-gray-600">Inkludert</div>
                            <div class="mt-2 text-base font-semibold text-gray-950 dark:text-white">
                                Brukere: {{ $billingBusinessCustomer['included_users'] ?? 0 }} · AI-kreditter: {{ filled($billingBusinessCustomer['included_ai_credits'] ?? null) ? $billingBusinessCustomer['included_ai_credits'] : 'Ikke satt' }}
                            </div>
                        </div>

                        <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800/40">
                            <div class="text-base font-semibold uppercase tracking-wide text-gray-600">Tilleggstjenester</div>
                            <div class="mt-2 text-base font-semibold text-gray-950 dark:text-white">{{ $billingBusinessAdditionalLines->count() }} linjer</div>
                        </div>

                        @if ($billingBusinessHasCustomerSpecificPrices)
                            <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800/40">
                                <div class="text-base font-semibold uppercase tracking-wide text-gray-600">Kundespesifikk pris</div>
                                <div class="mt-2 text-base font-semibold text-gray-950 dark:text-white">{{ $billingBusinessCustomerSpecificPriceLines->count() }} linjer</div>
                                <div class="mt-3 space-y-2 text-base leading-6 text-gray-600 dark:text-gray-400">
                                    <div>
                                        <div class="text-base font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400">Standardpris</div>
                                        <div class="font-semibold text-gray-950 dark:text-white">{{ data_get($billingBusinessCustomerSpecificPriceDetail, 'standard_amount_label', 'Ikke beregnbar') }}</div>
                                    </div>
                                    <div>
                                        <div class="text-base font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400">Avtalt pris</div>
                                        <div class="font-semibold text-gray-950 dark:text-white">{{ data_get($billingBusinessCustomerSpecificPriceDetail, 'custom_amount_label', 'Ikke beregnbar') }}</div>
                                    </div>
                                    @if (filled(data_get($billingBusinessCustomerSpecificPriceDetail, 'metadata.notes')))
                                        <div class="text-base leading-6 text-gray-600 dark:text-gray-400">{{ data_get($billingBusinessCustomerSpecificPriceDetail, 'metadata.notes') }}</div>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>

                    @if (! $billingBusinessHasCustomerSpecificPrices)
                        <div class="text-base leading-6 text-gray-600 dark:text-gray-400">
                            Ingen kundespesifikke priser registrert.
                        </div>
                    @endif
                </div>

                <div class="space-y-3">
                    <div>
                        <h3 class="text-base font-semibold text-gray-950 dark:text-white">Hva skal faktureres</h3>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                            <div class="text-base font-semibold uppercase tracking-wide text-gray-600">Sum aktive interne linjer</div>
                            <div class="mt-2 text-base font-semibold text-gray-950 dark:text-white">{{ $billingBusinessTotals['before_discount_label'] ?? 'Ikke beregnbar' }}</div>
                        </div>

                        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                            <div class="text-base font-semibold uppercase tracking-wide text-gray-600">Rabatt</div>
                            <div class="mt-2 text-base font-semibold text-gray-950 dark:text-white">{{ $billingBusinessTotals['discount_percent_label'] ?? 'Ingen rabatt' }}</div>
                        </div>

                        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                            <div class="text-base font-semibold uppercase tracking-wide text-gray-600">Rabattbeløp</div>
                            <div class="mt-2 text-base font-semibold text-gray-950 dark:text-white">{{ $billingBusinessTotals['discount_amount_label'] ?? 'Ikke beregnbar' }}</div>
                        </div>

                        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                            <div class="text-base font-semibold uppercase tracking-wide text-gray-600">Sum etter rabatt</div>
                            <div class="mt-2 text-base font-semibold text-gray-950 dark:text-white">{{ $billingBusinessTotals['after_discount_label'] ?? 'Ikke beregnbar' }}</div>
                        </div>
                    </div>

                    @if (($billingBusinessTotals['has_multiple_currencies'] ?? false) === true)
                        <div class="rounded-2xl border border-warning-300 bg-warning-50 px-5 py-4 text-base leading-6 text-warning-900 shadow-sm dark:border-warning-500/40 dark:bg-warning-500/10 dark:text-warning-100">
                            <div class="font-semibold">Ikke beregnbar på tvers av valuta</div>
                            <p class="mt-1">Totalsummen vises per valuta.</p>
                        </div>

                        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                            @foreach ($billingBusinessTotals['currency_groups'] as $currencyGroup)
                                <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800/40">
                                    <div class="text-base font-semibold text-gray-950 dark:text-white">{{ $currencyGroup['currency_label'] ?? '—' }}</div>
                                    <div class="mt-2 space-y-1 text-base leading-6 text-gray-600 dark:text-gray-400">
                                        <div>Linjer: {{ $currencyGroup['line_count'] ?? 0 }}</div>
                                        <div>Sum beregnbare linjer: {{ $currencyGroup['before_discount_label'] ?? 'Ikke beregnbar' }}</div>
                                        <div>Rabatt: {{ $currencyGroup['discount_amount_label'] ?? 'Ikke beregnbar' }}</div>
                                        <div>Sum etter rabatt: {{ $currencyGroup['after_discount_label'] ?? 'Ikke beregnbar' }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-base leading-6">
                            <thead class="border-b border-gray-200 dark:border-gray-700">
                                <tr>
                                    <th class="pb-2 font-medium text-gray-600">Beskrivelse</th>
                                    <th class="pb-2 font-medium text-gray-600">Type</th>
                                    <th class="pb-2 font-medium text-gray-600">Antall</th>
                                    <th class="pb-2 font-medium text-gray-600">Pris</th>
                                    <th class="pb-2 font-medium text-gray-600">Sum</th>
                                    <th class="pb-2 font-medium text-gray-600">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @forelse ($billingBusinessActiveLineRows as $line)
                                    @php
                                        $lineStatusClass = match ($line['status_label'] ?? 'Ikke beregnbar') {
                                            'Beregnet' => 'bg-success-100 text-success-800 dark:bg-success-500/20 dark:text-success-100',
                                            'Delvis beregnet' => 'bg-warning-100 text-warning-800 dark:bg-warning-500/20 dark:text-warning-100',
                                            default => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-100',
                                        };
                                    @endphp
                                    <tr>
                                        <td class="py-2 font-medium">{{ $line['description'] ?? '—' }}</td>
                                        <td class="py-2">{{ $line['type_label'] ?? 'Annet' }}</td>
                                        <td class="py-2">{{ $line['quantity'] ?? '—' }}</td>
                                        <td class="py-2">{{ $line['price_label'] ?? 'Ikke beregnbar' }}</td>
                                        <td class="py-2">{{ $line['sum_label'] ?? 'Ikke beregnbar' }}</td>
                                        <td class="py-2">
                                            <div class="space-y-1">
                                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-base font-semibold leading-6 {{ $lineStatusClass }}">
                                                    {{ $line['status_label'] ?? 'Ikke beregnbar' }}
                                                </span>
                                                @if (filled($line['reason'] ?? null))
                                                    <div class="text-base leading-6 text-gray-600 dark:text-gray-400">{{ $line['reason'] }}</div>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td class="py-4 text-base text-gray-600" colspan="6">Ingen linjer å vise ennå.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="space-y-3">
                    <div>
                        <h3 class="text-base font-semibold text-gray-950 dark:text-white">Faktura og betaling</h3>
                    </div>

                    @if (filled($billingLatestInvoice))
                        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                            <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                                <div class="text-base font-semibold uppercase tracking-wide text-gray-600">Siste faktura</div>
                                <div class="mt-2 text-base font-semibold text-gray-950 dark:text-white">{{ $billingLatestInvoiceLabel }}</div>
                            </div>

                            <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                                <div class="text-base font-semibold uppercase tracking-wide text-gray-600">Fakturastatus</div>
                                <div class="mt-2 text-base font-semibold text-gray-950 dark:text-white">{{ $billingLatestInvoiceStatusLabel }}</div>
                            </div>

                            <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                                <div class="text-base font-semibold uppercase tracking-wide text-gray-600">Betalt beløp</div>
                                <div class="mt-2 text-base font-semibold text-gray-950 dark:text-white">{{ $billingLatestInvoiceAmountLabel }}</div>
                            </div>

                            <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/40">
                                <div class="text-base font-semibold uppercase tracking-wide text-gray-600">Fakturadato</div>
                                <div class="mt-2 text-base font-semibold text-gray-950 dark:text-white">{{ $billingLatestInvoiceDateLabel }}</div>
                            </div>
                        </div>

                        @if (! $billingHasLineToInvoiceLinks)
                            <div class="rounded-2xl border border-warning-300 bg-warning-50 px-5 py-4 text-base leading-6 text-warning-900 shadow-sm dark:border-warning-500/40 dark:bg-warning-500/10 dark:text-warning-100">
                                Procynia finner ingen sikker kobling mellom linjene og fakturaen.
                            </div>
                        @endif
                    @else
                        <div class="rounded-2xl border border-warning-300 bg-warning-50 px-5 py-4 text-base leading-6 text-warning-900 shadow-sm dark:border-warning-500/40 dark:bg-warning-500/10 dark:text-warning-100">
                            <div class="font-semibold">Faktura mangler</div>
                            <div class="mt-3 grid gap-3 md:grid-cols-3">
                                <div class="rounded-xl bg-white/70 px-4 py-3 text-gray-800 dark:bg-gray-950/20 dark:text-gray-100">
                                    <div class="text-base font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400">Fakturastatus</div>
                                    <div class="mt-1 font-semibold">Ingen faktura funnet</div>
                                </div>
                                <div class="rounded-xl bg-white/70 px-4 py-3 text-gray-800 dark:bg-gray-950/20 dark:text-gray-100">
                                    <div class="text-base font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400">Betalt beløp</div>
                                    <div class="mt-1 font-semibold">Ikke registrert</div>
                                </div>
                                <div class="rounded-xl bg-white/70 px-4 py-3 text-gray-800 dark:bg-gray-950/20 dark:text-gray-100">
                                    <div class="text-base font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400">Fakturadato</div>
                                    <div class="mt-1 font-semibold">Ikke registrert</div>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
