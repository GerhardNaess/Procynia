<x-filament-panels::page>
    <div class="space-y-8">
        @php
            $statusLabel = $supplierLookupStatus['status_label'] ?? 'Unknown';
            $statusClasses = match ($supplierLookupStatus['status'] ?? null) {
                'completed' => 'bg-success-50 text-success-700 ring-1 ring-inset ring-success-200',
                'failed' => 'bg-danger-50 text-danger-700 ring-1 ring-inset ring-danger-200',
                'running', 'preparing', 'queued' => 'bg-primary-50 text-primary-700 ring-1 ring-inset ring-primary-200',
                default => 'bg-gray-100 text-gray-700 ring-1 ring-inset ring-gray-200',
            };
        @endphp

        <div class="grid gap-6 xl:grid-cols-[minmax(0,420px)_minmax(0,1fr)]">
            <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="space-y-1">
                    <div class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Supplier lookup</div>
                    <h2 class="text-lg font-semibold text-gray-900">Search suppliers in Doffin</h2>
                    <p class="text-sm text-gray-600">
                        Enter a supplier name, start the lookup, and follow the background run progress here.
                    </p>
                </div>

                <div class="mt-6 space-y-5">
                    {{ $this->form }}

                    <div class="space-y-2">
                        <x-filament::button
                            type="button"
                            wire:click="runSupplierLookup"
                            :disabled="$this->hasActiveSupplierLookupRun()"
                        >
                            Lookup supplier in Doffin
                        </x-filament::button>

                        <p class="text-xs leading-5 text-gray-500">
                            The lookup runs in the background and the status card updates automatically.
                        </p>
                    </div>
                </div>
            </section>

            <section
                @if ($this->hasActiveSupplierLookupRun()) wire:poll.3s="refreshSupplierLookupStatus" @endif
                class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm"
            >
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="space-y-1">
                        <div class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Supplier lookup status</div>
                        <h2 class="text-lg font-semibold text-gray-900">Background run status</h2>
                        @if (! $supplierLookupStatus)
                            <p class="text-sm text-gray-600">
                                Start a supplier lookup to see progress and results here.
                            </p>
                        @elseif ($this->hasActiveSupplierLookupRun())
                            <p class="text-sm text-gray-600">
                                The lookup is running in the background. Very short runs may complete before the next automatic refresh.
                            </p>
                        @elseif (($supplierLookupStatus['is_terminal'] ?? false) && ($supplierLookupStatus['processed_items'] ?? 0) > 0)
                            <p class="text-sm text-gray-600">
                                This lookup finished in the background. The values below are the persisted results from the completed run.
                            </p>
                        @else
                            <p class="text-sm text-gray-600">
                                The status card shows the latest persisted run data for this supplier lookup.
                            </p>
                        @endif
                    </div>

                    @if ($supplierLookupStatus)
                        <div class="inline-flex items-center rounded-full px-3 py-1 text-sm font-medium {{ $statusClasses }}">
                            {{ $statusLabel }}
                        </div>
                    @endif
                </div>

                @if (! $supplierLookupStatus)
                    <div class="mt-6 rounded-xl border border-dashed border-gray-300 bg-gray-50 px-5 py-6">
                        <div class="text-sm font-medium text-gray-900">No supplier lookup started yet</div>
                        <p class="mt-1 text-sm text-gray-600">
                            Use the form to start a lookup. Progress, timestamps, and result counts will appear here as soon as the run is created.
                        </p>
                    </div>
                @else
                    <div class="mt-6 space-y-6">
                        <div class="space-y-2">
                            <p class="text-sm text-gray-600">
                                Supplier query:
                                <span class="font-medium text-gray-900">{{ $supplierLookupStatus['supplier_query'] ?? 'n/a' }}</span>
                            </p>
                            @if (!empty($supplierLookupStatus['resolved_winner_label']))
                                <p class="text-sm text-gray-600">
                                    Resolved winner:
                                    <span class="font-medium text-gray-900">{{ $supplierLookupStatus['resolved_winner_label'] }}</span>
                                </p>
                            @endif
                        </div>

                        <div>
                            <div class="flex items-center justify-between text-sm text-gray-600">
                                <span>Processed {{ $supplierLookupStatus['processed_items'] ?? 0 }} of {{ $supplierLookupStatus['total_items'] ?? 0 }}</span>
                                <span>{{ number_format((float) ($supplierLookupStatus['progress_percent'] ?? 0), 2) }}%</span>
                            </div>

                            <div class="mt-2 h-2.5 overflow-hidden rounded-full bg-gray-100">
                                <div
                                    class="h-full rounded-full bg-primary-600 transition-all"
                                    style="width: {{ max(0, min(100, (float) ($supplierLookupStatus['progress_percent'] ?? 0))) }}%;"
                                ></div>
                            </div>
                        </div>

                        <dl class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <div class="rounded-xl bg-gray-50 px-4 py-3">
                                <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Status</dt>
                                <dd class="mt-1 text-sm font-medium text-gray-900">{{ $statusLabel }}</dd>
                            </div>
                            <div class="rounded-xl bg-gray-50 px-4 py-3">
                                <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Processed / Total</dt>
                                <dd class="mt-1 text-sm font-medium text-gray-900">{{ $supplierLookupStatus['processed_items'] ?? 0 }} / {{ $supplierLookupStatus['total_items'] ?? 0 }}</dd>
                            </div>
                            <div class="rounded-xl bg-gray-50 px-4 py-3">
                                <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Matches found</dt>
                                <dd class="mt-1 text-sm font-medium text-gray-900">{{ $supplierLookupStatus['matched_items'] ?? 0 }}</dd>
                            </div>
                            <div class="rounded-xl bg-gray-50 px-4 py-3">
                                <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Failed</dt>
                                <dd class="mt-1 text-sm font-medium text-gray-900">{{ $supplierLookupStatus['failed_items'] ?? 0 }}</dd>
                            </div>
                            <div class="rounded-xl bg-gray-50 px-4 py-3">
                                <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">ETA</dt>
                                <dd class="mt-1 text-sm font-medium text-gray-900">{{ $this->etaLabel($supplierLookupStatus['estimated_seconds_remaining'] ?? null) }}</dd>
                            </div>
                            <div class="rounded-xl bg-gray-50 px-4 py-3">
                                <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Started at</dt>
                                <dd class="mt-1 text-sm font-medium text-gray-900">{{ $supplierLookupStatus['started_at'] ? \Illuminate\Support\Carbon::parse($supplierLookupStatus['started_at'])->format('Y-m-d H:i:s') : 'Not started' }}</dd>
                            </div>
                            <div class="rounded-xl bg-gray-50 px-4 py-3">
                                <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Finished at</dt>
                                <dd class="mt-1 text-sm font-medium text-gray-900">{{ $supplierLookupStatus['finished_at'] ? \Illuminate\Support\Carbon::parse($supplierLookupStatus['finished_at'])->format('Y-m-d H:i:s') : 'In progress' }}</dd>
                            </div>
                            <div class="rounded-xl bg-gray-50 px-4 py-3">
                                <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Last heartbeat</dt>
                                <dd class="mt-1 text-sm font-medium text-gray-900">{{ $supplierLookupStatus['last_heartbeat_at'] ? \Illuminate\Support\Carbon::parse($supplierLookupStatus['last_heartbeat_at'])->format('Y-m-d H:i:s') : 'Waiting' }}</dd>
                            </div>
                        </dl>

                        @if (!empty($supplierLookupStatus['error_message']))
                            <div class="rounded-xl border border-danger-200 bg-danger-50 px-4 py-3 text-sm text-danger-700">
                                {{ $supplierLookupStatus['error_message'] }}
                            </div>
                        @endif
                    </div>
                @endif
            </section>
        </div>

        @if ($lastError)
            <div class="rounded-xl border border-danger-200 bg-danger-50 px-4 py-3 text-sm text-danger-700">
                {{ $lastError }}
            </div>
        @endif

        @if ($resultSummary)
            @php
                $harvest = $resultSummary['harvest'] ?? [];
                $persistence = $resultSummary['persistence'] ?? [];
            @endphp

            <div class="grid gap-4 md:grid-cols-3">
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Windows processed</div>
                    <div class="mt-2 text-2xl font-semibold text-gray-900">{{ $harvest['windows_processed'] ?? 0 }}</div>
                    <div class="mt-1 text-sm text-gray-500">Split windows: {{ $harvest['windows_split'] ?? 0 }}</div>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Notices seen</div>
                    <div class="mt-2 text-2xl font-semibold text-gray-900">{{ $harvest['notices_seen'] ?? 0 }}</div>
                    <div class="mt-1 text-sm text-gray-500">Records built: {{ $harvest['records_built'] ?? 0 }}</div>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Persistence</div>
                    <div class="mt-2 text-2xl font-semibold text-gray-900">{{ $persistence['notices_persisted'] ?? 0 }}</div>
                    <div class="mt-1 text-sm text-gray-500">Suppliers touched: {{ $persistence['suppliers_touched'] ?? 0 }}</div>
                </div>
            </div>

            <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
                <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                    <h3 class="text-sm font-semibold text-gray-900">Run details</h3>

                    <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Mode</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ $resultSummary['mode'] ?? 'unknown' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Import run</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ $resultSummary['run_id'] ?? 'n/a' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Notice rows created</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ $persistence['notices_created'] ?? 0 }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Notice rows updated</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ $persistence['notices_updated'] ?? 0 }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Suppliers created</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ $persistence['suppliers_created'] ?? 0 }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Suppliers updated</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ $persistence['suppliers_updated'] ?? 0 }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Links created</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ $persistence['links_created'] ?? 0 }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Links updated</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ $persistence['links_updated'] ?? 0 }}</dd>
                        </div>
                    </dl>
                </div>

                <div class="space-y-4">
                    <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                        <h3 class="text-sm font-semibold text-gray-900">Next admin steps</h3>

                        <div class="mt-3 flex flex-wrap gap-2">
                            <x-filament::link :href="\App\Filament\Resources\DoffinNoticeResource::getUrl('index')">
                                Open notices
                            </x-filament::link>
                            <x-filament::link :href="\App\Filament\Resources\DoffinSupplierResource::getUrl('index')">
                                Open suppliers
                            </x-filament::link>
                            <x-filament::link :href="\App\Filament\Resources\DoffinImportRunResource::getUrl('index')">
                                Open run logs
                            </x-filament::link>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
