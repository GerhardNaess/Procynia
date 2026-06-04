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
        <aside class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm lg:sticky lg:top-6">
            <div class="border-b border-gray-100 px-5 py-4">
                <p class="text-xs font-bold uppercase tracking-widest text-gray-400">Procynia intern</p>
                <h2 class="mt-1 text-xl font-extrabold tracking-tight text-gray-950">AI-lønnsomhet</h2>
                <p class="mt-1 text-sm text-gray-500">Velg kunde og periode. Visningen bygger på AI-case-ledgeren og historisk tokenkost.</p>
            </div>

            <div class="border-b border-gray-100 px-5 py-4 space-y-3">
                <label class="block text-sm font-bold text-gray-800" for="profitability-customer-select">Kunde</label>
                <select id="profitability-customer-select" wire:model.live="selectedCustomerId"
                    class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-100">
                    <option value="">Alle kunder samlet</option>
                    @foreach ($customerOptions as $customerId => $customerName)
                        <option value="{{ $customerId }}">{{ $customerName }}</option>
                    @endforeach
                </select>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-gray-600">Fra</label>
                        <input type="date" wire:model.live="dateFrom"
                            class="w-full rounded-xl border border-gray-300 bg-white px-2 py-1.5 text-xs text-gray-900 shadow-sm focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-100">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-gray-600">Til</label>
                        <input type="date" wire:model.live="dateTo"
                            class="w-full rounded-xl border border-gray-300 bg-white px-2 py-1.5 text-xs text-gray-900 shadow-sm focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-100">
                    </div>
                </div>

                <div class="grid gap-2 pt-1">
                    <button wire:click="applyFilters" type="button"
                        class="w-full rounded-xl bg-gray-900 px-4 py-2 text-sm font-bold text-white transition hover:bg-gray-700">
                        Oppdater visning
                    </button>
                    <button wire:click="resetFilters" type="button"
                        class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-100">
                        Nullstill filtre
                    </button>
                </div>
            </div>

            <div class="border-t border-amber-100 bg-amber-50 px-5 py-3">
                <p class="text-xs leading-relaxed text-amber-700">
                    <strong>Intern beregning:</strong>
                    Inntekt er en allokert verdi basert på plan og inkluderte AI-case.
                    Kost hentes fra historiske token-events og historisk modellpris.
                </p>
            </div>
        </aside>

        <section class="min-w-0 space-y-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-widest text-gray-400">Intern lønnsomhetsanalyse</p>
                    <h1 class="mt-1 text-3xl font-extrabold tracking-tight text-gray-950">{{ $pageContextTitle }}</h1>
                    <p class="mt-1 text-sm text-gray-500">Periode {{ $periodLabel }} · AI-case, inntekt og kost fra samme interne analysegrunnlag.</p>
                </div>
                <div class="inline-flex items-center gap-2 rounded-full border border-gray-200 bg-white px-4 py-2 text-sm font-bold text-gray-500 shadow-sm whitespace-nowrap">
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
                            'success' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
                            'warning' => 'border-amber-200 bg-amber-50 text-amber-700',
                            'danger' => 'border-rose-200 bg-rose-50 text-rose-700',
                            'primary' => 'border-gray-200 bg-white text-gray-700',
                        ];
                        $cardClass = $cardToneClasses[$card['tone']] ?? $cardToneClasses['primary'];
                    @endphp
                    <article class="flex h-36 flex-col rounded-2xl border p-4 shadow-sm {{ $cardClass }}">
                        <div class="min-h-[2.5rem] text-[11px] font-bold uppercase leading-snug tracking-wider text-gray-400">{{ $card['label'] }}</div>
                        <div class="text-2xl font-extrabold leading-none tracking-tight text-gray-950">{{ $card['value'] }}</div>
                        <div class="mt-auto text-xs font-medium text-gray-500">{{ $card['note'] }}</div>
                    </article>
                @endforeach
            </div>

            <p class="text-[11px] text-gray-400">
                Manglende prisgrunnlag, manglende kostgrunnlag og delvis beregnede rader vises eksplisitt for å unngå at usikre tall tolkes som fullverdige.
            </p>

            <article class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 px-5 py-4">
                    <h3 class="font-bold text-gray-900">Kundetabell</h3>
                    <p class="mt-0.5 text-sm text-gray-500">Allokert inntekt og intern AI-kost per kunde i valgt periode.</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100 text-left text-sm">
                        <thead class="bg-gray-50/80 text-xs uppercase tracking-wider text-gray-500">
                            <tr>
                                <th class="px-5 py-3">Kunde</th>
                                <th class="px-5 py-3">Allokert inntekt</th>
                                <th class="px-5 py-3">Intern AI-kost</th>
                                <th class="px-5 py-3">Dekningsbidrag</th>
                                <th class="px-5 py-3">Margin %</th>
                                <th class="px-5 py-3">Revenue status</th>
                                <th class="px-5 py-3">Cost status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @forelse ($customerRows as $row)
                                @php
                                    $statusClasses = [
                                        'ok' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
                                        'partial' => 'border-amber-200 bg-amber-50 text-amber-700',
                                        'missing' => 'border-rose-200 bg-rose-50 text-rose-700',
                                    ];
                                @endphp
                                <tr class="align-top">
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-gray-950">{{ $row['customer_name'] }}</div>
                                        <div class="mt-0.5 text-xs text-gray-400">{{ $row['plan_name'] ?? 'Ukjent plan' }}</div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-gray-950">{{ $this->moneyValue($row['allocated_revenue_nok'] ?? null, (string) ($row['revenue_status'] ?? 'missing')) }}</div>
                                        <div class="mt-0.5 text-xs text-gray-400">{{ $this->summaryNote('revenue', (string) ($row['revenue_status'] ?? 'missing'), $row['allocated_revenue_nok'] ?? null) }}</div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-gray-950">{{ $this->moneyValue($row['internal_cost_nok'] ?? null, (string) ($row['cost_status'] ?? 'missing')) }}</div>
                                        <div class="mt-0.5 text-xs text-gray-400">{{ $this->summaryNote('cost', (string) ($row['cost_status'] ?? 'missing'), $row['internal_cost_nok'] ?? null) }}</div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-gray-950">{{ $this->moneyValue($row['contribution_margin_nok'] ?? null, $this->rowMarginStatus((string) ($row['revenue_status'] ?? 'missing'), (string) ($row['cost_status'] ?? 'missing'))) }}</div>
                                        <div class="mt-0.5 text-xs text-gray-400">{{ $this->summaryNote('margin', $this->rowMarginStatus((string) ($row['revenue_status'] ?? 'missing'), (string) ($row['cost_status'] ?? 'missing')), $row['contribution_margin_nok'] ?? null) }}</div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-gray-950">{{ $this->percentValue($row['margin_percent'] ?? null) }}</div>
                                        <div class="mt-0.5 text-xs text-gray-400">Dekningsbidrag i prosent</div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold uppercase tracking-wider {{ $statusClasses[$this->statusLabel((string) ($row['revenue_status'] ?? 'missing'))] ?? $statusClasses['missing'] }}">
                                            {{ $this->statusLabel((string) ($row['revenue_status'] ?? 'missing')) }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold uppercase tracking-wider {{ $statusClasses[$this->statusLabel((string) ($row['cost_status'] ?? 'missing'))] ?? $statusClasses['missing'] }}">
                                            {{ $this->statusLabel((string) ($row['cost_status'] ?? 'missing')) }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-5 py-8 text-center text-sm text-gray-500">Ingen AI-case i valgt periode.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </article>

            <article class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 px-5 py-4">
                    <h3 class="font-bold text-gray-900">Casetabell</h3>
                    <p class="mt-0.5 text-sm text-gray-500">Detaljer per AI-aktivt case, måned og ledgerspor.</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100 text-left text-sm">
                        <thead class="bg-gray-50/80 text-xs uppercase tracking-wider text-gray-500">
                            <tr>
                                <th class="px-5 py-3">Kunde</th>
                                <th class="px-5 py-3">Case</th>
                                <th class="px-5 py-3">Måned</th>
                                <th class="px-5 py-3">Allokert inntekt</th>
                                <th class="px-5 py-3">Intern AI-kost</th>
                                <th class="px-5 py-3">Dekningsbidrag</th>
                                <th class="px-5 py-3">Margin %</th>
                                <th class="px-5 py-3">Revenue status</th>
                                <th class="px-5 py-3">Cost status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @forelse ($caseRows as $row)
                                @php
                                    $statusClasses = [
                                        'ok' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
                                        'partial' => 'border-amber-200 bg-amber-50 text-amber-700',
                                        'missing' => 'border-rose-200 bg-rose-50 text-rose-700',
                                    ];
                                    $caseLabel = $row['saved_notice_title'] ?: ($row['saved_notice_external_id'] ?? 'Ukjent case');
                                @endphp
                                <tr class="align-top">
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-gray-950">{{ $row['customer_name'] }}</div>
                                        <div class="mt-0.5 text-xs text-gray-400">{{ $row['plan_name'] ?? 'Ukjent plan' }}</div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-gray-950">{{ $caseLabel }}</div>
                                        <div class="mt-0.5 text-xs text-gray-400">#{{ $row['saved_notice_external_id'] ?? $row['saved_notice_id'] }}</div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-gray-950">{{ \Illuminate\Support\Carbon::parse($row['period_start'])->format('m.Y') }}</div>
                                        <div class="mt-0.5 text-xs text-gray-400">{{ $row['source_operation_key'] ?? 'Ukjent operasjon' }}</div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-gray-950">{{ $this->moneyValue($row['allocated_revenue_nok'] ?? null, (string) ($row['revenue_status'] ?? 'missing')) }}</div>
                                        <div class="mt-0.5 text-xs text-gray-400">{{ $this->summaryNote('revenue', (string) ($row['revenue_status'] ?? 'missing'), $row['allocated_revenue_nok'] ?? null) }}</div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-gray-950">{{ $this->moneyValue($row['internal_cost_nok'] ?? null, (string) ($row['cost_status'] ?? 'missing')) }}</div>
                                        <div class="mt-0.5 text-xs text-gray-400">{{ $this->summaryNote('cost', (string) ($row['cost_status'] ?? 'missing'), $row['internal_cost_nok'] ?? null) }}</div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-gray-950">{{ $this->moneyValue($row['contribution_margin_nok'] ?? null, $this->rowMarginStatus((string) ($row['revenue_status'] ?? 'missing'), (string) ($row['cost_status'] ?? 'missing'))) }}</div>
                                        <div class="mt-0.5 text-xs text-gray-400">{{ $this->summaryNote('margin', $this->rowMarginStatus((string) ($row['revenue_status'] ?? 'missing'), (string) ($row['cost_status'] ?? 'missing')), $row['contribution_margin_nok'] ?? null) }}</div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="font-semibold text-gray-950">{{ $this->percentValue($row['margin_percent'] ?? null) }}</div>
                                        <div class="mt-0.5 text-xs text-gray-400">Dekningsbidrag i prosent</div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold uppercase tracking-wider {{ $statusClasses[$this->statusLabel((string) ($row['revenue_status'] ?? 'missing'))] ?? $statusClasses['missing'] }}">
                                            {{ $this->statusLabel((string) ($row['revenue_status'] ?? 'missing')) }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold uppercase tracking-wider {{ $statusClasses[$this->statusLabel((string) ($row['cost_status'] ?? 'missing'))] ?? $statusClasses['missing'] }}">
                                            {{ $this->statusLabel((string) ($row['cost_status'] ?? 'missing')) }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="px-5 py-8 text-center text-sm text-gray-500">Ingen case-linjer i valgt periode.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </article>
        </section>
    </div>
</x-filament-panels::page>
