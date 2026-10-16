<x-filament-panels::page>
    <div class="max-w-3xl rounded-2xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 p-6 shadow-sm">
        <div class="space-y-2">
            <div class="text-base font-semibold uppercase tracking-[0.16em] text-gray-600 dark:text-gray-400">Placeholder</div>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $this->getTitle() }}</h2>
            <p class="text-base leading-6 text-gray-600 dark:text-gray-400">
                {{ $this->getSubheading() }}
            </p>
        </div>
    </div>
</x-filament-panels::page>
