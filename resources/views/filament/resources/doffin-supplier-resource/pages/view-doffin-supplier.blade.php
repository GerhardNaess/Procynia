<x-filament-panels::page>
    @php
        /** @var \App\Models\DoffinSupplier $supplier */
        $supplier = $this->getRecord();
        $noticeRows = $this->noticeRows();
    @endphp

    <div class="mx-auto max-w-6xl space-y-4">
        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-3">
                <h2 class="text-lg font-semibold tracking-tight text-slate-950">Supplier</h2>
            </div>

            <div class="grid gap-x-6 gap-y-4 px-5 py-4 md:grid-cols-2">
                <div class="space-y-1">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Supplier name</div>
                    <div class="text-base font-semibold leading-6 text-slate-950">
                        {{ $supplier->supplier_name }}
                    </div>
                </div>

                <div class="space-y-1">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Organization number</div>
                    <div class="text-sm font-medium text-slate-900">
                        {{ $supplier->organization_number ?: 'Unknown' }}
                    </div>
                </div>

                <div class="space-y-1">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Normalized name</div>
                    <div class="text-sm font-medium text-slate-900 break-all">
                        {{ $supplier->normalized_name }}
                    </div>
                </div>

                <div class="space-y-1">
                    <div class="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Updated at</div>
                    <div class="text-sm font-medium text-slate-900">
                        {{ $supplier->updated_at?->format('Y-m-d H:i:s') ?? '-' }}
                    </div>
                </div>
            </div>
        </section>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
                <h2 class="text-lg font-semibold tracking-tight text-slate-950">Linked notices</h2>
                <span class="text-sm text-slate-500">{{ number_format(count($noticeRows)) }}</span>
            </div>

            <div class="max-h-[30rem] overflow-y-auto">
                @if (count($noticeRows) === 0)
                    <div class="px-5 py-6 text-sm text-slate-500">
                        No linked notices are available for this supplier.
                    </div>
                @else
                    <div class="divide-y divide-slate-200">
                        @foreach ($noticeRows as $notice)
                            <article class="px-5 py-3">
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div class="min-w-0 space-y-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            @if ($notice['notice_record_id'])
                                                <a
                                                    href="{{ \App\Filament\Resources\DoffinNoticeResource::getUrl('view', ['record' => $notice['notice_record_id']]) }}"
                                                    class="text-sm font-semibold text-primary-600 hover:text-primary-500"
                                                >
                                                    {{ $notice['notice_id'] ?: 'Unknown notice' }}
                                                </a>
                                            @else
                                                <div class="text-sm font-semibold text-slate-900">
                                                    {{ $notice['notice_id'] ?: 'Unknown notice' }}
                                                </div>
                                            @endif

                                            @if ($notice['source'])
                                                <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-700 ring-1 ring-inset ring-slate-200">
                                                    {{ $notice['source'] }}
                                                </span>
                                            @endif
                                        </div>

                                        <div class="text-sm font-medium leading-5 text-slate-950">
                                            {{ $notice['heading'] ?: 'No heading available' }}
                                        </div>
                                    </div>

                                    <div class="text-xs font-medium text-slate-500">
                                        {{ $notice['publication_date'] ?: '-' }}
                                    </div>
                                </div>

                                <div class="mt-3 grid gap-x-4 gap-y-2 md:grid-cols-2 xl:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]">
                                    <div class="space-y-1">
                                        <div class="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Buyer</div>
                                        <div class="text-sm text-slate-900">
                                            {{ $notice['buyer_name'] ?: 'Unknown buyer' }}
                                        </div>
                                    </div>

                                    <div class="space-y-1">
                                        <div class="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Winner lots</div>
                                        <div class="text-sm text-slate-900">
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
