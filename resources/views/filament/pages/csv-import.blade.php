<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div class="space-y-2">
                    <h2 class="text-lg font-semibold text-gray-900">CSV Import</h2>
                    <p class="max-w-3xl text-sm text-gray-600">
                        Imports the canonical CPV catalog from <code>database/data/cpv_codes.csv</code> into the
                        <code>cpv_codes</code> table using the existing Laravel command.
                    </p>
                </div>

                <x-filament::button wire:click="runImport">
                    Run CSV import
                </x-filament::button>
            </div>

            <dl class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4">
                    <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Stored CPV codes</dt>
                    <dd class="mt-2 text-2xl font-semibold text-gray-900">{{ number_format($catalogCount) }}</dd>
                </div>

                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4">
                    <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Source file</dt>
                    <dd class="mt-2 text-sm text-gray-900">database/data/cpv_codes.csv</dd>
                </div>

                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4">
                    <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Target table</dt>
                    <dd class="mt-2 text-sm text-gray-900">cpv_codes</dd>
                </div>
            </dl>
        </div>

        @if ($lastError)
            <div class="rounded-xl border border-danger-200 bg-danger-50 px-4 py-3 text-sm text-danger-700">
                {{ $lastError }}
            </div>
        @endif

        @if ($lastOutput)
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <h3 class="text-sm font-semibold text-gray-900">Latest import output</h3>
                <pre class="mt-3 overflow-x-auto rounded-lg bg-gray-950 p-4 text-sm leading-6 text-gray-100">{{ $lastOutput }}</pre>
            </div>
        @endif
    </div>
</x-filament-panels::page>
