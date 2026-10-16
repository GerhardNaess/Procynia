<x-filament-panels::page>
    <style>
        .ai-profitability-layout {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
            align-items: start;
        }

        @media (min-width: 1024px) {
            .ai-profitability-layout {
                grid-template-columns: 320px minmax(0, 1fr);
            }
        }
    </style>

    <div class="ai-profitability-layout">
        <aside class="overflow-hidden rounded-2xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 shadow-sm lg:sticky lg:top-6">
            <div class="border-b border-gray-100 dark:border-white/10 px-5 py-4">
                <p class="text-base font-bold uppercase tracking-widest text-gray-600 dark:text-gray-400">Procynia intern</p>
                <h2 class="mt-1 text-xl font-extrabold tracking-tight text-gray-950 dark:text-white">AI-lønnsomhet</h2>
                <p class="mt-1 text-base leading-6 text-gray-600 dark:text-gray-400">Intern estimert analyse av AI-økonomi. Tallene er ikke fakturagrunnlag eller regnskap.</p>
            </div>

            <div class="border-b border-gray-100 dark:border-white/10 px-5 py-4 space-y-3">
                <label class="block text-base font-bold text-gray-800 dark:text-gray-100" for="profitability-customer-select">Kunde</label>
                <select id="profitability-customer-select" wire:model.live="selectedCustomerId"
                    class="w-full rounded-xl border border-field-border bg-white dark:bg-gray-900 px-3 py-2 text-base text-gray-900 dark:text-white shadow-sm focus:border-violet-600 focus:outline-none focus:ring-2 focus:ring-violet-600 dark:focus:border-violet-400 dark:focus:ring-violet-400">
                    <option value="">Alle kunder samlet</option>
                    @foreach ($customerOptions as $customerId => $customerName)
                        <option value="{{ $customerId }}">{{ $customerName }}</option>
                    @endforeach
                </select>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="mb-1 block text-base font-semibold text-gray-600 dark:text-gray-400" for="ai-profitability-date-from">Fra</label>
                        <input type="date" id="ai-profitability-date-from" wire:model.live="dateFrom"
                            class="w-full rounded-xl border border-field-border bg-white dark:bg-gray-900 px-2 py-2 text-base text-gray-900 dark:text-white shadow-sm focus:border-violet-600 focus:outline-none focus:ring-2 focus:ring-violet-600 dark:focus:border-violet-400 dark:focus:ring-violet-400">
                    </div>
                    <div>
                        <label class="mb-1 block text-base font-semibold text-gray-600 dark:text-gray-400" for="ai-profitability-date-to">Til</label>
                        <input type="date" id="ai-profitability-date-to" wire:model.live="dateTo"
                            class="w-full rounded-xl border border-field-border bg-white dark:bg-gray-900 px-2 py-2 text-base text-gray-900 dark:text-white shadow-sm focus:border-violet-600 focus:outline-none focus:ring-2 focus:ring-violet-600 dark:focus:border-violet-400 dark:focus:ring-violet-400">
                    </div>
                </div>

                <div class="grid gap-2 pt-1">
                    <button wire:click="applyFilters" type="button"
                        class="w-full rounded-xl bg-gray-900 dark:bg-gray-700 px-4 py-2 text-base font-bold text-white transition hover:bg-gray-700 dark:hover:bg-gray-600">
                        Oppdater visning
                    </button>
                    <button wire:click="resetFilters" type="button"
                        class="w-full rounded-xl border border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-white/5 px-4 py-2 text-base font-semibold text-gray-700 dark:text-gray-200 transition hover:bg-gray-100 dark:hover:bg-white/10">
                        Nullstill filtre
                    </button>
                </div>
            </div>

            <div class="border-t border-amber-100 dark:border-amber-500/30 bg-amber-50 dark:bg-amber-500/10 px-5 py-3">
                <p class="text-base leading-relaxed text-amber-700 dark:text-amber-400">
                    <strong>Intern estimert analyse:</strong>
                    Inntekt er allokert fra kundens avtale og AI-aktive saker.
                </p>
                <p class="mt-1 text-base leading-relaxed text-amber-700 dark:text-amber-400">
                    Intern kost er estimert fra tokenbruk, modellpriser og valutakurser.
                    Manglende eller delvise data betyr usikkerhet, ikke null.
                </p>
            </div>
        </aside>

        <section class="min-w-0 space-y-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-base font-bold uppercase tracking-widest text-gray-600 dark:text-gray-400">Intern estimert analyse</p>
                    <h2 class="mt-1 text-3xl font-extrabold tracking-tight text-gray-950 dark:text-white">{{ $pageContextTitle }}</h2>
                    <p class="mt-1 text-base leading-6 text-gray-600 dark:text-gray-400">Periode {{ $periodLabel }} · estimert inntekt, estimert intern AI-kost og estimert dekningsbidrag per kunde og case.</p>
                </div>
                <div class="inline-flex items-center gap-2 rounded-full border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 px-4 py-2.5 text-base leading-6 font-bold text-gray-600 dark:text-gray-400 shadow-sm whitespace-nowrap">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    {{ $periodLabel }}
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
                @foreach ($summaryCards as $card)
                    @php
                        $cardToneClasses = [
                            'success' => 'border-emerald-200 dark:border-emerald-500/30 bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
                            'warning' => 'border-amber-200 dark:border-amber-500/30 bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-400',
                            'danger' => 'border-rose-200 dark:border-rose-500/30 bg-rose-50 dark:bg-rose-500/10 text-rose-700 dark:text-rose-400',
                            'primary' => 'border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-200',
                        ];
                        $cardClass = $cardToneClasses[$card['tone']] ?? $cardToneClasses['primary'];
                    @endphp
                    <article class="flex min-h-40 flex-col rounded-2xl border p-4 shadow-sm {{ $cardClass }}">
                        <div class="min-h-12 text-base font-bold uppercase leading-6 tracking-wider text-gray-600 dark:text-gray-400">{{ $card['label'] }}</div>
                        <div class="text-2xl font-extrabold leading-none tracking-tight text-gray-950 dark:text-white">{{ $card['value'] }}</div>
                        <div class="mt-auto text-base font-medium leading-6 text-gray-600 dark:text-gray-400">{{ $card['note'] }}</div>
                    </article>
                @endforeach
            </div>

            <p class="text-base leading-6 text-gray-600 dark:text-gray-400">
                Manglende prisgrunnlag, manglende kostgrunnlag og delvis beregnede rader vises eksplisitt. Manglende data betyr usikkerhet, ikke null.
            </p>

            <article class="overflow-hidden rounded-2xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 shadow-sm">
                <div class="border-b border-gray-100 dark:border-white/10 px-5 py-4">
                    <h3 id="ai-profitability-table-heading-1" class="font-bold text-gray-900 dark:text-white">Kundetabell</h3>
                    <p class="mt-0.5 text-base leading-6 text-gray-600 dark:text-gray-400">Estimert inntekt og estimert intern AI-kost per kunde i valgt periode.</p>
                </div>

                <div class="relative overflow-x-auto" @include('filament.components.scroll-region-attrs', ['labelledby' => 'ai-profitability-table-heading-1'])>
                    <table class="min-w-full divide-y divide-gray-100 dark:divide-white/10 text-left text-base">
                        <thead class="bg-gray-50/80 dark:bg-white/5 text-base uppercase tracking-wider text-gray-600 dark:text-gray-400">
                            <tr>
                                <th scope="col" class="px-5 py-3">Kunde</th>
                                <th scope="col" class="px-5 py-3">Estimert inntekt</th>
                                <th scope="col" class="px-5 py-3">Estimert intern AI-kost</th>
                                <th scope="col" class="px-5 py-3">Estimert dekningsbidrag</th>
                                <th scope="col" class="px-5 py-3">Margin %</th>
                                <th scope="col" class="px-5 py-3">Revenue status</th>
                                <th scope="col" class="px-5 py-3">Cost status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/10 bg-white dark:bg-gray-900">
                            @forelse ($customerRows as $row)
                                @php
                                    $statusClasses = [
                                        'ok' => 'border-emerald-200 dark:border-emerald-500/30 bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
                                        'partial' => 'border-amber-200 dark:border-amber-500/30 bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-400',
                                        'missing' => 'border-rose-200 dark:border-rose-500/30 bg-rose-50 dark:bg-rose-500/10 text-rose-700 dark:text-rose-400',
                                    ];
                                @endphp
                                <tr class="align-top">
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-gray-950 dark:text-white">{{ $row['customer_name'] }}</div>
                                        <div class="mt-0.5 text-base leading-6 text-gray-600 dark:text-gray-400">{{ $row['plan_name'] ?? 'Ukjent plan' }}</div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-gray-950 dark:text-white">{{ $this->moneyValue($row['allocated_revenue_nok'] ?? null, (string) ($row['revenue_status'] ?? 'missing')) }}</div>
                                        <div class="mt-0.5 text-base leading-6 text-gray-600 dark:text-gray-400">{{ $this->summaryNote('revenue', (string) ($row['revenue_status'] ?? 'missing'), $row['allocated_revenue_nok'] ?? null) }}</div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-gray-950 dark:text-white">{{ $this->moneyValue($row['internal_cost_nok'] ?? null, (string) ($row['cost_status'] ?? 'missing')) }}</div>
                                        <div class="mt-0.5 text-base leading-6 text-gray-600 dark:text-gray-400">{{ $this->summaryNote('cost', (string) ($row['cost_status'] ?? 'missing'), $row['internal_cost_nok'] ?? null) }}</div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-gray-950 dark:text-white">{{ $this->moneyValue($row['contribution_margin_nok'] ?? null, $this->rowMarginStatus((string) ($row['revenue_status'] ?? 'missing'), (string) ($row['cost_status'] ?? 'missing'))) }}</div>
                                        <div class="mt-0.5 text-base leading-6 text-gray-600 dark:text-gray-400">{{ $this->summaryNote('margin', $this->rowMarginStatus((string) ($row['revenue_status'] ?? 'missing'), (string) ($row['cost_status'] ?? 'missing')), $row['contribution_margin_nok'] ?? null) }}</div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-gray-950 dark:text-white">{{ $this->percentValue($row['margin_percent'] ?? null) }}</div>
                                        <div class="mt-0.5 text-base leading-6 text-gray-600 dark:text-gray-400">Dekningsbidrag i prosent</div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="inline-flex rounded-full border px-2.5 py-1.5 text-base font-semibold uppercase tracking-wider leading-6 {{ $statusClasses[$this->statusLabel((string) ($row['revenue_status'] ?? 'missing'))] ?? $statusClasses['missing'] }}">
                                            {{ $this->statusLabel((string) ($row['revenue_status'] ?? 'missing')) }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="inline-flex rounded-full border px-2.5 py-1.5 text-base font-semibold uppercase tracking-wider leading-6 {{ $statusClasses[$this->statusLabel((string) ($row['cost_status'] ?? 'missing'))] ?? $statusClasses['missing'] }}">
                                            {{ $this->statusLabel((string) ($row['cost_status'] ?? 'missing')) }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-5 py-8 text-center text-base text-gray-600 dark:text-gray-400">Ingen AI-case i valgt periode.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </article>

            <article class="overflow-hidden rounded-2xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 shadow-sm">
                <div class="border-b border-gray-100 dark:border-white/10 px-5 py-4">
                    <h3 id="ai-profitability-table-heading-2" class="font-bold text-gray-900 dark:text-white">Casetabell</h3>
                    <p class="mt-0.5 text-base leading-6 text-gray-600 dark:text-gray-400">Detaljer per AI-aktivt case, måned og ledgerspor. Tallene er estimater.</p>
                </div>

                <div class="relative overflow-x-auto" @include('filament.components.scroll-region-attrs', ['labelledby' => 'ai-profitability-table-heading-2'])>
                    <table class="min-w-full divide-y divide-gray-100 dark:divide-white/10 text-left text-base">
                        <thead class="bg-gray-50/80 dark:bg-white/5 text-base uppercase tracking-wider text-gray-600 dark:text-gray-400">
                            <tr>
                                <th scope="col" class="px-5 py-3">Kunde</th>
                                <th scope="col" class="px-5 py-3">Case</th>
                                <th scope="col" class="px-5 py-3">Måned</th>
                                <th scope="col" class="px-5 py-3">Estimert inntekt</th>
                                <th scope="col" class="px-5 py-3">Estimert intern AI-kost</th>
                                <th scope="col" class="px-5 py-3">Estimert dekningsbidrag</th>
                                <th scope="col" class="px-5 py-3">Margin %</th>
                                <th scope="col" class="px-5 py-3">Revenue status</th>
                                <th scope="col" class="px-5 py-3">Cost status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/10 bg-white dark:bg-gray-900">
                            @forelse ($caseRows as $row)
                                @php
                                    $statusClasses = [
                                        'ok' => 'border-emerald-200 dark:border-emerald-500/30 bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
                                        'partial' => 'border-amber-200 dark:border-amber-500/30 bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-400',
                                        'missing' => 'border-rose-200 dark:border-rose-500/30 bg-rose-50 dark:bg-rose-500/10 text-rose-700 dark:text-rose-400',
                                    ];
                                    $caseLabel = $row['saved_notice_title'] ?: ($row['saved_notice_external_id'] ?? 'Ukjent case');
                                @endphp
                                <tr class="align-top">
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-gray-950 dark:text-white">{{ $row['customer_name'] }}</div>
                                        <div class="mt-0.5 text-base leading-6 text-gray-600 dark:text-gray-400">{{ $row['plan_name'] ?? 'Ukjent plan' }}</div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-gray-950 dark:text-white">{{ $caseLabel }}</div>
                                        <div class="mt-0.5 text-base leading-6 text-gray-600 dark:text-gray-400">#{{ $row['saved_notice_external_id'] ?? $row['saved_notice_id'] }}</div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-gray-950 dark:text-white">{{ \Illuminate\Support\Carbon::parse($row['period_start'])->format('m.Y') }}</div>
                                        <div class="mt-0.5 text-base leading-6 text-gray-600 dark:text-gray-400">{{ $row['source_operation_key'] ?? 'Ukjent operasjon' }}</div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-gray-950 dark:text-white">{{ $this->moneyValue($row['allocated_revenue_nok'] ?? null, (string) ($row['revenue_status'] ?? 'missing')) }}</div>
                                        <div class="mt-0.5 text-base leading-6 text-gray-600 dark:text-gray-400">{{ $this->summaryNote('revenue', (string) ($row['revenue_status'] ?? 'missing'), $row['allocated_revenue_nok'] ?? null) }}</div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-gray-950 dark:text-white">{{ $this->moneyValue($row['internal_cost_nok'] ?? null, (string) ($row['cost_status'] ?? 'missing')) }}</div>
                                        <div class="mt-0.5 text-base leading-6 text-gray-600 dark:text-gray-400">{{ $this->summaryNote('cost', (string) ($row['cost_status'] ?? 'missing'), $row['internal_cost_nok'] ?? null) }}</div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-gray-950 dark:text-white">{{ $this->moneyValue($row['contribution_margin_nok'] ?? null, $this->rowMarginStatus((string) ($row['revenue_status'] ?? 'missing'), (string) ($row['cost_status'] ?? 'missing'))) }}</div>
                                        <div class="mt-0.5 text-base leading-6 text-gray-600 dark:text-gray-400">{{ $this->summaryNote('margin', $this->rowMarginStatus((string) ($row['revenue_status'] ?? 'missing'), (string) ($row['cost_status'] ?? 'missing')), $row['contribution_margin_nok'] ?? null) }}</div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-gray-950 dark:text-white">{{ $this->percentValue($row['margin_percent'] ?? null) }}</div>
                                        <div class="mt-0.5 text-base leading-6 text-gray-600 dark:text-gray-400">Dekningsbidrag i prosent</div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="inline-flex rounded-full border px-2.5 py-1.5 text-base font-semibold uppercase tracking-wider leading-6 {{ $statusClasses[$this->statusLabel((string) ($row['revenue_status'] ?? 'missing'))] ?? $statusClasses['missing'] }}">
                                            {{ $this->statusLabel((string) ($row['revenue_status'] ?? 'missing')) }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="inline-flex rounded-full border px-2.5 py-1.5 text-base font-semibold uppercase tracking-wider leading-6 {{ $statusClasses[$this->statusLabel((string) ($row['cost_status'] ?? 'missing'))] ?? $statusClasses['missing'] }}">
                                            {{ $this->statusLabel((string) ($row['cost_status'] ?? 'missing')) }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="px-5 py-8 text-center text-base text-gray-600 dark:text-gray-400">Ingen case-linjer i valgt periode.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </article>
        </section>
    </div>
</x-filament-panels::page>
