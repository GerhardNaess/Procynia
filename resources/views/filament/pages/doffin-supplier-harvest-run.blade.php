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
            'Completed' => 'bg-emerald-50 dark:bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 ring-emerald-200 dark:ring-emerald-500/30',
            'Failed' => 'bg-rose-50 dark:bg-rose-500/10 text-rose-700 dark:text-rose-400 ring-rose-200 dark:ring-rose-500/30',
            'Running' => 'bg-blue-50 dark:bg-blue-500/10 text-blue-700 dark:text-blue-400 ring-blue-200 dark:ring-blue-500/30',
            default => 'bg-slate-100 dark:bg-white/10 text-slate-700 dark:text-gray-200 ring-slate-200 dark:ring-white/10',
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
            font-size: 1rem;
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
                class="inline-flex items-center justify-center rounded-xl border border-slate-200 dark:border-white/10 bg-white dark:bg-gray-900 px-3 py-2 text-base font-semibold text-slate-700 dark:text-gray-200 shadow-sm transition hover:bg-slate-50 dark:hover:bg-white/5"
            >
                Start another run
            </a>
        </div>

        @if ($lastError)
            <div class="rounded-2xl border border-danger-200 dark:border-danger-500/30 bg-danger-50 dark:bg-danger-500/10 px-4 py-3 text-base leading-6 text-danger-700 dark:text-danger-400">
                {{ $lastError }}
            </div>
        @endif

        <section class="overflow-hidden rounded-[1.15rem] border border-slate-200 dark:border-white/10 bg-white dark:bg-gray-900 shadow-[0_10px_24px_rgba(15,23,42,0.07)]">
            <div class="flex flex-col gap-1 border-b border-slate-200 dark:border-white/10 bg-slate-50/80 dark:bg-white/5 px-3.5 py-2 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex flex-wrap items-center gap-2.5 text-base text-slate-600 dark:text-gray-400">
                    <span class="font-semibold text-slate-900 dark:text-white">Harvest Run</span>
                    <span class="h-1 w-1 rounded-full bg-slate-300 dark:bg-gray-600" aria-hidden="true"></span>
                    <code class="rounded-lg bg-white dark:bg-gray-900 px-2 py-0.5 text-base text-slate-600 dark:text-gray-400 ring-1 ring-inset ring-slate-200 dark:ring-white/10">
                        {{ $runStatus['run_uuid'] ?? '' }}
                    </code>
                </div>

                <div class="text-base text-slate-600 dark:text-gray-400">
                    Last update: {{ $this->relativeTimestamp($runStatus['last_heartbeat_at'] ?? $runStatus['finished_at'] ?? $runStatus['created_at'] ?? null) }}
                </div>
            </div>

            <div class="space-y-2.5 p-3.5">
                <section class="rounded-[16px] border border-slate-200 dark:border-white/10 bg-gradient-to-b from-white dark:from-gray-900 to-slate-50 dark:to-gray-900 p-3.5">
                    <div class="grid gap-3 lg:grid-cols-[minmax(0,1.35fr)_minmax(16rem,0.85fr)] lg:items-end">
                        <div class="space-y-2">
                            {{-- The badge is a status region: a phase change found by the 3 s poll (queued → running → completed)
                                 is announced once; polls that leave the phase unchanged say nothing. --}}
                            <div role="status" data-harvest-run-phase class="inline-flex items-center gap-2 rounded-full px-2 py-1 text-base font-semibold uppercase tracking-[0.12em] leading-6 ring-1 ring-inset {{ $statusModifier }}">
                                <span class="inline-flex h-2 w-2 rounded-full bg-current @if ($showAnimatedBar) animate-pulse motion-reduce:animate-none @endif" aria-hidden="true"></span>
                                {{ $phase['badge'] }}
                            </div>

                            <div class="space-y-1.5">
                                <h2 class="text-[1.55rem] font-semibold tracking-tight text-slate-950 dark:text-white sm:text-[1.75rem]">{{ $phase['title'] }}</h2>
                                <p class="text-base font-semibold text-slate-900 dark:text-white">
                                    {{ $runStatus['source_from_date'] ?? '-' }} to {{ $runStatus['source_to_date'] ?? '-' }}
                                </p>
                                <p class="max-w-2xl text-base leading-6 text-slate-600 dark:text-gray-400">
                                    {{ $phase['subtitle'] }}
                                </p>
                            </div>
                        </div>

                        <div class="space-y-1.5">
                            <div class="flex items-end justify-between gap-3">
                                <div class="text-base font-semibold tracking-tight text-slate-950 dark:text-white">
                                    {{ $progressSummary }}
                                </div>

                                <div class="text-[1.05rem] font-semibold tracking-tight text-blue-700 dark:text-blue-400 sm:text-[1.25rem]">
                                    {{ number_format($progressPercent, 1) }}%
                                </div>
                            </div>

                            <div
                                class="h-1.5 overflow-hidden rounded-full bg-slate-200 dark:bg-white/10"
                                role="progressbar"
                                aria-label="{{ __('procynia.system_status.fields.progress') }}"
                                aria-valuemin="0"
                                aria-valuemax="100"
                                @if ($showPreparedProgress) aria-valuenow="{{ (int) round($progressPercent) }}" @endif
                                aria-valuetext="{{ $progressSummary }}"
                                data-harvest-run-progress
                            >
                                @if ($showPreparedProgress)
                                    <div
                                        class="h-full rounded-full bg-blue-600 transition-all duration-700 @if ($showAnimatedBar) animate-pulse motion-reduce:animate-none @endif"
                                        style="width: {{ $progressPercent }}%;"
                                    ></div>
                                @else
                                    <div class="h-full w-1/3 rounded-full bg-blue-600 animate-pulse motion-reduce:animate-none"></div>
                                @endif
                            </div>
                        </div>
                    </div>
                </section>

                <section class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                    <div class="rounded-[15px] border border-slate-200 dark:border-white/10 bg-white dark:bg-gray-900 p-3 shadow-sm">
                        <div class="text-base font-semibold uppercase tracking-[0.12em] text-slate-600 dark:text-gray-400">Notices processed</div>
                        <div class="mt-1 text-[1.1rem] font-semibold tracking-tight text-slate-950 dark:text-white">
                            {{ number_format($processedItems) }}
                            <span class="text-base font-medium text-slate-600 dark:text-gray-400">/ {{ number_format($totalItems) }}</span>
                        </div>
                    </div>

                    <div class="rounded-[15px] border border-slate-200 dark:border-white/10 bg-white dark:bg-gray-900 p-3 shadow-sm">
                        <div class="text-base font-semibold uppercase tracking-[0.12em] text-slate-600 dark:text-gray-400">Suppliers harvested</div>
                        <div class="mt-1 text-[1.1rem] font-semibold tracking-tight text-slate-950 dark:text-white">{{ number_format($harvestedSuppliers) }}</div>
                    </div>

                    <div class="rounded-[15px] border border-slate-200 dark:border-white/10 bg-white dark:bg-gray-900 p-3 shadow-sm">
                        <div class="text-base font-semibold uppercase tracking-[0.12em] text-slate-600 dark:text-gray-400">Failed</div>
                        <div class="mt-1 text-[1.1rem] font-semibold tracking-tight text-slate-950 dark:text-white">{{ number_format($failedItems) }}</div>
                    </div>

                    <div class="rounded-[15px] border border-slate-200 dark:border-white/10 bg-white dark:bg-gray-900 p-3 shadow-sm">
                        <div class="text-base font-semibold uppercase tracking-[0.12em] text-slate-600 dark:text-gray-400">ETA</div>
                        <div class="mt-1 text-[1.1rem] font-semibold tracking-tight text-slate-950 dark:text-white">
                            {{ $this->etaLabel($runStatus['estimated_seconds_remaining'] ?? null) }}
                        </div>
                    </div>

                    <div class="rounded-[15px] border border-slate-200 dark:border-white/10 bg-white dark:bg-gray-900 p-3 shadow-sm">
                        <div class="text-base font-semibold uppercase tracking-[0.12em] text-slate-600 dark:text-gray-400">Started at</div>
                        <div class="mt-1 text-base font-semibold tracking-tight text-slate-950 dark:text-white">
                            {{ $this->formatTimestamp($runStatus['started_at'] ?? null) }}
                        </div>
                    </div>

                    <div class="rounded-[15px] border border-slate-200 dark:border-white/10 bg-white dark:bg-gray-900 p-3 shadow-sm">
                        <div class="text-base font-semibold uppercase tracking-[0.12em] text-slate-600 dark:text-gray-400">Finished at</div>
                        <div class="mt-1 text-base font-semibold tracking-tight text-slate-950 dark:text-white">
                            {{ $this->formatTimestamp($runStatus['finished_at'] ?? null) }}
                        </div>
                    </div>
                </section>
            </div>
        </section>

        <section class="overflow-hidden rounded-[1.15rem] border border-slate-200 dark:border-white/10 bg-white dark:bg-gray-900 shadow-[0_10px_24px_rgba(15,23,42,0.07)]">
            <div class="border-b border-slate-200 dark:border-white/10 px-3.5 py-2">
                <h2 class="text-base font-semibold tracking-tight text-slate-950 dark:text-white">Run activity</h2>
            </div>

            <ol class="space-y-2.5 px-3.5 py-3.5">
                @foreach ($activityItems as $item)
                    <li class="grid grid-cols-[14px_minmax(0,1fr)] gap-2.5">
                        <span class="mt-1.5 inline-flex h-2.5 w-2.5 rounded-full bg-slate-400 shadow-[0_0_0_4px_rgba(248,250,252,1)] dark:shadow-none" aria-hidden="true"></span>

                        <div class="space-y-0.5">
                            <div class="text-base font-semibold leading-6 text-slate-950 dark:text-white">{{ $item['message'] }}</div>
                            <div class="text-base text-slate-600 dark:text-gray-400">{{ $item['time'] }}</div>
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>
    </div>
</x-filament-panels::page>
