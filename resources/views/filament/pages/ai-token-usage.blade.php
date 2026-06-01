<x-filament-panels::page>
    <div class="space-y-6">

        {{-- Disclaimer --}}
        <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5">
            <div class="flex items-start gap-3">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                </svg>
                <div class="space-y-2">
                    <div class="text-sm font-semibold text-amber-800">
                        Intern Procynia-visning · Ikke kundevendt fakturagrunnlag
                    </div>
                    <p class="text-sm text-amber-700">
                        Denne siden viser registrert teknisk AI-tokenforbruk basert på <code class="rounded bg-amber-100 px-1 font-mono text-xs">ai_token_events</code>.
                        Datagrunnlaget er foreløpig delvis:
                    </p>
                    <ul class="list-inside list-disc space-y-1 text-sm text-amber-700">
                        <li>Svarutkast-generering er instrumentert i <code class="rounded bg-amber-100 px-1 font-mono text-xs">ai_token_events</code>.</li>
                        <li>Kravekstraksjon har tokenlogging i <code class="rounded bg-amber-100 px-1 font-mono text-xs">requirement_extraction_runs</code> og <code class="rounded bg-amber-100 px-1 font-mono text-xs">requirement_extraction_calls</code>, men er ikke samlet her.</li>
                        <li>Øvrige AI-funksjoner kan mangle tokenlogging.</li>
                    </ul>
                    <p class="text-sm text-amber-700">
                        Tallene er intern teknisk oversikt, ikke komplett fakturagrunnlag. Ingen kostnad beregnes ennå.
                    </p>
                </div>
            </div>
        </section>

        {{-- Period filter --}}
        <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-center gap-4">
                <div class="text-sm font-semibold text-gray-700">Velg periode:</div>
                <div class="flex items-center gap-2">
                    <label for="year-select" class="sr-only">År</label>
                    <select
                        id="year-select"
                        wire:model.live="selectedYear"
                        class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-900 shadow-sm focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-100"
                    >
                        @foreach ($this->selectableYears() as $year)
                            <option value="{{ $year }}">{{ $year }}</option>
                        @endforeach
                    </select>

                    <label for="month-select" class="sr-only">Måned</label>
                    <select
                        id="month-select"
                        wire:model.live="selectedMonth"
                        class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-900 shadow-sm focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-100"
                    >
                        @foreach ($this->selectableMonths() as $num => $name)
                            <option value="{{ $num }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="text-sm text-gray-500">
                    Viser data for <strong>{{ $periodLabel }}</strong>
                </div>
            </div>
        </section>

        {{-- Per customer --}}
        <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
            <div class="mb-4 space-y-1">
                <div class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">AI-tokenforbruk</div>
                <h3 class="text-lg font-semibold text-gray-900">Per kunde</h3>
                <p class="text-sm text-gray-500">Basert på <code class="rounded bg-gray-100 px-1 font-mono text-xs">ai_token_events</code> for valgt måned.</p>
            </div>

            @if (count($customerRows) > 0)
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-gray-200 text-gray-500">
                            <tr>
                                <th class="pb-2 pr-6 font-medium">Kunde</th>
                                <th class="pb-2 pr-6 text-right font-medium">Antall kall</th>
                                <th class="pb-2 pr-6 text-right font-medium">Input tokens</th>
                                <th class="pb-2 pr-6 text-right font-medium">Output tokens</th>
                                <th class="pb-2 text-right font-medium">Total tokens</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-gray-900">
                            @foreach ($customerRows as $row)
                                <tr>
                                    <td class="py-2 pr-6 font-medium">{{ $row['customer_name'] }}</td>
                                    <td class="py-2 pr-6 text-right tabular-nums">{{ number_format($row['event_count'], 0, ',', ' ') }}</td>
                                    <td class="py-2 pr-6 text-right tabular-nums">{{ number_format($row['total_input_tokens'], 0, ',', ' ') }}</td>
                                    <td class="py-2 pr-6 text-right tabular-nums">{{ number_format($row['total_output_tokens'], 0, ',', ' ') }}</td>
                                    <td class="py-2 text-right font-semibold tabular-nums">{{ number_format($row['total_tokens_sum'], 0, ',', ' ') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="border-t border-gray-200 text-gray-700">
                            <tr>
                                <td class="pt-2 pr-6 font-semibold">Totalt</td>
                                <td class="pt-2 pr-6 text-right tabular-nums font-semibold">{{ number_format(array_sum(array_column($customerRows, 'event_count')), 0, ',', ' ') }}</td>
                                <td class="pt-2 pr-6 text-right tabular-nums font-semibold">{{ number_format(array_sum(array_column($customerRows, 'total_input_tokens')), 0, ',', ' ') }}</td>
                                <td class="pt-2 pr-6 text-right tabular-nums font-semibold">{{ number_format(array_sum(array_column($customerRows, 'total_output_tokens')), 0, ',', ' ') }}</td>
                                <td class="pt-2 text-right tabular-nums font-semibold">{{ number_format(array_sum(array_column($customerRows, 'total_tokens_sum')), 0, ',', ' ') }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @else
                <p class="py-8 text-center text-sm text-gray-400">Ingen token-events registrert for {{ $periodLabel }}.</p>
            @endif
        </section>

        {{-- Per operation_key og per model side om side --}}
        <div class="grid gap-6 lg:grid-cols-2">

            <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="mb-4 space-y-1">
                    <div class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Fordeling</div>
                    <h3 class="text-lg font-semibold text-gray-900">Per operation_key</h3>
                </div>

                @if (count($operationRows) > 0)
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b border-gray-200 text-gray-500">
                                <tr>
                                    <th class="pb-2 pr-4 font-medium">Operasjon</th>
                                    <th class="pb-2 pr-4 text-right font-medium">Kall</th>
                                    <th class="pb-2 text-right font-medium">Total tokens</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 text-gray-900">
                                @foreach ($operationRows as $row)
                                    <tr>
                                        <td class="py-2 pr-4 font-mono text-xs text-gray-700">{{ $row['operation_key'] }}</td>
                                        <td class="py-2 pr-4 text-right tabular-nums">{{ number_format($row['event_count'], 0, ',', ' ') }}</td>
                                        <td class="py-2 text-right tabular-nums font-semibold">{{ number_format($row['total_tokens_sum'], 0, ',', ' ') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="py-6 text-center text-sm text-gray-400">Ingen data.</p>
                @endif
            </section>

            <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="mb-4 space-y-1">
                    <div class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Fordeling</div>
                    <h3 class="text-lg font-semibold text-gray-900">Per modell</h3>
                </div>

                @if (count($modelRows) > 0)
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b border-gray-200 text-gray-500">
                                <tr>
                                    <th class="pb-2 pr-4 font-medium">Modell</th>
                                    <th class="pb-2 pr-4 text-right font-medium">Kall</th>
                                    <th class="pb-2 pr-4 text-right font-medium">Input</th>
                                    <th class="pb-2 text-right font-medium">Output</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 text-gray-900">
                                @foreach ($modelRows as $row)
                                    <tr>
                                        <td class="py-2 pr-4 font-mono text-xs text-gray-700">{{ $row['model'] }}</td>
                                        <td class="py-2 pr-4 text-right tabular-nums">{{ number_format($row['event_count'], 0, ',', ' ') }}</td>
                                        <td class="py-2 pr-4 text-right tabular-nums">{{ number_format($row['total_input_tokens'], 0, ',', ' ') }}</td>
                                        <td class="py-2 text-right tabular-nums font-semibold">{{ number_format($row['total_output_tokens'], 0, ',', ' ') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="py-6 text-center text-sm text-gray-400">Ingen data.</p>
                @endif
            </section>
        </div>

        {{-- Siste token-events --}}
        <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
            <div class="mb-4 space-y-1">
                <div class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Hendelseslogg</div>
                <h3 class="text-lg font-semibold text-gray-900">Siste token-events (maks 30)</h3>
                <p class="text-sm text-gray-500">Viser de siste registrerte AI-kall for valgt måned, nyeste øverst.</p>
            </div>

            @if (count($recentEvents) > 0)
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-gray-200 text-gray-500">
                            <tr>
                                <th class="pb-2 pr-4 font-medium">Tidspunkt</th>
                                <th class="pb-2 pr-4 font-medium">Kunde</th>
                                <th class="pb-2 pr-4 font-medium">Bruker</th>
                                <th class="pb-2 pr-4 font-medium">Operasjon</th>
                                <th class="pb-2 pr-4 font-medium">Modell</th>
                                <th class="pb-2 pr-4 text-right font-medium">Input</th>
                                <th class="pb-2 pr-4 text-right font-medium">Output</th>
                                <th class="pb-2 text-right font-medium">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 text-gray-900">
                            @foreach ($recentEvents as $event)
                                <tr>
                                    <td class="py-1.5 pr-4 text-xs tabular-nums text-gray-500">{{ $event['created_at'] }}</td>
                                    <td class="py-1.5 pr-4 text-xs">{{ $event['customer_name'] }}</td>
                                    <td class="py-1.5 pr-4 text-xs text-gray-500">{{ $event['user_name'] }}</td>
                                    <td class="py-1.5 pr-4 font-mono text-xs text-gray-600">{{ $event['operation_key'] }}</td>
                                    <td class="py-1.5 pr-4 font-mono text-xs text-gray-600">{{ $event['model'] }}</td>
                                    <td class="py-1.5 pr-4 text-right tabular-nums text-xs">{{ number_format($event['input_tokens'], 0, ',', ' ') }}</td>
                                    <td class="py-1.5 pr-4 text-right tabular-nums text-xs">{{ number_format($event['output_tokens'], 0, ',', ' ') }}</td>
                                    <td class="py-1.5 text-right tabular-nums text-xs font-semibold">{{ number_format($event['total_tokens'], 0, ',', ' ') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="py-8 text-center text-sm text-gray-400">Ingen token-events registrert for {{ $periodLabel }}.</p>
            @endif
        </section>

    </div>
</x-filament-panels::page>
