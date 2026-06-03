<x-filament-panels::page>
    <style>
        .ai-forbruk-layout {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
            align-items: start;
        }
        @media (min-width: 1024px) {
            .ai-forbruk-layout {
                grid-template-columns: 340px minmax(0, 1fr);
            }
        }
    </style>
    <div class="ai-forbruk-layout">

        {{-- ── SIDEBAR ── --}}
        <aside class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm lg:sticky lg:top-6">

            <div class="border-b border-gray-100 px-5 py-4">
                <p class="text-xs font-bold uppercase tracking-widest text-gray-400">Procynia intern</p>
                <h2 class="mt-1 text-xl font-extrabold tracking-tight text-gray-950">AI-forbruk</h2>
                <p class="mt-1 text-sm text-gray-500">Velg kunde og periode. Alle kort og tabeller oppdateres automatisk.</p>
            </div>

            {{-- Kunde --}}
            <div class="border-b border-gray-100 px-5 py-4 space-y-3">
                <label class="block text-sm font-bold text-gray-800" for="customer-select">Kunde</label>
                <select id="customer-select" wire:model.live="selectedCustomerId"
                    class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-100">
                    <option value="">Alle kunder samlet</option>
                    @foreach ($customerList as $c)
                        <option value="{{ $c['id'] }}">{{ $c['name'] }}</option>
                    @endforeach
                </select>

                <label class="block text-sm font-bold text-gray-800" for="period-select">Periode</label>
                <select id="period-select" wire:model.live="periodPreset"
                    class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-100">
                    <option value="last30">Siste 30 dager</option>
                    <option value="month">Denne måneden</option>
                    <option value="quarter">Dette kvartalet</option>
                    <option value="year">Dette året</option>
                    <option value="custom">Egendefinert</option>
                </select>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Fra</label>
                        <input type="date" wire:model="dateFrom"
                            class="w-full rounded-xl border border-gray-300 bg-white px-2 py-1.5 text-xs text-gray-900 shadow-sm focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-100">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 mb-1">Til</label>
                        <input type="date" wire:model="dateTo"
                            class="w-full rounded-xl border border-gray-300 bg-white px-2 py-1.5 text-xs text-gray-900 shadow-sm focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-100">
                    </div>
                </div>

                <div class="grid gap-2 pt-1">
                    <button wire:click="applyFilters" type="button"
                        class="w-full rounded-xl bg-gray-900 px-4 py-2 text-sm font-bold text-white transition hover:bg-gray-700">
                        Oppdater visning
                    </button>
                    <button wire:click="resetFilters" type="button"
                        class="w-full rounded-xl bg-gray-50 border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-100">
                        Nullstill filtre
                    </button>
                </div>
            </div>

            {{-- AI-funksjon og trendgruppe --}}
            <div class="px-5 py-4 space-y-3">
                <label class="block text-sm font-bold text-gray-800" for="function-select">AI-funksjon</label>
                <select id="function-select" wire:model.live="functionFilter"
                    class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-100">
                    <option value="">Alle funksjoner</option>
                    <option value="saved_notice_requirement_answer_draft">Svarutkast</option>
                    <option value="saved_notice_documents_upload">Krav-ekstraksjon</option>
                    <option value="saved_notice_evidence_refresh">Bevisgrunnlag</option>
                    <option value="saved_notice_assessment_refresh">Vurdering</option>
                    <option value="knowledge_document_upload">Kunnskapsbase-opplasting</option>
                    <option value="knowledge_chunk_metadata_update">Chunk-metadata</option>
                    <option value="knowledge_vocabulary_analysis_batch">Standardvokabular</option>
                </select>

                <label class="block text-sm font-bold text-gray-800" for="trend-select">Trendvisning</label>
                <select id="trend-select" wire:model.live="trendGrouping"
                    class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-100">
                    <option value="day">Daglig</option>
                    <option value="week">Ukentlig</option>
                    <option value="month">Månedlig</option>
                </select>
            </div>

            {{-- Datakildemark --}}
            <div class="border-t border-amber-100 bg-amber-50 px-5 py-3">
                <p class="text-xs leading-relaxed text-amber-700">
                    <strong>Delvis datagrunnlag:</strong>
                    AI-operasjoner hentes fra <code class="font-mono">ai_usage_events</code>.
                    Tokenforbruk hentes fra <code class="font-mono">ai_token_events</code> og dekker foreløpig bare instrumenterte AI-kall.
                    Kravekstraksjon er ikke fullt samlet i tokenstatistikken ennå.
                </p>
            </div>

            {{-- Kontrollsignaler --}}
            <div class="border-t border-gray-100">
                <div class="px-5 pt-4 pb-2">
                    <div class="text-xs font-bold uppercase tracking-wider text-gray-500">Kontrollsignaler</div>
                    <p class="mt-0.5 text-xs text-gray-400">Kunder eller mønstre som bør følges opp.</p>
                </div>
                <div class="divide-y divide-gray-50 px-4 pb-3">
                    @forelse ($alerts as $alert)
                        @php
                            $alertColors = [
                                'red'   => 'bg-red-50 border-red-200 text-red-800',
                                'amber' => 'bg-amber-50 border-amber-200 text-amber-800',
                                'blue'  => 'bg-blue-50 border-blue-200 text-blue-800',
                            ];
                            $cls = $alertColors[$alert['type']] ?? $alertColors['blue'];
                        @endphp
                        <div class="my-2 rounded-xl border px-3 py-2.5 text-sm {{ $cls }}">
                            <strong class="block font-bold text-xs">{{ $alert['title'] }}</strong>
                            <span class="mt-0.5 text-xs">{{ $alert['message'] }}</span>
                        </div>
                    @empty
                        <p class="py-4 text-center text-xs text-gray-400">Ingen signaler i valgt periode.</p>
                    @endforelse
                </div>
            </div>
        </aside>

        {{-- ── MAIN ── --}}
        <section class="min-w-0 space-y-5">

            {{-- Header --}}
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-widest text-gray-400">AI-statistikk og kapasitetskontroll</p>
                    <h1 class="mt-1 text-3xl font-extrabold tracking-tight text-gray-950">{{ $pageContextTitle }}</h1>
                    <p class="mt-1 text-sm text-gray-500">
                        Viser {{ $functionFilter !== '' ? $this->operationLabel($functionFilter) : 'alle AI-funksjoner' }} i valgt periode.
                    </p>
                </div>
                <div class="inline-flex items-center gap-2 rounded-full border border-gray-200 bg-white px-4 py-2 text-sm font-bold text-gray-500 shadow-sm whitespace-nowrap">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    {{ $periodLabel }}
                </div>
            </div>

            {{-- KPI cards
                 Struktur per kort: flex-col h-36, tre soner med fast posisjon.
                 • Tittelzone: min-h-[2.5rem] — 1–2 linjer 11px uppercase, uten overflow-klipp
                 • Verdisone:  text-2xl font-extrabold — alltid på samme Y (etter tittelzone)
                 • Footersone: mt-auto text-xs — alltid i bunnen, kun farget tekst, ingen badge
            --}}
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-3 2xl:grid-cols-6">

                @php
                    $t = $kpi['trend_operations'] ?? 0;
                    $kpiCards = [
                        [
                            'label'  => 'AI-operasjoner',
                            'value'  => number_format($kpi['operations'] ?? 0, 0, ',', ' '),
                            'footer' => ($t > 0 ? '+' : '').$t.' % mot forrige periode',
                            'fClass' => $this->trendTextClass($t, false),
                        ],
                        [
                            'label'  => 'Tokenforbruk',
                            'value'  => number_format($kpi['tokens'] ?? 0, 0, ',', ' '),
                            'footer' => (($t2 = $kpi['trend_tokens'] ?? 0) > 0 ? '+' : '').$t2.' % mot forrige periode',
                            'fClass' => $this->trendTextClass($t2, false),
                        ],
                        [
                            'label'  => 'Tokens / op.',
                            'value'  => number_format($kpi['avg_tokens'] ?? 0, 0, ',', ' '),
                            'footer' => (($t3 = $kpi['trend_avg'] ?? 0) > 0 ? '+' : '').$t3.' % mot forrige periode',
                            'fClass' => $this->trendTextClass($t3, true),
                        ],
                        [
                            'label'  => 'AI-kapasitet',
                            'value'  => ($kpi['activated_cases'] ?? 0).' / '.($kpi['capacity'] ?? 0),
                            'footer' => ($kpi['capacity_pct'] ?? 0).' % brukt',
                            'fClass' => 'text-gray-400',
                        ],
                        [
                            'label'  => 'Blokkerte',
                            'value'  => number_format($kpi['blocked'] ?? 0, 0, ',', ' '),
                            'footer' => (($t5 = $kpi['trend_blocked'] ?? 0) > 0 ? '+' : '').$t5.' % mot forrige periode',
                            'fClass' => $this->trendTextClass($t5, true),
                        ],
                    ];
                @endphp

                @foreach ($kpiCards as $card)
                    <article class="flex h-36 flex-col rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                        <div class="min-h-[2.5rem] text-[11px] font-bold uppercase leading-snug tracking-wider text-gray-400">{{ $card['label'] }}</div>
                        <div class="text-2xl font-extrabold leading-none tracking-tight text-gray-950">{{ $card['value'] }}</div>
                        <div class="mt-auto text-xs font-medium {{ $card['fClass'] }}">{{ $card['footer'] }}</div>
                    </article>
                @endforeach

                {{-- Kort 6: Estimert kostnad --}}
                <article class="flex h-36 flex-col rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="min-h-[2.5rem] text-[11px] font-bold uppercase leading-snug tracking-wider text-gray-400">Est. kostnad</div>
                    @if ($totalCostStatus === 'ok' || $totalCostStatus === 'partial')
                        <div class="text-2xl font-extrabold leading-none tracking-tight text-gray-950">
                            ≈ {{ number_format($totalCostNok ?? 0, 0, ',', ' ') }} kr
                        </div>
                        <div class="mt-auto text-xs font-medium text-gray-400">
                            {{ $totalCostStatus === 'partial' ? 'Delvis dekning' : 'Intern kostnad' }}
                        </div>
                    @else
                        <div class="text-2xl font-extrabold leading-none tracking-tight text-gray-400">–</div>
                        <div class="mt-auto text-xs font-medium text-gray-400">
                            @if ($totalCostStatus === 'price_missing') Pris mangler
                            @elseif ($totalCostStatus === 'exchange_rate_missing') Valutakurs mangler
                            @else Ingen tokens
                            @endif
                        </div>
                    @endif
                </article>

            </div>

            {{-- Merknad under KPI-raden --}}
            <p class="text-[11px] text-gray-400">
                AI-kapasitet og estimert kostnad er foreløpige interne beregninger og er ikke faktureringsgrunnlag.
            </p>

            {{-- Trend: AI-operasjoner --}}
            <article class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                    <div class="border-b border-gray-100 px-5 py-4">
                        <h3 class="font-bold text-gray-900">Trend: AI-operasjoner og blokkerte forsøk</h3>
                        <p class="mt-0.5 text-sm text-gray-500">Utvikling basert på ai_usage_events i valgt periode.</p>
                    </div>
                    @if ($operationsChartPoints !== '')
                        @php
                            /* SVG layout:
                             *  viewBox: -44 -4 610 175
                             *  Chart data area: x=0..560, y=10..120 (plotH=110, padT=10)
                             *  Left margin (-44..0): Y-axis labels
                             *  Bottom area (y=122..155): X-axis labels
                             */
                            $yMax   = $operationsChartMax;
                            $ySteps = [1, 0.75, 0.5, 0.25, 0]; // top to bottom
                            $labels = $operationsChartLabels;
                            $labelCount = count($labels);
                            $xStep = $labelCount > 1 ? 560 / ($labelCount - 1) : 560;
                        @endphp
                        <div class="px-3 pt-4 pb-0">
                            <svg viewBox="-44 -4 610 168" class="w-full" aria-hidden="true">
                                {{-- Y-axis label (rotated) --}}
                                <text x="-36" y="65" transform="rotate(-90,-36,65)" text-anchor="middle"
                                      font-size="11" fill="#94a3b8" font-family="inherit" font-weight="600" letter-spacing="0.06em">
                                    ANTALL OPERASJONER
                                </text>

                                {{-- Grid lines + Y-axis tick values --}}
                                @foreach ($ySteps as $pct)
                                    @php
                                        $gy    = $pct * 110 + 10;
                                        $val   = $pct === 0 ? 0 : (int) round($yMax * (1 - $pct));
                                        $label = $val >= 1000 ? round($val / 1000, 1).'k' : $val;
                                    @endphp
                                    <line x1="0" y1="{{ $gy }}" x2="560" y2="{{ $gy }}" stroke="#f1f5f9" stroke-width="1"/>
                                    <text x="-5" y="{{ $gy + 4 }}" text-anchor="end"
                                          font-size="12" fill="#94a3b8" font-family="inherit">{{ $label }}</text>
                                @endforeach

                                {{-- Allowed line --}}
                                <polyline points="{{ $operationsChartPoints }}" fill="none" stroke="#111827" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>

                                {{-- Blocked line --}}
                                @if ($blockedChartPoints !== '')
                                    <polyline points="{{ $blockedChartPoints }}" fill="none" stroke="#2563eb" stroke-width="2" stroke-dasharray="4 3" stroke-linejoin="round" stroke-linecap="round"/>
                                @endif

                                {{-- X-axis baseline --}}
                                <line x1="0" y1="120" x2="560" y2="120" stroke="#e2e8f0" stroke-width="1"/>

                                {{-- X-axis period labels (first, ~middle, last) --}}
                                @if ($labelCount > 0)
                                    @foreach ($labels as $i => $lbl)
                                        @if ($lbl !== '')
                                            @php
                                                $lx     = round($i * $xStep, 1);
                                                $anchor = $i === 0 ? 'start' : ($i === $labelCount - 1 ? 'end' : 'middle');
                                            @endphp
                                            <text x="{{ $lx }}" y="136"
                                                  text-anchor="{{ $anchor }}"
                                                  font-size="12" fill="#94a3b8" font-family="inherit">{{ $lbl }}</text>
                                        @endif
                                    @endforeach
                                @endif
                            </svg>
                        </div>
                        <div class="flex gap-5 px-5 pb-4 text-xs font-semibold text-gray-500">
                            <span class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm bg-gray-900"></span>AI-operasjoner</span>
                            <span class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm bg-blue-600"></span>Blokkerte forsøk</span>
                        </div>
                    @else
                        <p class="px-5 py-10 text-center text-sm text-gray-400">Ingen data i valgt periode.</p>
                    @endif
            </article>

            {{-- Token chart --}}
            <article class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                <div class="border-b border-gray-100 px-5 py-4">
                    <h3 class="font-bold text-gray-900">Trend: tokenforbruk</h3>
                    <p class="mt-0.5 text-sm text-gray-500">Basert på <code class="font-mono text-xs">ai_token_events</code> — foreløpig kun svarutkast-generering.</p>
                </div>
                @if ($tokensChartPoints !== '')
                    @php
                        $tYMax       = $tokensChartMax;
                        $tYSteps     = [1, 0.75, 0.5, 0.25, 0];
                        $tLabels     = $operationsChartLabels;
                        $tLabelCount = count($tLabels);
                        $tXStep      = $tLabelCount > 1 ? 560 / ($tLabelCount - 1) : 560;
                    @endphp
                    <div class="px-3 pt-4 pb-0">
                        <svg viewBox="-44 -4 610 168" class="w-full" aria-hidden="true">
                            {{-- Y-axis label (rotated) --}}
                            <text x="-36" y="65" transform="rotate(-90,-36,65)" text-anchor="middle"
                                  font-size="11" fill="#94a3b8" font-family="inherit" font-weight="600" letter-spacing="0.06em">
                                TOKENS
                            </text>

                            {{-- Grid lines + Y-axis tick values --}}
                            @foreach ($tYSteps as $pct)
                                @php
                                    $gy  = $pct * 110 + 10;
                                    $val = $pct === 0 ? 0 : (int) round($tYMax * (1 - $pct));
                                    $lbl = $val >= 1000000
                                        ? round($val / 1000000, 1).'M'
                                        : ($val >= 1000 ? round($val / 1000, 1).'k' : $val);
                                @endphp
                                <line x1="0" y1="{{ $gy }}" x2="560" y2="{{ $gy }}" stroke="#f1f5f9" stroke-width="1"/>
                                <text x="-5" y="{{ $gy + 4 }}" text-anchor="end"
                                      font-size="12" fill="#94a3b8" font-family="inherit">{{ $lbl }}</text>
                            @endforeach

                            {{-- Token line --}}
                            <polyline points="{{ $tokensChartPoints }}" fill="none" stroke="#2563eb" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>

                            {{-- X-axis baseline --}}
                            <line x1="0" y1="120" x2="560" y2="120" stroke="#e2e8f0" stroke-width="1"/>

                            {{-- X-axis period labels --}}
                            @if ($tLabelCount > 0)
                                @foreach ($tLabels as $i => $lbl)
                                    @if ($lbl !== '')
                                        @php
                                            $lx     = round($i * $tXStep, 1);
                                            $anchor = $i === 0 ? 'start' : ($i === $tLabelCount - 1 ? 'end' : 'middle');
                                        @endphp
                                        <text x="{{ $lx }}" y="136" text-anchor="{{ $anchor }}"
                                              font-size="12" fill="#94a3b8" font-family="inherit">{{ $lbl }}</text>
                                    @endif
                                @endforeach
                            @endif
                        </svg>
                    </div>
                    <div class="px-5 pb-4 text-xs font-semibold text-gray-500">
                        <span class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm bg-blue-600"></span>Total tokens · Kun instrumenterte AI-kall</span>
                    </div>
                @else
                    <div class="px-5 py-6 text-center text-sm text-gray-400 space-y-1">
                        <p>Det finnes ingen registrerte tokenhendelser i valgt periode.</p>
                        <p class="text-xs">Tokenforbruk vises bare for instrumenterte AI-kall. AI-operasjoner kan finnes uten tilhørende tokenverdi.</p>
                    </div>
                @endif
            </article>

            {{-- Function + Customer capacity --}}
            <div class="grid gap-5 lg:grid-cols-2">

                <article class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                    <div class="border-b border-gray-100 px-5 py-4">
                        <h3 class="font-bold text-gray-900">Bruk per AI-funksjon</h3>
                        <p class="mt-0.5 text-sm text-gray-500">AI-operasjoner fra <code class="font-mono text-xs">ai_usage_events</code>. Tokens fra <code class="font-mono text-xs">ai_token_events</code> (delvis).</p>
                    </div>
                    @forelse ($functionRows as $row)
                        <div class="border-b border-gray-50 px-5 py-3">
                            <div class="flex items-center justify-between gap-4">
                                <div class="min-w-0">
                                    <div class="font-semibold text-sm text-gray-900 truncate">{{ $row['label'] }}</div>
                                </div>
                                <div class="text-right shrink-0">
                                    <div class="font-extrabold text-sm text-gray-950">{{ number_format($row['operations'], 0, ',', ' ') }}</div>
                                    <div class="text-xs text-gray-400">{{ $row['pct'] }}%</div>
                                </div>
                            </div>
                            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500">
                                @if ($row['has_token_data'] && $row['tokens'] > 0)
                                    <span>{{ number_format($row['tokens'], 0, ',', ' ') }} tokens</span>
                                @elseif (! $row['has_token_data'])
                                    <span class="italic text-gray-400">Tokenforbruk ikke registrert for denne funksjonen</span>
                                @endif
                                @if ($row['blocked'] > 0)
                                    <span class="text-red-500">{{ $row['blocked'] }} blokkert</span>
                                @endif
                                @if (($row['cost_status'] ?? '') === 'ok' && $row['cost_usd'] !== null)
                                    <span class="text-violet-600 font-semibold">≈ {{ number_format($row['cost_usd'], 2, ',', ' ') }} kr</span>
                                @elseif (in_array($row['cost_status'] ?? '', ['price_missing', 'exchange_rate_missing']))
                                    <span class="italic text-amber-500">{{ ($row['cost_status'] ?? '') === 'exchange_rate_missing' ? 'Valutakurs mangler' : 'Pris ikke registrert' }}</span>
                                @endif
                            </div>
                            <div class="mt-2 h-1.5 w-full rounded-full bg-gray-100">
                                <div class="h-full rounded-full bg-gray-900 transition-all" style="width: {{ $row['pct'] }}%"></div>
                            </div>
                        </div>
                    @empty
                        <p class="px-5 py-8 text-center text-sm text-gray-400">Ingen data i valgt periode.</p>
                    @endforelse
                </article>

                <article class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                    <div class="border-b border-gray-100 px-5 py-4">
                        <h3 class="font-bold text-gray-900">Kapasitet per kunde</h3>
                        <p class="mt-0.5 text-sm text-gray-500">Foreløpig intern visning av AI-aktiverte saker mot registrert kapasitetsverdi. Datagrunnlaget er delvis og må kvalitetssikres før dette brukes som kommersiell fasit.</p>
                    </div>
                    @forelse ($customerCapacityRows as $row)
                        <div class="border-b border-gray-50 px-5 py-3">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="font-semibold text-sm text-gray-900">{{ $row['customer_name'] }}</div>
                                    <div class="text-xs text-gray-400">{{ $row['plan'] }}</div>
                                </div>
                                <span class="inline-flex shrink-0 items-center rounded-full border px-2 py-0.5 text-xs font-bold {{ $this->capacityStatusClass($row['status']) }}">
                                    {{ $this->capacityStatusLabel($row['status']) }}
                                </span>
                            </div>
                            @if ($row['limit_defined'])
                                <div class="mt-2 flex items-center gap-3 text-sm">
                                    <span class="font-bold text-gray-950">{{ $row['activated'] }}</span>
                                    <span class="text-gray-400">av</span>
                                    <span class="font-semibold text-gray-700">{{ $row['limit'] }}</span>
                                    <span class="text-gray-400 text-xs">AI-saker</span>
                                    <span class="ml-auto text-xs text-gray-500">{{ $row['pct'] }}%</span>
                                </div>
                                <p class="mt-1 text-xs text-gray-400">Foreløpig beregning — ikke endelig kommersiell fasit.</p>
                                <div class="mt-1.5 h-1.5 w-full rounded-full bg-gray-100">
                                    @php
                                        $fillCls = match($row['status']) {
                                            'over' => 'bg-red-500',
                                            'warning' => 'bg-amber-400',
                                            default => 'bg-emerald-500',
                                        };
                                    @endphp
                                    <div class="h-full rounded-full transition-all {{ $fillCls }}" style="width: {{ min(100, $row['pct']) }}%"></div>
                                </div>
                            @else
                                <div class="mt-2 text-sm text-gray-500">
                                    <span class="font-semibold text-gray-900">{{ $row['activated'] }}</span> AI-saker registrert ·
                                    <span class="text-xs text-gray-400">Ingen grense registrert</span>
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="px-5 py-8 text-center text-sm text-gray-400">Ingen kunder å vise.</p>
                    @endforelse
                </article>
            </div>

            {{-- User table --}}
            <article class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                <div class="border-b border-gray-100 px-5 py-4">
                    <h3 class="font-bold text-gray-900">Brukere i valgt periode</h3>
                    <p class="mt-0.5 text-sm text-gray-500">Analysegrunnlag for hvem som bruker AI og hvor tung bruken er. Maks 50 brukere.</p>
                </div>
                @if (count($userRows) > 0)
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 text-xs font-bold uppercase tracking-wider text-gray-500">
                                <tr>
                                    <th class="px-4 py-3 text-left">Bruker</th>
                                    <th class="px-4 py-3 text-left">Kunde</th>
                                    <th class="px-4 py-3 text-left">Rolle</th>
                                    <th class="px-4 py-3 text-right">Operasjoner</th>
                                    <th class="px-4 py-3 text-right">Tokens</th>
                                    <th class="px-4 py-3 text-right">Tok / op</th>
                                    <th class="px-4 py-3 text-right">Blokkert</th>
                                    <th class="px-4 py-3 text-right text-violet-600">Est. kostnad</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                @foreach ($userRows as $row)
                                    <tr class="hover:bg-gray-50/50">
                                        <td class="px-4 py-3 font-medium text-gray-900">{{ $row['user_name'] }}</td>
                                        <td class="px-4 py-3 text-gray-600">{{ $row['customer_name'] }}</td>
                                        <td class="px-4 py-3 text-gray-500 text-xs">{{ $row['role'] }}</td>
                                        <td class="px-4 py-3 text-right tabular-nums font-semibold">{{ number_format($row['operations'], 0, ',', ' ') }}</td>
                                        @if ($row['has_token_data'])
                                            <td class="px-4 py-3 text-right tabular-nums text-gray-700">{{ number_format($row['tokens'], 0, ',', ' ') }}</td>
                                            <td class="px-4 py-3 text-right tabular-nums text-gray-500">{{ number_format($row['avg_tokens'], 0, ',', ' ') }}</td>
                                        @else
                                            <td class="px-4 py-3 text-right text-xs text-gray-400 italic">Ikke registrert</td>
                                            <td class="px-4 py-3 text-right text-xs text-gray-400 italic">Ikke registrert</td>
                                        @endif
                                        <td class="px-4 py-3 text-right tabular-nums {{ $row['blocked'] > 0 ? 'font-semibold text-red-600' : 'text-gray-400' }}">{{ $row['blocked'] }}</td>
                                        @if (($row['cost_status'] ?? '') === 'ok' && $row['cost_usd'] !== null)
                                            <td class="px-4 py-3 text-right tabular-nums text-xs font-semibold text-violet-700">≈ {{ number_format($row['cost_usd'], 2, ',', ' ') }} kr</td>
                                        @elseif (in_array($row['cost_status'] ?? '', ['price_missing', 'exchange_rate_missing']))
                                            <td class="px-4 py-3 text-right text-xs italic text-amber-500">{{ ($row['cost_status'] ?? '') === 'exchange_rate_missing' ? 'Valutakurs mangler' : 'Pris ikke registrert' }}</td>
                                        @else
                                            <td class="px-4 py-3 text-right text-xs text-gray-300">—</td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="px-5 py-8 text-center text-sm text-gray-400">Ingen brukere med AI-aktivitet i valgt periode.</p>
                @endif
            </article>

            {{-- Trend table --}}
            <article class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                <div class="border-b border-gray-100 px-5 py-4">
                    <h3 class="font-bold text-gray-900">Trendtabell</h3>
                    <p class="mt-0.5 text-sm text-gray-500">Gruppert etter valgt trendvisning (dag / uke / måned).</p>
                </div>
                @if (count($trendRows) > 0)
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 text-xs font-bold uppercase tracking-wider text-gray-500">
                                <tr>
                                    <th class="px-4 py-3 text-left">Periode</th>
                                    <th class="px-4 py-3 text-right">AI-operasjoner</th>
                                    <th class="px-4 py-3 text-right">Tokens</th>
                                    <th class="px-4 py-3 text-right">Tok / op</th>
                                    <th class="px-4 py-3 text-right">Blokkert</th>
                                    <th class="px-4 py-3 text-right text-violet-600">Est. kostnad</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                @foreach ($trendRows as $row)
                                    <tr class="hover:bg-gray-50/50">
                                        <td class="px-4 py-3 font-mono text-xs text-gray-700">{{ $row['period'] }}</td>
                                        <td class="px-4 py-3 text-right tabular-nums font-semibold">{{ number_format($row['operations'], 0, ',', ' ') }}</td>
                                        <td class="px-4 py-3 text-right tabular-nums text-gray-700">{{ number_format($row['tokens'], 0, ',', ' ') }}</td>
                                        <td class="px-4 py-3 text-right tabular-nums text-gray-500">{{ number_format($row['avg_tokens'], 0, ',', ' ') }}</td>
                                        <td class="px-4 py-3 text-right tabular-nums {{ $row['blocked'] > 0 ? 'font-semibold text-red-600' : 'text-gray-400' }}">{{ $row['blocked'] }}</td>
                                        @if (($row['cost_status'] ?? '') === 'ok' && $row['cost_usd'] !== null)
                                            <td class="px-4 py-3 text-right tabular-nums text-xs font-semibold text-violet-700">≈ {{ number_format($row['cost_usd'], 2, ',', ' ') }} kr</td>
                                        @elseif (in_array($row['cost_status'] ?? '', ['price_missing', 'exchange_rate_missing']))
                                            <td class="px-4 py-3 text-right text-xs italic text-amber-500">{{ ($row['cost_status'] ?? '') === 'exchange_rate_missing' ? 'Valutakurs mangler' : 'Pris ikke registrert' }}</td>
                                        @else
                                            <td class="px-4 py-3 text-right text-xs text-gray-300">—</td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="px-5 py-8 text-center text-sm text-gray-400">Ingen data i valgt periode.</p>
                @endif
            </article>

        </section>
    </div>
</x-filament-panels::page>
