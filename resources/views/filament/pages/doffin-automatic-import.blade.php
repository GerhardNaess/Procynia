<x-filament-panels::page>
    @php
        $envEnabled = (bool) ($statusSummary['environment_enabled'] ?? false);
        $adminEnabled = (bool) ($statusSummary['admin_enabled'] ?? false);
        $apiConfigured = (bool) ($statusSummary['api_configured'] ?? false);
        $effectiveEnabled = (bool) ($statusSummary['enabled'] ?? false);
        $skipReasonLabel = (string) ($statusSummary['skip_reason_label'] ?? '');

        $okBadge = 'bg-success-50 text-success-700 ring-1 ring-inset ring-success-200';
        $errBadge = 'bg-danger-50 text-danger-700 ring-1 ring-inset ring-danger-200';
        $warnBadge = 'bg-warning-50 text-warning-700 ring-1 ring-inset ring-warning-200';
    @endphp

    <div class="mx-auto max-w-4xl space-y-6">
        <section class="rounded-3xl border border-gray-200 bg-white p-6 shadow-sm">
            <div class="space-y-3">
                <p class="text-sm font-medium text-gray-500">Doffin driftkontroll</p>
                <div class="space-y-2">
                    <h2 class="text-3xl font-semibold tracking-tight text-gray-950">Doffin automatisk import</h2>
                    <p class="max-w-2xl text-sm leading-6 text-gray-600">
                        Hold planlagt Doffin-import av eller på uten å endre kode. Når bryteren er av, hopper scheduler stille over uten API-kall.
                    </p>
                </div>
            </div>

            <div class="mt-6 grid gap-3 md:grid-cols-4">
                <div class="rounded-2xl bg-gray-50 px-4 py-3">
                    <div class="text-xs font-semibold uppercase tracking-[0.12em] text-gray-500">Miljøbryter</div>
                    <div class="mt-1.5 inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $envEnabled ? $okBadge : $errBadge }}">
                        {{ $envEnabled ? 'På' : 'Av' }}
                    </div>
                </div>

                <div class="rounded-2xl bg-gray-50 px-4 py-3">
                    <div class="text-xs font-semibold uppercase tracking-[0.12em] text-gray-500">Admin-bryter</div>
                    <div class="mt-1.5 inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $adminEnabled ? $okBadge : $errBadge }}">
                        {{ $adminEnabled ? 'På' : 'Av' }}
                    </div>
                </div>

                <div class="rounded-2xl bg-gray-50 px-4 py-3">
                    <div class="text-xs font-semibold uppercase tracking-[0.12em] text-gray-500">API-konfig</div>
                    <div class="mt-1.5 inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $apiConfigured ? $okBadge : $warnBadge }}">
                        {{ $apiConfigured ? 'Klar' : 'Mangler' }}
                    </div>
                </div>

                <div class="rounded-2xl bg-gray-50 px-4 py-3">
                    <div class="text-xs font-semibold uppercase tracking-[0.12em] text-gray-500">Effektiv status</div>
                    <div class="mt-1.5 inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $effectiveEnabled ? $okBadge : $warnBadge }}">
                        {{ $effectiveEnabled ? 'Aktiv' : 'Stoppet' }}
                    </div>
                </div>
            </div>

            @if (! $effectiveEnabled && $skipReasonLabel !== '')
                <div class="mt-4 rounded-2xl border border-warning-200 bg-warning-50 px-4 py-3 text-sm text-warning-800">
                    <span class="font-semibold">Planlagt import er ikke aktiv.</span>
                    <span class="block mt-1">{{ $skipReasonLabel }}</span>
                </div>
            @endif

            <form wire:submit="save" class="mt-8 space-y-6">
                {{ $this->form }}

                <div class="space-y-3 pt-2">
                    <x-filament::button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="save"
                        size="lg"
                    >
                        <span wire:loading.remove wire:target="save">Lagre bryteren</span>
                        <span wire:loading.inline-flex wire:target="save" class="items-center gap-2">
                            <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle cx="12" cy="12" r="9" class="opacity-30" stroke="currentColor" stroke-width="3"></circle>
                                <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"></path>
                            </svg>
                            Lagrer...
                        </span>
                    </x-filament::button>

                    <p class="text-sm text-gray-500">
                        Scheduler må fortsatt ha gyldig Doffin API-konfigurasjon før en planlagt kjøring får kontakte API-et.
                    </p>
                </div>
            </form>
        </section>

        @if ($lastError)
            <div class="rounded-2xl border border-danger-200 bg-danger-50 px-4 py-3 text-sm text-danger-700">
                {{ $lastError }}
            </div>
        @endif
    </div>
</x-filament-panels::page>
