<x-filament-panels::page>
    @php
        $batchSummary = (array) ($statusSummaries['scheduled_import'] ?? []);
        $watchSummary = (array) ($statusSummaries['watch_inbox_discovery'] ?? []);

        $okBadge = 'bg-success-50 text-success-700 ring-1 ring-inset ring-success-200';
        $errBadge = 'bg-danger-50 text-danger-700 ring-1 ring-inset ring-danger-200';
        $warnBadge = 'bg-warning-50 text-warning-700 ring-1 ring-inset ring-warning-200';
    @endphp

    <div class="mx-auto max-w-6xl space-y-6">
        <section class="rounded-3xl border border-gray-200 bg-white p-6 shadow-sm">
            <div class="space-y-3">
                <p class="text-base font-medium text-gray-600">Doffin driftkontroll</p>
                <div class="space-y-2">
                    <h2 class="text-3xl font-semibold tracking-tight text-gray-950">Doffin automatisering</h2>
                    <p class="max-w-2xl text-base leading-6 text-gray-600">
                        Hold planlagt Doffin-batchimport og watch inbox discovery av eller på uten å endre kode.
                    </p>
                </div>
            </div>

            <div class="mt-6 grid gap-4 xl:grid-cols-2">
                <div class="rounded-2xl bg-gray-50 p-5">
                    @php
                        $batchEnvEnabled = (bool) ($batchSummary['environment_enabled'] ?? false);
                        $batchAdminEnabled = (bool) ($batchSummary['admin_enabled'] ?? false);
                        $batchApiConfigured = (bool) ($batchSummary['api_configured'] ?? false);
                        $batchEffectiveEnabled = (bool) ($batchSummary['enabled'] ?? false);
                        $batchSkipReasonLabel = (string) ($batchSummary['skip_reason_label'] ?? '');
                    @endphp

                    <div class="space-y-2">
                        <p class="text-base font-semibold uppercase tracking-[0.16em] text-gray-600">Doffin batch-import</p>
                        <h3 class="text-lg font-semibold text-gray-950">Planlagt import</h3>
                        <p class="text-base leading-6 text-gray-600">
                            Standard arbeidsflyt for automatisk import av nye kunngjøringer.
                        </p>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <div class="rounded-2xl bg-white px-4 py-3">
                            <div class="text-base font-semibold uppercase tracking-[0.12em] text-gray-600">Miljø</div>
                            <div class="mt-1.5 inline-flex items-center rounded-full px-2.5 py-1 text-base font-medium leading-6 {{ $batchEnvEnabled ? $okBadge : $errBadge }}">
                                {{ $batchEnvEnabled ? 'På' : 'Av' }}
                            </div>
                        </div>

                        <div class="rounded-2xl bg-white px-4 py-3">
                            <div class="text-base font-semibold uppercase tracking-[0.12em] text-gray-600">Admin</div>
                            <div class="mt-1.5 inline-flex items-center rounded-full px-2.5 py-1 text-base font-medium leading-6 {{ $batchAdminEnabled ? $okBadge : $errBadge }}">
                                {{ $batchAdminEnabled ? 'På' : 'Av' }}
                            </div>
                        </div>

                        <div class="rounded-2xl bg-white px-4 py-3">
                            <div class="text-base font-semibold uppercase tracking-[0.12em] text-gray-600">API</div>
                            <div class="mt-1.5 inline-flex items-center rounded-full px-2.5 py-1 text-base font-medium leading-6 {{ $batchApiConfigured ? $okBadge : $warnBadge }}">
                                {{ $batchApiConfigured ? 'Klar' : 'Mangler' }}
                            </div>
                        </div>

                        <div class="rounded-2xl bg-white px-4 py-3">
                            <div class="text-base font-semibold uppercase tracking-[0.12em] text-gray-600">Status</div>
                            <div class="mt-1.5 inline-flex items-center rounded-full px-2.5 py-1 text-base font-medium leading-6 {{ $batchEffectiveEnabled ? $okBadge : $warnBadge }}">
                                {{ $batchEffectiveEnabled ? 'Aktiv' : 'Stoppet' }}
                            </div>
                        </div>
                    </div>

                    @if (! $batchEffectiveEnabled && $batchSkipReasonLabel !== '')
                        <div class="mt-4 rounded-2xl border border-warning-200 bg-warning-50 px-4 py-3 text-base leading-6 text-warning-800">
                            <span class="font-semibold">Planlagt batch-import er ikke aktiv.</span>
                            <span class="block mt-1">{{ $batchSkipReasonLabel }}</span>
                        </div>
                    @endif
                </div>

                <div class="rounded-2xl bg-gray-50 p-5">
                    @php
                        $watchEnvEnabled = (bool) ($watchSummary['environment_enabled'] ?? false);
                        $watchAdminEnabled = (bool) ($watchSummary['admin_enabled'] ?? false);
                        $watchApiConfigured = (bool) ($watchSummary['api_configured'] ?? false);
                        $watchEffectiveEnabled = (bool) ($watchSummary['enabled'] ?? false);
                        $watchSkipReasonLabel = (string) ($watchSummary['skip_reason_label'] ?? '');
                    @endphp

                    <div class="space-y-2">
                        <p class="text-base font-semibold uppercase tracking-[0.16em] text-gray-600">Doffin overvåkningsprofiler</p>
                        <h3 class="text-lg font-semibold text-gray-950">Watch inbox discovery</h3>
                        <p class="text-base leading-6 text-gray-600">
                            Automatisk søk etter nye treff for aktive watch-profiler.
                        </p>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <div class="rounded-2xl bg-white px-4 py-3">
                            <div class="text-base font-semibold uppercase tracking-[0.12em] text-gray-600">Miljø</div>
                            <div class="mt-1.5 inline-flex items-center rounded-full px-2.5 py-1 text-base font-medium leading-6 {{ $watchEnvEnabled ? $okBadge : $errBadge }}">
                                {{ $watchEnvEnabled ? 'På' : 'Av' }}
                            </div>
                        </div>

                        <div class="rounded-2xl bg-white px-4 py-3">
                            <div class="text-base font-semibold uppercase tracking-[0.12em] text-gray-600">Admin</div>
                            <div class="mt-1.5 inline-flex items-center rounded-full px-2.5 py-1 text-base font-medium leading-6 {{ $watchAdminEnabled ? $okBadge : $errBadge }}">
                                {{ $watchAdminEnabled ? 'På' : 'Av' }}
                            </div>
                        </div>

                        <div class="rounded-2xl bg-white px-4 py-3">
                            <div class="text-base font-semibold uppercase tracking-[0.12em] text-gray-600">API</div>
                            <div class="mt-1.5 inline-flex items-center rounded-full px-2.5 py-1 text-base font-medium leading-6 {{ $watchApiConfigured ? $okBadge : $warnBadge }}">
                                {{ $watchApiConfigured ? 'Klar' : 'Mangler' }}
                            </div>
                        </div>

                        <div class="rounded-2xl bg-white px-4 py-3">
                            <div class="text-base font-semibold uppercase tracking-[0.12em] text-gray-600">Status</div>
                            <div class="mt-1.5 inline-flex items-center rounded-full px-2.5 py-1 text-base font-medium leading-6 {{ $watchEffectiveEnabled ? $okBadge : $warnBadge }}">
                                {{ $watchEffectiveEnabled ? 'Aktiv' : 'Stoppet' }}
                            </div>
                        </div>
                    </div>

                    @if (! $watchEffectiveEnabled && $watchSkipReasonLabel !== '')
                        <div class="mt-4 rounded-2xl border border-warning-200 bg-warning-50 px-4 py-3 text-base leading-6 text-warning-800">
                            <span class="font-semibold">Watch inbox discovery er ikke aktiv.</span>
                            <span class="block mt-1">{{ $watchSkipReasonLabel }}</span>
                        </div>
                    @endif
                </div>
            </div>

            <form wire:submit="save" class="mt-8 space-y-6">
                {{ $this->form }}

                <div class="space-y-3 pt-2">
                    <x-filament::button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="save"
                        size="lg"
                    >
                        <span wire:loading.remove wire:target="save">Lagre brytere</span>
                        <span wire:loading.inline-flex wire:target="save" class="items-center gap-2">
                            <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle cx="12" cy="12" r="9" class="opacity-30" stroke="currentColor" stroke-width="3"></circle>
                                <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"></path>
                            </svg>
                            Lagrer...
                        </span>
                    </x-filament::button>

                    <p class="text-base leading-6 text-gray-600">
                        Begge brytere krever gyldig lokal eller test Doffin API-konfigurasjon før en planlagt kjøring får kontakte API-et.
                    </p>
                </div>
            </form>
        </section>

        @if ($lastError)
            <div class="rounded-2xl border border-danger-200 bg-danger-50 px-4 py-3 text-base leading-6 text-danger-700">
                {{ $lastError }}
            </div>
        @endif
    </div>
</x-filament-panels::page>
