<x-filament-panels::page>
    @php
        $phase = $this->phasePayload();
        $activityItems = $this->activityItems();
        $totalItems = (int) ($runStatus['total_items'] ?? 0);
        $processedItems = (int) ($runStatus['processed_items'] ?? 0);
        $harvestedSuppliers = (int) ($runStatus['harvested_suppliers'] ?? 0);
        $failedItems = (int) ($runStatus['failed_items'] ?? 0);
        $progressPercent = max(0, min(100, (float) ($runStatus['progress_percent'] ?? 0)));
        $showPreparedProgress = $totalItems > 0;
        $showAnimatedBar = $this->shouldPoll();
        $statusModifier = match ($phase['badge']) {
            'Completed' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            'Failed' => 'bg-rose-50 text-rose-700 ring-rose-200',
            'Running' => 'bg-blue-50 text-blue-700 ring-blue-200',
            default => 'bg-slate-100 text-slate-700 ring-slate-200',
        };
        $progressSummary = $showPreparedProgress
            ? number_format($processedItems) . ' / ' . number_format($totalItems) . ' notices'
            : 'Notice list is being prepared';
    @endphp

    <style>
        .fi-header {
            margin-bottom: 0.5rem;
        }

        .fi-header.fi-header-has-breadcrumbs {
            row-gap: 0.25rem;
        }

        .fi-breadcrumbs {
            font-size: 0.6875rem;
        }

        .fi-header-heading {
            font-size: 1.8rem;
            line-height: 1.05;
        }

        @media (min-width: 64rem) {
            .fi-header-heading {
                font-size: 2rem;
            }
        }
    </style>

    <div class="mx-auto max-w-[70rem] space-y-2.5" @if ($this->shouldPoll()) wire:poll.3s="refreshRunStatus" @endif>
        <div class="flex justify-end">
            <a
                href="{{ \App\Filament\Pages\DoffinSupplierHarvest::getUrl() }}"
                class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-2.5 py-1.5 text-[11px] font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
            >
                Start another run
            </a>
        </div>

        @if ($lastError)
            <div class="rounded-2xl border border-danger-200 bg-danger-50 px-4 py-3 text-sm text-danger-700">
                {{ $lastError }}
            </div>
        @endif

        <section class="overflow-hidden rounded-[1.15rem] border border-slate-200 bg-white shadow-[0_10px_24px_rgba(15,23,42,0.07)]">
            <div class="flex flex-col gap-1 border-b border-slate-200 bg-slate-50/80 px-3.5 py-2 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex flex-wrap items-center gap-2.5 text-xs text-slate-500">
                    <span class="font-semibold text-slate-900">Harvest Run</span>
                    <span class="h-1 w-1 rounded-full bg-slate-300"></span>
                    <code class="rounded-lg bg-white px-2 py-0.5 text-[11px] text-slate-600 ring-1 ring-inset ring-slate-200">
                        {{ $runStatus['run_uuid'] ?? '' }}
                    </code>
                </div>

                <div class="text-xs text-slate-500">
                    Last update: {{ $this->relativeTimestamp($runStatus['last_heartbeat_at'] ?? $runStatus['finished_at'] ?? $runStatus['created_at'] ?? null) }}
                </div>
            </div>

            <div class="space-y-2.5 p-3.5">
                <section class="rounded-[16px] border border-slate-200 bg-gradient-to-b from-white to-slate-50 p-3.5">
                    <div class="grid gap-3 lg:grid-cols-[minmax(0,1.35fr)_minmax(16rem,0.85fr)] lg:items-end">
                        <div class="space-y-2">
                            <div class="inline-flex items-center gap-2 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.12em] ring-1 ring-inset {{ $statusModifier }}">
                                <span class="inline-flex h-2 w-2 rounded-full bg-current @if ($showAnimatedBar) animate-pulse @endif"></span>
                                {{ $phase['badge'] }}
                            </div>

                            <div class="space-y-1.5">
                                <h2 class="text-[1.55rem] font-semibold tracking-tight text-slate-950 sm:text-[1.75rem]">{{ $phase['title'] }}</h2>
                                <p class="text-[13px] font-semibold text-slate-900">
                                    {{ $runStatus['source_from_date'] ?? '-' }} to {{ $runStatus['source_to_date'] ?? '-' }}
                                </p>
                                <p class="max-w-2xl text-[13px] leading-5 text-slate-600">
                                    {{ $phase['subtitle'] }}
                                </p>
                            </div>
                        </div>

                        <div class="space-y-1.5">
                            <div class="flex items-end justify-between gap-3">
                                <div class="text-[15px] font-semibold tracking-tight text-slate-950 sm:text-base">
                                    {{ $progressSummary }}
                                </div>

                                <div class="text-[1.05rem] font-semibold tracking-tight text-blue-700 sm:text-[1.25rem]">
                                    {{ number_format($progressPercent, 1) }}%
                                </div>
                            </div>

                            <div class="h-1.5 overflow-hidden rounded-full bg-slate-200">
                                @if ($showPreparedProgress)
                                    <div
                                        class="h-full rounded-full bg-blue-600 transition-all duration-700 @if ($showAnimatedBar) animate-pulse @endif"
                                        style="width: {{ $progressPercent }}%;"
                                    ></div>
                                @else
                                    <div class="h-full w-1/3 rounded-full bg-blue-600 animate-pulse"></div>
                                @endif
                            </div>
                        </div>
                    </div>
                </section>

                <section class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                    <div class="rounded-[15px] border border-slate-200 bg-white p-3 shadow-sm">
                        <div class="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Notices processed</div>
                        <div class="mt-1 text-[1.1rem] font-semibold tracking-tight text-slate-950">
                            {{ number_format($processedItems) }}
                            <span class="text-xs font-medium text-slate-400">/ {{ number_format($totalItems) }}</span>
                        </div>
                    </div>

                    <div class="rounded-[15px] border border-slate-200 bg-white p-3 shadow-sm">
                        <div class="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Suppliers harvested</div>
                        <div class="mt-1 text-[1.1rem] font-semibold tracking-tight text-slate-950">{{ number_format($harvestedSuppliers) }}</div>
                    </div>

                    <div class="rounded-[15px] border border-slate-200 bg-white p-3 shadow-sm">
                        <div class="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Failed</div>
                        <div class="mt-1 text-[1.1rem] font-semibold tracking-tight text-slate-950">{{ number_format($failedItems) }}</div>
                    </div>

                    <div class="rounded-[15px] border border-slate-200 bg-white p-3 shadow-sm">
                        <div class="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">ETA</div>
                        <div class="mt-1 text-[1.1rem] font-semibold tracking-tight text-slate-950">
                            {{ $this->etaLabel($runStatus['estimated_seconds_remaining'] ?? null) }}
                        </div>
                    </div>

                    <div class="rounded-[15px] border border-slate-200 bg-white p-3 shadow-sm">
                        <div class="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Started at</div>
                        <div class="mt-1 text-[13px] font-semibold tracking-tight text-slate-950">
                            {{ $this->formatTimestamp($runStatus['started_at'] ?? null) }}
                        </div>
                    </div>

                    <div class="rounded-[15px] border border-slate-200 bg-white p-3 shadow-sm">
                        <div class="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Finished at</div>
                        <div class="mt-1 text-[13px] font-semibold tracking-tight text-slate-950">
                            {{ $this->formatTimestamp($runStatus['finished_at'] ?? null) }}
                        </div>
                    </div>
                </section>
            </div>
        </section>

        <section class="overflow-hidden rounded-[1.15rem] border border-slate-200 bg-white shadow-[0_10px_24px_rgba(15,23,42,0.07)]">
            <div class="border-b border-slate-200 px-3.5 py-2">
                <h3 class="text-base font-semibold tracking-tight text-slate-950">Run activity</h3>
            </div>

            <ol class="space-y-2.5 px-3.5 py-3.5">
                @foreach ($activityItems as $item)
                    <li class="grid grid-cols-[14px_minmax(0,1fr)] gap-2.5">
                        <span class="mt-1.5 inline-flex h-2.5 w-2.5 rounded-full bg-slate-400 shadow-[0_0_0_4px_rgba(248,250,252,1)]" aria-hidden="true"></span>

                        <div class="space-y-0.5">
                            <div class="text-[13px] font-semibold leading-5 text-slate-950">{{ $item['message'] }}</div>
                            <div class="text-[11px] text-slate-500">{{ $item['time'] }}</div>
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>
    </div>
</x-filament-panels::page>
