<x-filament-panels::page>
    <div class="mx-auto max-w-3xl space-y-6">
        <section class="rounded-3xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 p-8 shadow-sm">
            <div class="space-y-3">
                <p class="text-base font-medium text-gray-600 dark:text-gray-400">Doffin supplier harvest</p>
                <div class="space-y-2">
                    <h2 class="text-3xl font-semibold tracking-tight text-gray-950 dark:text-white">Start a supplier harvest</h2>
                    <p class="max-w-2xl text-base leading-6 text-gray-600 dark:text-gray-400">
                        Start a supplier harvest and follow progress on the run page.
                    </p>
                </div>
            </div>

            <form wire:submit="startSupplierHarvest" class="mt-8 space-y-6">
                {{ $this->form }}

                <div class="space-y-3 pt-2">
                    <x-filament::button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="startSupplierHarvest"
                        size="lg"
                    >
                        <span wire:loading.remove wire:target="startSupplierHarvest">
                            Harvest suppliers from Doffin
                        </span>
                        <span wire:loading.inline-flex wire:target="startSupplierHarvest" class="items-center gap-2">
                            <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle cx="12" cy="12" r="9" class="opacity-30" stroke="currentColor" stroke-width="3"></circle>
                                <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"></path>
                            </svg>
                            Starting...
                        </span>
                    </x-filament::button>

                    <p class="text-base leading-6 text-gray-600 dark:text-gray-400">
                        The run page opens immediately after the harvest is queued.
                    </p>
                </div>
            </form>
        </section>

        @if ($lastError)
            <div class="rounded-2xl border border-danger-200 dark:border-danger-500/30 bg-danger-50 dark:bg-danger-500/10 px-4 py-3 text-base leading-6 text-danger-700 dark:text-danger-400">
                {{ $lastError }}
            </div>
        @endif
    </div>
</x-filament-panels::page>
