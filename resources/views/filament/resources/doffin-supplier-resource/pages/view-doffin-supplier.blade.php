<x-filament-panels::page>
    @php
        /** @var \App\Models\DoffinSupplier $supplier */
        $supplier = $this->getRecord();
        $noticeRows = $this->noticeRows();
    @endphp

    <div class="mx-auto max-w-6xl space-y-4">
        <section class="overflow-hidden rounded-2xl border border-slate-200 dark:border-white/10 bg-white dark:bg-gray-900 shadow-sm">
            <div class="border-b border-slate-200 dark:border-white/10 px-5 py-3">
                <h2 class="text-lg font-semibold tracking-tight text-slate-950 dark:text-white">Supplier</h2>
            </div>

            <div class="grid gap-x-6 gap-y-4 px-5 py-4 md:grid-cols-2">
                <div class="space-y-1">
                    <div class="text-base font-semibold uppercase tracking-[0.12em] text-slate-600 dark:text-gray-400">Supplier name</div>
                    <div class="text-base font-semibold leading-6 text-slate-950 dark:text-white">
                        {{ $supplier->supplier_name }}
                    </div>
                </div>

                <div class="space-y-1">
                    <div class="text-base font-semibold uppercase tracking-[0.12em] text-slate-600 dark:text-gray-400">Organization number</div>
                    <div class="text-base font-medium text-slate-900 dark:text-white">
                        {{ $supplier->organization_number ?: 'Unknown' }}
                    </div>
                </div>

                <div class="space-y-1">
                    <div class="text-base font-semibold uppercase tracking-[0.12em] text-slate-600 dark:text-gray-400">Normalized name</div>
                    <div class="text-base font-medium text-slate-900 dark:text-white break-all">
                        {{ $supplier->normalized_name }}
                    </div>
                </div>

                <div class="space-y-1">
                    <div class="text-base font-semibold uppercase tracking-[0.12em] text-slate-600 dark:text-gray-400">Updated at</div>
                    <div class="text-base font-medium text-slate-900 dark:text-white">
                        {{ $supplier->updated_at?->format('Y-m-d H:i:s') ?? '-' }}
                    </div>
                </div>
            </div>
        </section>

        <section class="overflow-hidden rounded-2xl border border-slate-200 dark:border-white/10 bg-white dark:bg-gray-900 shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-200 dark:border-white/10 px-5 py-3">
                <h2 id="doffin-supplier-linked-notices" class="text-lg font-semibold tracking-tight text-slate-950 dark:text-white">Linked notices</h2>
                <span class="text-base leading-6 text-slate-600 dark:text-gray-400">{{ number_format(count($noticeRows)) }}</span>
            </div>

            <div class="relative max-h-[30rem] overflow-y-auto" @include('filament.components.scroll-region-attrs', ['labelledby' => 'doffin-supplier-linked-notices'])>
                @if (count($noticeRows) === 0)
                    <div class="px-5 py-6 text-base leading-6 text-slate-600 dark:text-gray-400">
                        No linked notices are available for this supplier.
                    </div>
                @else
                    <div class="divide-y divide-slate-200 dark:divide-white/10">
                        @foreach ($noticeRows as $notice)
                            <article class="px-5 py-3">
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div class="min-w-0 space-y-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            @if ($notice['notice_record_id'])
                                                <a
                                                    href="{{ \App\Filament\Resources\DoffinNoticeResource::getUrl('view', ['record' => $notice['notice_record_id']]) }}"
                                                    class="text-base font-semibold text-primary-600 dark:text-primary-400 hover:text-primary-800 hover:underline dark:hover:text-primary-300"
                                                >
                                                    {{ $notice['notice_id'] ?: 'Unknown notice' }}
                                                </a>
                                            @else
                                                <div class="text-base font-semibold text-slate-900 dark:text-white">
                                                    {{ $notice['notice_id'] ?: 'Unknown notice' }}
                                                </div>
                                            @endif

                                            @if ($notice['source'])
                                                <span class="inline-flex items-center rounded-full bg-slate-100 dark:bg-white/10 px-2 py-1 text-base font-medium leading-6 text-slate-700 dark:text-gray-200 ring-1 ring-inset ring-slate-200 dark:ring-white/10">
                                                    {{ $notice['source'] }}
                                                </span>
                                            @endif
                                        </div>

                                        <div class="text-base font-medium leading-6 text-slate-950 dark:text-white">
                                            {{ $notice['heading'] ?: 'No heading available' }}
                                        </div>
                                    </div>

                                    <div class="text-base font-medium text-slate-600 dark:text-gray-400">
                                        {{ $notice['publication_date'] ?: '-' }}
                                    </div>
                                </div>

                                <div class="mt-3 grid gap-x-4 gap-y-2 md:grid-cols-2 xl:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]">
                                    <div class="space-y-1">
                                        <div class="text-base font-semibold uppercase tracking-[0.12em] text-slate-600 dark:text-gray-400">Buyer</div>
                                        <div class="text-base text-slate-900 dark:text-white">
                                            {{ $notice['buyer_name'] ?: 'Unknown buyer' }}
                                        </div>
                                    </div>

                                    <div class="space-y-1">
                                        <div class="text-base font-semibold uppercase tracking-[0.12em] text-slate-600 dark:text-gray-400">Winner lots</div>
                                        <div class="text-base text-slate-900 dark:text-white">
                                            {{ $notice['winner_lots'] ?: 'None' }}
                                        </div>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>
    </div>
</x-filament-panels::page>
