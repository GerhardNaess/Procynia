<x-filament-panels::page>
    <div class="space-y-6">
        <section class="max-w-3xl rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
            <p class="text-base leading-6 text-gray-600">
                Uptime Kuma brukes til oppetidsovervåkning av Procynia og sentrale endepunkter.
                Selve overvåkningen kjøres som eget Docker-basert verktøy, mens Procynia Admin fungerer som inngang til driftsoversikten.
            </p>
        </section>

        <section class="max-w-3xl rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
            <div class="space-y-1">
                <div class="text-base font-semibold uppercase tracking-[0.16em] text-gray-600">Overvåkningsverktøy</div>
                <h3 class="text-lg font-semibold text-gray-900">Uptime Kuma</h3>
            </div>

            <dl class="mt-5 space-y-3 text-base">
                <div class="flex gap-4">
                    <dt class="w-28 shrink-0 font-medium text-gray-600">Verktøy</dt>
                    <dd class="text-gray-900">Uptime Kuma</dd>
                </div>
                <div class="flex gap-4">
                    <dt class="w-28 shrink-0 font-medium text-gray-600">Status</dt>
                    <dd class="text-gray-900">Konfigurert</dd>
                </div>
                <div class="flex gap-4">
                    <dt class="w-28 shrink-0 font-medium text-gray-600">Adresse</dt>
                    <dd class="font-mono text-base text-gray-900">
                        @if ($this->uptimeKumaUrl !== '')
                            {{ $this->uptimeKumaUrl }}
                        @else
                            <span class="text-gray-600">Ikke konfigurert</span>
                        @endif
                    </dd>
                </div>
                <div class="flex gap-4">
                    <dt class="w-28 shrink-0 font-medium text-gray-600">Bruk</dt>
                    <dd class="text-gray-900">Oppetidsovervåkning og varsling</dd>
                </div>
            </dl>

            <div class="mt-6">
                @if ($this->uptimeKumaUrl !== '')
                    <a
                        href="{{ $this->uptimeKumaUrl }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-base font-semibold text-white shadow-sm hover:bg-primary-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600"
                    >
                        Åpne Uptime Kuma
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="h-3.5 w-3.5" aria-hidden="true">
                            <path d="M6.22 8.72a.75.75 0 0 0 1.06 1.06l5.22-5.22v1.69a.75.75 0 0 0 1.5 0v-3.5a.75.75 0 0 0-.75-.75h-3.5a.75.75 0 0 0 0 1.5h1.69L6.22 8.72Z" />
                            <path d="M3.5 6.75c0-.69.56-1.25 1.25-1.25H7A.75.75 0 0 0 7 4H4.75A2.75 2.75 0 0 0 2 6.75v4.5A2.75 2.75 0 0 0 4.75 14h4.5A2.75 2.75 0 0 0 12 11.25V9a.75.75 0 0 0-1.5 0v2.25c0 .69-.56 1.25-1.25 1.25h-4.5c-.69 0-1.25-.56-1.25-1.25v-4.5Z" />
                        </svg>
                    </a>
                @else
                    <p class="text-base leading-6 text-amber-800">
                        Uptime Kuma URL er ikke konfigurert. Sett <code class="rounded bg-amber-50 px-1.5 py-0.5 font-mono text-base">UPTIME_KUMA_URL</code> i miljøkonfigurasjonen.
                    </p>
                @endif
            </div>
        </section>

        <section class="max-w-3xl rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
            <div class="space-y-1">
                <div class="text-base font-semibold uppercase tracking-[0.16em] text-gray-600">Veiledning</div>
                <h3 class="text-lg font-semibold text-gray-900">Anbefalt bruk</h3>
            </div>
            <p class="mt-3 text-base leading-6 text-gray-600">
                Bruk Uptime Kuma til å overvåke Procynia web, health-endpoint, admin-login og andre kritiske endepunkter.
                Varsling og monitorer administreres direkte i Uptime Kuma.
            </p>
        </section>
    </div>
</x-filament-panels::page>
