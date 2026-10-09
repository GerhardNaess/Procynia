@php
    $t = fn (string $key, array $replace = []) => __('procynia.ai_admin.experience.'.$key, $replace);
    $num = fn ($value, int $decimals = 0) => $value === null ? $t('none') : number_format((float) $value, $decimals, ',', ' ');
    $nok = fn ($value) => $value === null ? $t('none') : number_format((float) $value, 2, ',', ' ');
    $pct = fn ($value) => $value === null ? $t('none') : number_format((float) $value, 1, ',', ' ').' %';
    $spread = fn (array $stats, int $decimals = 0) => $stats['n'] === 0 ? $t('none') : $num($stats['median'], $decimals).' · '.$num($stats['p75'], $decimals).' · '.$num($stats['p95'], $decimals);
    $featureLabels = (array) __('procynia.ai_admin.experience.features.labels');
    $tierNames = (array) __('procynia.ai_admin.experience.tier_names');
    $feature = fn (string $key) => $featureLabels[$key] ?? $key;
    $package = fn (string $key) => __('procynia.billing.modules.package_labels.'.$key) === 'procynia.billing.modules.package_labels.'.$key ? $key : __('procynia.billing.modules.package_labels.'.$key);
    $packages = fn (array $keys) => $keys === [] ? $t('mixes.none') : implode(', ', array_map($package, $keys));
    $tierName = fn (?string $key) => $key === null || $key === 'none' ? $t('tiers.none') : ($tierNames[$key] ?? $key);
    $date = fn ($value) => $value?->format('d.m.Y');
    // A billing period is half-open; its last whole day is the day before its end.
    $period = fn (array $row) => $date($row['period_start']).'–'.$date($row['period_end']->subDay());
    $basisLine = fn (array $basis) => $t('basis.line', ['customers' => $basis['customers'], 'periods' => $basis['periods'], 'calls' => $num($basis['calls'])]);
@endphp

<x-filament-panels::page>
    <style>
        .exp-page { font-size: 1rem; line-height: 1.5rem; }
        .exp-card { border: 1px solid rgb(229 231 235); border-radius: 1rem; background: white; padding: 1.25rem; }
        .exp-table { width: 100%; border-collapse: collapse; }
        .exp-table th { text-align: left; font-weight: 700; color: rgb(55 65 81); padding: .5rem .75rem; border-bottom: 1px solid rgb(229 231 235); vertical-align: bottom; }
        .exp-table td { padding: .5rem .75rem; border-bottom: 1px solid rgb(243 244 246); vertical-align: top; color: rgb(17 24 39); }
        .exp-table td.num, .exp-table th.num { text-align: right; white-space: nowrap; }
        .exp-scroll { max-width: 100%; overflow-x: auto; }
        @media (min-width: 768px) { .exp-table td:first-child { min-width: 12rem; } }
        .exp-grid { display: grid; gap: .75rem; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); }
        @media (max-width: 767px) {
            .exp-table thead { display: none; }
            .exp-table, .exp-table tbody, .exp-table tr, .exp-table td { display: block; width: 100%; }
            .exp-table tr { border-bottom: 1px solid rgb(229 231 235); padding: .5rem 0; }
            .exp-table td { border: 0; padding: .25rem 0; display: flex; justify-content: space-between; gap: 1rem; text-align: right; }
            .exp-table td.num { white-space: normal; }
            .exp-table td::before { content: attr(data-label); font-weight: 600; color: rgb(75 85 99); text-align: left; }
        }
    </style>

    <div class="exp-page space-y-6" data-testid="experience-page">
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-amber-900" data-testid="experience-read-only">
            <p class="font-semibold">{{ $t('read_only') }}</p>
            <p class="mt-1">{{ $t('source_note') }}</p>
        </div>

        {{-- Filters --}}
        <section class="exp-card" aria-labelledby="exp-filters">
            <h2 id="exp-filters" class="text-lg font-bold text-gray-950">{{ $t('filters.heading') }}</h2>
            <div class="exp-grid mt-3">
                <label class="block">
                    <span class="font-semibold text-gray-800">{{ $t('filters.customer') }}</span>
                    <select wire:model.live="customerId" data-testid="experience-filter-customer" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-base">
                        <option value="">{{ $t('filters.all_customers') }}</option>
                        @foreach ($customerOptions as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="font-semibold text-gray-800">{{ $t('filters.from') }}</span>
                    <input type="date" wire:model.live="dateFrom" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-base">
                </label>
                <label class="block">
                    <span class="font-semibold text-gray-800">{{ $t('filters.to') }}</span>
                    <input type="date" wire:model.live="dateTo" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-base">
                </label>
                <label class="block">
                    <span class="font-semibold text-gray-800">{{ $t('filters.feature') }}</span>
                    <select wire:model.live="feature" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-base">
                        <option value="">{{ $t('filters.all_features') }}</option>
                        @foreach ($featureOptions as $key)
                            <option value="{{ $key }}">{{ $feature($key) }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="font-semibold text-gray-800">{{ $t('filters.operation') }}</span>
                    <select wire:model.live="operation" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-base" aria-describedby="exp-operation-help">
                        <option value="">{{ $t('filters.all_operations') }}</option>
                        @foreach ($operationOptions as $key)
                            <option value="{{ $key }}">{{ $key }}</option>
                        @endforeach
                    </select>
                    <span id="exp-operation-help" class="mt-1 block text-gray-600">{{ $t('filters.operation_help') }}</span>
                </label>
                <label class="block">
                    <span class="font-semibold text-gray-800">{{ $t('filters.tier') }}</span>
                    <select wire:model.live="tier" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-base">
                        <option value="">{{ $t('filters.all_tiers') }}</option>
                        @foreach ($tierOptions as $key)
                            <option value="{{ $key }}">{{ $tierName($key) }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="font-semibold text-gray-800">{{ $t('filters.package') }}</span>
                    <select wire:model.live="package" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-base">
                        <option value="">{{ $t('filters.all_packages') }}</option>
                        @foreach ($packageOptions as $key)
                            <option value="{{ $key }}">{{ $package($key) }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="grid grid-cols-2 gap-2">
                    <label class="block">
                        <span class="font-semibold text-gray-800">{{ $t('filters.users_min') }}</span>
                        <input type="number" min="0" inputmode="numeric" wire:model.live.debounce.500ms="usersMin" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-base">
                    </label>
                    <label class="block">
                        <span class="font-semibold text-gray-800">{{ $t('filters.users_max') }}</span>
                        <input type="number" min="0" inputmode="numeric" wire:model.live.debounce.500ms="usersMax" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-base">
                    </label>
                </div>
                <label class="block">
                    <span class="font-semibold text-gray-800">{{ $t('filters.interval') }}</span>
                    <select wire:model.live="interval" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-base">
                        <option value="">{{ $t('filters.all_intervals') }}</option>
                        <option value="month">{{ $t('filters.intervals.month') }}</option>
                        <option value="year">{{ $t('filters.intervals.year') }}</option>
                    </select>
                </label>
            </div>
            <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                <label class="flex items-center gap-2">
                    <input type="checkbox" wire:model.live="includeIncomplete" data-testid="experience-include-incomplete" class="h-5 w-5 rounded border-gray-300">
                    <span>{{ $t('filters.include_incomplete') }}</span>
                </label>
                <button type="button" wire:click="resetFilters" class="rounded-lg border border-gray-300 bg-white px-4 py-2 font-semibold text-gray-800 hover:bg-gray-50">{{ $t('filters.reset') }}</button>
            </div>
        </section>

        {{-- Data basis --}}
        @php($basis = $report['basis'])
        <div class="rounded-xl border px-4 py-3 {{ $basis['sufficient'] ? 'border-gray-200 bg-white' : 'border-amber-300 bg-amber-50' }}" data-testid="experience-basis">
            <p class="font-semibold text-gray-950">{{ $basisLine($basis) }}</p>
            @if (! $hasTrustedData)
                <p class="mt-1 text-amber-900">{{ $t('basis.no_trusted') }}</p>
            @elseif ($basis['periods'] === 0)
                <p class="mt-1 text-amber-900">{{ $t('basis.empty') }}</p>
            @elseif (! $basis['sufficient'])
                <p class="mt-1 font-semibold text-amber-900" data-testid="experience-insufficient">{{ $t('basis.insufficient') }}</p>
            @endif
            @if ($report['excluded_incomplete'] > 0)
                <p class="mt-1 text-gray-700">{{ $t('basis.excluded', ['count' => $report['excluded_incomplete']]) }}</p>
            @endif
        </div>

        {{-- Overview --}}
        @php($overview = $report['overview'])
        <section aria-labelledby="exp-overview" data-testid="experience-overview">
            <h2 id="exp-overview" class="text-lg font-bold text-gray-950">{{ $t('overview.heading') }}</h2>
            <dl class="exp-grid mt-3">
                @foreach ([
                    ['overview.customers', $num($basis['customers'])],
                    ['overview.periods', $num($basis['periods'])],
                    ['overview.total_cost', $nok($overview['settled_cost_nok']).' NOK'],
                    ['overview.median_cost', $nok($overview['cost']['median'])],
                    ['overview.p75_cost', $nok($overview['cost']['p75'])],
                    ['overview.p95_cost', $nok($overview['cost']['p95'])],
                    ['overview.median_units', $num($overview['units']['median'])],
                    ['overview.median_utilization', $pct($overview['utilization']['median'])],
                ] as [$label, $value])
                    <div class="exp-card !p-4">
                        <dt class="text-gray-700">{{ $t($label) }}</dt>
                        <dd class="mt-1 text-xl font-bold text-gray-950">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
            @if ($overview['open_calls'] > 0)
                <p class="mt-2 text-gray-700">{{ $t('overview.open_cost', ['calls' => $overview['open_calls'], 'nok' => $nok($overview['pending_reserved_cost_nok'] + $overview['unresolved_reserved_cost_nok'])]) }}</p>
            @endif
        </section>

        @if ($detail === false)
            <div class="exp-card">
                <p>{{ $t('detail.not_found') }}</p>
                <button type="button" wire:click="closePeriod" class="mt-2 font-semibold text-primary-700 underline">{{ $t('detail.back') }}</button>
            </div>
        @elseif ($detail !== null)
            {{-- Customer/period detail --}}
            <section class="exp-card space-y-6" data-testid="experience-detail" aria-labelledby="exp-detail">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 id="exp-detail" class="text-2xl font-bold text-gray-950">{{ $detail['customer'] }}</h2>
                        <p class="text-gray-700">{{ $period($detail) }}@if ($detail['status'] !== 'final') · {{ $t('customers.status_open') }}@endif @if ($detail['coverage'] !== 'full') · {{ $t('customers.status_partial') }}@endif</p>
                    </div>
                    <button type="button" wire:click="closePeriod" data-testid="experience-detail-back" class="rounded-lg border border-gray-300 bg-white px-4 py-2 font-semibold text-gray-800 hover:bg-gray-50">{{ $t('detail.back') }}</button>
                </div>

                @if ($detail['context_after_end'])
                    <p class="rounded-lg bg-amber-50 px-3 py-2 text-amber-900">{{ $t('detail.context_after_end') }}</p>
                @endif

                <div class="grid gap-6 lg:grid-cols-3">
                    <div data-testid="experience-detail-setup">
                        <h3 class="font-bold text-gray-950">{{ $t('detail.setup') }}</h3>
                        <dl class="mt-2 space-y-1">
                            <div class="flex justify-between gap-3"><dt class="text-gray-700">{{ $t('customers.users') }}</dt><dd class="font-semibold">{{ $detail['users'] }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-gray-700">{{ $t('customers.modules') }}</dt><dd class="text-right font-semibold">{{ $packages($detail['packages']) }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-gray-700">{{ $t('customers.tier') }}</dt><dd class="font-semibold">{{ $tierName($detail['tier_key']) }}@if ($detail['tier_multiplier'] !== null) (×{{ $num($detail['tier_multiplier'], 2) }})@endif</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-gray-700">{{ $t('detail.base_capacity') }}</dt><dd class="font-semibold">{{ $num($detail['calculated_base_capacity']) }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-gray-700">{{ $t('detail.total_capacity') }}</dt><dd class="font-semibold">{{ $detail['included_units'] === null ? $t('unmetered') : $num($detail['included_units']) }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-gray-700">{{ $t('detail.source') }}</dt><dd class="font-semibold">{{ $t('sources.'.$detail['capacity_source']) }}</dd></div>
                            @if ($detail['override_units'] !== null)
                                <div class="flex justify-between gap-3"><dt class="text-gray-700">{{ $t('detail.override') }}</dt><dd class="font-semibold">{{ $num($detail['override_units']) }}</dd></div>
                            @endif
                        </dl>
                    </div>
                    <div data-testid="experience-detail-usage">
                        <h3 class="font-bold text-gray-950">{{ $t('detail.usage') }}</h3>
                        <dl class="mt-2 space-y-1">
                            <div class="flex justify-between gap-3"><dt class="text-gray-700">{{ $t('customers.cost') }}</dt><dd class="font-semibold">{{ $nok($detail['settled_cost_nok']) }} NOK</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-gray-700">{{ $t('customers.used') }}</dt><dd class="font-semibold">{{ $num($detail['settled_units']) }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-gray-700">{{ $t('detail.reserved_units') }}</dt><dd class="font-semibold">{{ $num($detail['reserved_units']) }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-gray-700">{{ $t('customers.calls') }}</dt><dd class="font-semibold">{{ $num($detail['calls']) }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-gray-700">{{ $t('detail.successful') }} / {{ $t('detail.failed') }}</dt><dd class="font-semibold">{{ $detail['successful_calls'] }} / {{ $detail['failed_calls'] }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-gray-700">{{ $t('customers.open') }}</dt><dd class="font-semibold">{{ $detail['pending_calls'] }} / {{ $detail['unresolved_calls'] }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-gray-700">{{ $t('detail.tokens') }}</dt><dd class="font-semibold">{{ $num($detail['total_tokens']) }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-gray-700">{{ $t('customers.utilization') }}</dt><dd class="font-semibold">{{ $pct($detail['used_percent']) }}</dd></div>
                        </dl>
                    </div>
                    <div data-testid="experience-detail-capacity">
                        <h3 class="font-bold text-gray-950">{{ $t('detail.capacity') }}</h3>
                        <dl class="mt-2 space-y-1">
                            <div class="flex justify-between gap-3"><dt class="text-gray-700">{{ $t('detail.warn') }}</dt><dd class="font-semibold">{{ $detail['verdict_warn'] }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-gray-700">{{ $t('detail.exhausted') }}</dt><dd class="font-semibold">{{ $detail['verdict_exhausted'] }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-gray-700">{{ $t('detail.insufficient') }}</dt><dd class="font-semibold">{{ $detail['verdict_insufficient'] }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-gray-700">{{ $t('detail.would_have_blocked') }}</dt><dd class="font-semibold">{{ $detail['would_have_blocked'] }}</dd></div>
                        </dl>
                    </div>
                </div>

                <div>
                    <h3 class="font-bold text-gray-950">{{ $t('detail.features') }}</h3>
                    <div class="exp-scroll"><table class="exp-table mt-2" data-testid="experience-detail-features">
                        <thead><tr><th>{{ $t('columns.feature') }}</th><th class="num">{{ $t('columns.calls') }}</th><th class="num">{{ $t('columns.cost') }}</th><th class="num">{{ $t('columns.units') }}</th><th class="num">{{ $t('columns.reserved') }}</th><th class="num">{{ $t('columns.share') }}</th></tr></thead>
                        <tbody>
                            @forelse ($detail['features'] as $row)
                                <tr>
                                    <td data-label="{{ $t('columns.feature') }}">{{ $feature($row['key']) }}</td>
                                    <td class="num" data-label="{{ $t('columns.calls') }}">{{ $num($row['calls']) }}</td>
                                    <td class="num" data-label="{{ $t('columns.cost') }}">{{ $nok($row['settled_cost_nok']) }}</td>
                                    <td class="num" data-label="{{ $t('columns.units') }}">{{ $num($row['settled_units']) }}</td>
                                    <td class="num" data-label="{{ $t('columns.reserved') }}">{{ $num($row['reserved_units']) }}</td>
                                    <td class="num" data-label="{{ $t('columns.share') }}">{{ $pct($row['share']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6">{{ $t('customers.empty') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table></div>
                </div>

                <div>
                    <h3 class="font-bold text-gray-950">{{ $t('detail.operations') }}</h3>
                    @include('filament.pages.partials.ai-erfaring-operations', ['rows' => $detail['operations'], 'testid' => 'experience-detail-operations'])
                </div>

                @if ($detail['revisions'] !== [])
                    <div>
                        <h3 class="font-bold text-gray-950">{{ $t('detail.revisions') }}</h3>
                        <ul class="mt-2 space-y-1">
                            @foreach ($detail['revisions'] as $revision)
                                <li>{{ $t('detail.revision_line', ['revision' => $revision['revision'], 'date' => \Carbon\CarbonImmutable::parse($revision['created_at'])->format('d.m.Y'), 'fields' => implode(', ', array_keys($revision['changes']))]) }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </section>
        @endif

        {{-- Customers and periods --}}
        <section class="exp-card" aria-labelledby="exp-customers" data-testid="experience-customers">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <h2 id="exp-customers" class="text-lg font-bold text-gray-950">{{ $t('customers.heading') }}</h2>
                <label class="flex items-center gap-2">
                    <span class="font-semibold text-gray-800">{{ $t('customers.sort') }}</span>
                    <select wire:model.live="sort" class="rounded-lg border border-gray-300 px-3 py-2 text-base">
                        @foreach (\App\Services\Ai\Experience\AiExperienceAnalysisService::SORTS as $key)
                            <option value="{{ $key }}">{{ $t('customers.sorts.'.$key) }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            <div class="exp-scroll"><table class="exp-table mt-3">
                <thead>
                    <tr>
                        <th>{{ $t('customers.customer') }} · {{ $t('customers.period') }}</th>
                        <th>{{ $t('customers.users') }} · {{ $t('customers.modules') }}</th>
                        <th>{{ $t('customers.tier') }} · {{ $t('customers.capacity') }}</th>
                        <th class="num">{{ $t('customers.used') }} · {{ $t('customers.utilization') }}</th>
                        <th class="num">{{ $t('customers.cost') }}</th>
                        <th class="num">{{ $t('customers.calls') }}</th>
                        <th class="num">{{ $t('customers.open') }}</th>
                        <th class="num">{{ $t('customers.blocked') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['rows'] as $row)
                        <tr data-testid="experience-row">
                            <td data-label="{{ $t('customers.customer') }}">
                                <button type="button" wire:click="openPeriod({{ $row['id'] }})" class="text-left font-semibold text-primary-700 underline">{{ $row['customer'] }}</button>
                                <span class="block text-gray-700">{{ $period($row) }}@if ($row['status'] !== 'final') · {{ $t('customers.status_open') }}@endif @if ($row['coverage'] !== 'full') · {{ $t('customers.status_partial') }}@endif</span>
                            </td>
                            <td data-label="{{ $t('customers.users') }}">{{ $row['users'] }}<span class="block text-gray-700">{{ $packages($row['packages']) }}</span></td>
                            <td data-label="{{ $t('customers.tier') }}">{{ $tierName($row['tier_key']) }}<span class="block text-gray-700">{{ $row['included_units'] === null ? $t('unmetered') : $num($row['included_units']) }}</span></td>
                            <td class="num" data-label="{{ $t('customers.used') }}">{{ $num($row['settled_units']) }}<span class="block text-gray-700">{{ $pct($row['used_percent']) }}</span></td>
                            <td class="num" data-label="{{ $t('customers.cost') }}">{{ $nok($row['settled_cost_nok']) }}</td>
                            <td class="num" data-label="{{ $t('customers.calls') }}">{{ $num($row['calls']) }}</td>
                            <td class="num" data-label="{{ $t('customers.open') }}">{{ $row['pending_calls'] + $row['unresolved_calls'] }}</td>
                            <td class="num" data-label="{{ $t('customers.blocked') }}">{{ $row['would_have_blocked'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8">{{ $t('customers.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table></div>
        </section>

        {{-- Current model against observed data --}}
        <section class="exp-card" aria-labelledby="exp-formula" data-testid="experience-formula">
            <h2 id="exp-formula" class="text-lg font-bold text-gray-950">{{ $t('formula.heading') }}</h2>
            <p class="mt-1 text-gray-700">{{ $t('formula.help') }}</p>
            <div class="exp-scroll"><table class="exp-table mt-3">
                <thead>
                    <tr>
                        <th>{{ $t('formula.factor') }}</th>
                        <th class="num">{{ $t('formula.current') }}</th>
                        <th>{{ $t('formula.observed') }} ({{ $t('columns.median') }} · {{ $t('columns.p75') }} · {{ $t('columns.p95') }})</th>
                        <th>{{ $t('formula.signals') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report['formula']['factors'] as $factor)
                        @php($isTier = $factor['factor'] === 'tier')
                        <tr data-testid="experience-factor-{{ $factor['key'] }}">
                            <td data-label="{{ $t('formula.factor') }}">
                                {{ match ($factor['factor']) {
                                    'option' => $t('formula.factors.option', ['module' => $package($factor['key'])]),
                                    'tier' => $t('formula.factors.tier', ['tier' => $tierName($factor['key'])]),
                                    default => $t('formula.factors.'.$factor['factor']),
                                } }}
                                <span class="block text-gray-700">{{ $t('formula.observed_measures.'.$factor['factor']) }}</span>
                            </td>
                            <td class="num" data-label="{{ $t('formula.current') }}">
                                @if ($factor['current'] === null)
                                    {{ $t('formula.per_base') }}
                                @elseif ($isTier)
                                    ×{{ $num($factor['current'], 2) }}
                                @else
                                    {{ $num($factor['current']) }}
                                @endif
                            </td>
                            <td data-label="{{ $t('formula.observed') }}">
                                {{ $isTier ? $spread($factor['observed'], 1).' %' : $spread($factor['observed']) }}
                                <span class="block text-gray-700">{{ $basisLine($factor['basis']) }}@if ($factor['share_above'] !== null) · {{ $t($isTier ? 'formula.share_tight' : 'formula.share_above', ['percent' => $num($factor['share_above'] * 100)]) }}@endif</span>
                            </td>
                            <td data-label="{{ $t('formula.signals') }}">
                                @forelse ($factor['signals'] as $signal)
                                    <span class="mb-1 mr-1 inline-block rounded-full bg-gray-100 px-2 py-0.5 text-gray-900">{{ $t('signals.'.$signal) }}</span>
                                @empty
                                    {{ $t('none') }}
                                @endforelse
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            @php($estimates = $report['formula']['estimate_signals'])
            <div class="mt-3">
                <h3 class="font-bold text-gray-950">{{ $t('formula.estimates') }}</h3>
                @if ($estimates['too_low'] === [] && $estimates['too_high'] === [])
                    <p class="text-gray-700">{{ $t('formula.estimates_ok') }}</p>
                @endif
                @if ($estimates['too_low'] !== [])
                    <p>{{ $t('formula.estimates_low', ['operations' => implode(', ', $estimates['too_low'])]) }}</p>
                @endif
                @if ($estimates['too_high'] !== [])
                    <p>{{ $t('formula.estimates_high', ['operations' => implode(', ', $estimates['too_high'])]) }}</p>
                @endif
            </div>
        </section>

        {{-- Users --}}
        @php($perUser = $report['per_user'])
        <section class="exp-card" aria-labelledby="exp-users" data-testid="experience-users">
            <h2 id="exp-users" class="text-lg font-bold text-gray-950">{{ $t('per_user.heading') }}</h2>
            <p class="mt-1 text-gray-700">{{ $t('per_user.help') }} {{ $basisLine($perUser['basis']) }}</p>
            <div class="exp-scroll"><table class="exp-table mt-3">
                <thead><tr><th></th><th class="num">{{ $t('columns.mean') }}</th><th class="num">{{ $t('columns.median') }}</th><th class="num">{{ $t('columns.p75') }}</th><th class="num">{{ $t('columns.p95') }}</th></tr></thead>
                <tbody>
                    @foreach ([['per_user.cost', $perUser['cost'], 2], ['per_user.units', $perUser['units'], 1], ['per_user.users', $perUser['users'], 1]] as [$label, $stats, $decimals])
                        <tr>
                            <td>{{ $t($label) }}</td>
                            <td class="num" data-label="{{ $t('columns.mean') }}">{{ $num($stats['mean'], $decimals) }}</td>
                            <td class="num" data-label="{{ $t('columns.median') }}">{{ $num($stats['median'], $decimals) }}</td>
                            <td class="num" data-label="{{ $t('columns.p75') }}">{{ $num($stats['p75'], $decimals) }}</td>
                            <td class="num" data-label="{{ $t('columns.p95') }}">{{ $num($stats['p95'], $decimals) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        </section>

        {{-- Modules --}}
        <section class="exp-card" aria-labelledby="exp-modules" data-testid="experience-modules">
            <h2 id="exp-modules" class="text-lg font-bold text-gray-950">{{ $t('modules.heading') }}</h2>
            <p class="mt-1 text-gray-700">{{ $t('modules.help') }}</p>
            <div class="exp-scroll"><table class="exp-table mt-3">
                <thead><tr><th>{{ $t('modules.module') }}</th><th class="num">{{ $t('columns.customers') }}</th><th class="num">{{ $t('modules.with') }}</th><th class="num">{{ $t('modules.without') }}</th><th class="num">{{ $t('modules.direct') }}</th><th class="num">{{ $t('modules.cost_with') }}</th></tr></thead>
                <tbody>
                    @foreach ($report['modules'] as $module)
                        <tr>
                            <td data-label="{{ $t('modules.module') }}">
                                <span class="font-semibold">{{ $package($module['package']) }}</span>
                                <span class="block text-gray-700">
                                    @if ($module['median_difference_units'] === null)
                                        {{ $t('modules.difference_none', ['module' => $package($module['package'])]) }}
                                    @else
                                        {{ $t($module['median_difference_units'] >= 0 ? 'modules.difference_higher' : 'modules.difference_lower', ['module' => $package($module['package']), 'units' => $num(abs($module['median_difference_units']))]) }}
                                    @endif
                                </span>
                            </td>
                            <td class="num" data-label="{{ $t('columns.customers') }}">{{ $module['customers_with'] }}</td>
                            <td class="num" data-label="{{ $t('modules.with') }}">{{ $num($module['with_units']['median']) }}</td>
                            <td class="num" data-label="{{ $t('modules.without') }}">{{ $num($module['without_units']['median']) }}</td>
                            <td class="num" data-label="{{ $t('modules.direct') }}">{{ $spread($module['direct_units']) }}</td>
                            <td class="num" data-label="{{ $t('modules.cost_with') }}">{{ $module['with_cost']['n'] === 0 ? $t('none') : $nok($module['with_cost']['median']).' · '.$nok($module['with_cost']['p75']).' · '.$nok($module['with_cost']['p95']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        </section>

        {{-- Module mix --}}
        <section class="exp-card" aria-labelledby="exp-mixes" data-testid="experience-mixes">
            <h2 id="exp-mixes" class="text-lg font-bold text-gray-950">{{ $t('mixes.heading') }}</h2>
            <div class="exp-scroll"><table class="exp-table mt-3">
                <thead><tr><th>{{ $t('mixes.mix') }}</th><th class="num">{{ $t('columns.customers') }} · {{ $t('columns.periods') }}</th><th class="num">{{ $t('mixes.median_users') }}</th><th class="num">{{ $t('mixes.units') }}</th><th class="num">{{ $t('mixes.cost') }}</th></tr></thead>
                <tbody>
                    @forelse ($report['module_mixes'] as $mix)
                        <tr>
                            <td data-label="{{ $t('mixes.mix') }}">{{ $packages($mix['packages']) }}</td>
                            <td class="num" data-label="{{ $t('columns.customers') }}">{{ $mix['basis']['customers'] }} · {{ $mix['basis']['periods'] }}</td>
                            <td class="num" data-label="{{ $t('mixes.median_users') }}">{{ $num($mix['users']['median'], 1) }}</td>
                            <td class="num" data-label="{{ $t('mixes.units') }}">{{ $spread($mix['units']) }}</td>
                            <td class="num" data-label="{{ $t('mixes.cost') }}">{{ $mix['cost']['n'] === 0 ? $t('none') : $nok($mix['cost']['median']).' · '.$nok($mix['cost']['p75']).' · '.$nok($mix['cost']['p95']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">{{ $t('customers.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table></div>
        </section>

        {{-- Tiers --}}
        <section class="exp-card" aria-labelledby="exp-tiers" data-testid="experience-tiers">
            <h2 id="exp-tiers" class="text-lg font-bold text-gray-950">{{ $t('tiers.heading') }}</h2>
            <div class="exp-scroll"><table class="exp-table mt-3">
                <thead><tr><th>{{ $t('tiers.tier') }}</th><th class="num">{{ $t('columns.customers') }} · {{ $t('columns.periods') }}</th><th class="num">{{ $t('tiers.units') }}</th><th class="num">{{ $t('tiers.utilization') }}</th><th class="num">{{ $t('tiers.blocked') }}</th><th class="num">{{ $t('tiers.tight') }}</th></tr></thead>
                <tbody>
                    @forelse ($report['tiers'] as $tierRow)
                        <tr>
                            <td data-label="{{ $t('tiers.tier') }}">{{ $tierName($tierRow['tier_key']) }}</td>
                            <td class="num" data-label="{{ $t('columns.customers') }}">{{ $tierRow['basis']['customers'] }} · {{ $tierRow['basis']['periods'] }}</td>
                            <td class="num" data-label="{{ $t('tiers.units') }}">{{ $num($tierRow['units']['median']) }}</td>
                            <td class="num" data-label="{{ $t('tiers.utilization') }}">{{ $tierRow['utilization']['n'] === 0 ? $t('none') : $spread($tierRow['utilization'], 1).' %' }}</td>
                            <td class="num" data-label="{{ $t('tiers.blocked') }}">{{ $tierRow['would_have_blocked'] }} · {{ $tierRow['periods_blocked'] }}</td>
                            <td class="num" data-label="{{ $t('tiers.tight') }}">{{ $tierRow['periods_tight'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6">{{ $t('customers.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table></div>
        </section>

        {{-- Features --}}
        <section class="exp-card" aria-labelledby="exp-features" data-testid="experience-features">
            <h2 id="exp-features" class="text-lg font-bold text-gray-950">{{ $t('features.heading') }}</h2>
            <div class="exp-scroll"><table class="exp-table mt-3">
                <thead><tr><th>{{ $t('columns.feature') }}</th><th class="num">{{ $t('columns.customers') }} · {{ $t('columns.periods') }}</th><th class="num">{{ $t('columns.calls') }}</th><th class="num">{{ $t('columns.cost') }}</th><th class="num">{{ $t('columns.share') }}</th><th class="num">{{ $t('features.monthly_cost') }}</th></tr></thead>
                <tbody>
                    @forelse ($report['features'] as $row)
                        <tr>
                            <td data-label="{{ $t('columns.feature') }}">{{ $feature($row['key']) }}</td>
                            <td class="num" data-label="{{ $t('columns.customers') }}">{{ $row['customers'] }} · {{ $row['periods'] }}</td>
                            <td class="num" data-label="{{ $t('columns.calls') }}">{{ $num($row['calls']) }}</td>
                            <td class="num" data-label="{{ $t('columns.cost') }}">{{ $nok($row['settled_cost_nok']) }}</td>
                            <td class="num" data-label="{{ $t('columns.share') }}">{{ $pct($row['share']) }}</td>
                            <td class="num" data-label="{{ $t('features.monthly_cost') }}">{{ $nok($row['monthly_cost']['median']) }} · {{ $nok($row['monthly_cost']['p75']) }} · {{ $nok($row['monthly_cost']['p95']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6">{{ $t('customers.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table></div>
        </section>

        {{-- Operations --}}
        <section class="exp-card" aria-labelledby="exp-operations" data-testid="experience-operations">
            <h2 id="exp-operations" class="text-lg font-bold text-gray-950">{{ $t('operations.heading') }}</h2>
            <p class="mt-1 text-gray-700">{{ $t('operations.help') }}</p>
            @include('filament.pages.partials.ai-erfaring-operations', ['rows' => $operations, 'testid' => 'experience-operations-table'])
        </section>

        {{-- Trend --}}
        <section class="exp-card" aria-labelledby="exp-trend" data-testid="experience-trend">
            <h2 id="exp-trend" class="text-lg font-bold text-gray-950">{{ $t('trend.heading') }}</h2>
            <p class="mt-1 text-gray-700">{{ $t('trend.help') }}</p>
            <div class="exp-scroll"><table class="exp-table mt-3">
                <thead><tr><th>{{ $t('columns.month') }}</th><th class="num">{{ $t('trend.total_cost') }}</th><th class="num">{{ $t('trend.units') }}</th><th class="num">{{ $t('trend.customers') }}</th><th class="num">{{ $t('trend.median_cost') }}</th><th class="num">{{ $t('trend.p95_cost') }}</th></tr></thead>
                <tbody>
                    @forelse ($trend as $month)
                        <tr>
                            <td data-label="{{ $t('columns.month') }}">{{ $month['month'] }}</td>
                            <td class="num" data-label="{{ $t('trend.total_cost') }}">{{ $nok($month['cost_nok']) }}</td>
                            <td class="num" data-label="{{ $t('trend.units') }}">{{ $num($month['units']) }}</td>
                            <td class="num" data-label="{{ $t('trend.customers') }}">{{ $month['customers'] }}</td>
                            <td class="num" data-label="{{ $t('trend.median_cost') }}">{{ $nok($month['median_cost_nok']) }}</td>
                            <td class="num" data-label="{{ $t('trend.p95_cost') }}">{{ $nok($month['p95_cost_nok']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6">{{ $t('trend.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table></div>
        </section>
    </div>
</x-filament-panels::page>
