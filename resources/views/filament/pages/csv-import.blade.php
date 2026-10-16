<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 p-6 shadow-sm">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div class="space-y-2">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">CPV Import</h2>
                    <p class="max-w-3xl text-base leading-6 text-gray-600 dark:text-gray-400">
                        Imports the canonical CPV catalog from <code>database/data/cpv_codes.csv</code> into the
                        <code>cpv_codes</code> table using the existing Laravel command.
                    </p>
                </div>

                <x-filament::button wire:click="runImport">
                    Run CPV import
                </x-filament::button>
            </div>

            <dl class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-white/5 p-4">
                    <dt class="text-base font-semibold uppercase tracking-[0.16em] text-gray-600 dark:text-gray-400">Stored CPV codes</dt>
                    <dd class="mt-2 text-2xl font-semibold text-gray-900 dark:text-white">{{ number_format($catalogCount) }}</dd>
                </div>

                <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-white/5 p-4">
                    <dt class="text-base font-semibold uppercase tracking-[0.16em] text-gray-600 dark:text-gray-400">Source file</dt>
                    <dd class="mt-2 text-base text-gray-900 dark:text-white">database/data/cpv_codes.csv</dd>
                </div>

                <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-white/5 p-4">
                    <dt class="text-base font-semibold uppercase tracking-[0.16em] text-gray-600 dark:text-gray-400">Target table</dt>
                    <dd class="mt-2 text-base text-gray-900 dark:text-white">cpv_codes</dd>
                </div>
            </dl>
        </div>

        @if ($lastError)
            <div class="rounded-xl border border-danger-200 dark:border-danger-500/30 bg-danger-50 dark:bg-danger-500/10 px-4 py-3 text-base leading-6 text-danger-700 dark:text-danger-400">
                {{ $lastError }}
            </div>
        @endif

        @if ($lastOutput)
            <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 p-5 shadow-sm">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">Latest import output</h3>
                <pre class="mt-3 overflow-x-auto rounded-lg bg-gray-950 p-4 text-base leading-6 text-gray-100">{{ $lastOutput }}</pre>
            </div>
        @endif
    </div>
</x-filament-panels::page>
