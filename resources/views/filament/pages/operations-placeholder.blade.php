<x-filament-panels::page>
    <div class="max-w-3xl rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
        <div class="space-y-2">
            <div class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Placeholder</div>
            <h2 class="text-lg font-semibold text-gray-900">{{ $this->getTitle() }}</h2>
            <p class="text-sm text-gray-600">
                {{ $this->getSubheading() }}
            </p>
        </div>
    </div>
</x-filament-panels::page>
