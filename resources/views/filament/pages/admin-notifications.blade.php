<x-filament-panels::page>
    <div class="space-y-6">

        @if ($unreadCount > 0)
            <div class="flex items-center justify-between">
                <h2 class="text-base font-semibold text-gray-900">{{ $unreadCount }} uleste varsel{{ $unreadCount !== 1 ? 'er' : '' }}</h2>
                <button wire:click="markAllAsRead" type="button"
                    class="rounded-xl border border-gray-200 bg-white px-3 py-1.5 text-base font-semibold text-gray-600 shadow-sm transition hover:bg-gray-50">
                    Merk alle som lest
                </button>
            </div>
        @endif

        @forelse ($unreadNotifications as $n)
            @php
                $severityMap = [
                    'critical' => 'border-red-200 bg-red-50',
                    'warning'  => 'border-amber-200 bg-amber-50',
                    'info'     => 'border-blue-200 bg-blue-50',
                ];
                $cls = $severityMap[$n['severity']] ?? $severityMap['info'];
                $dotMap = [
                    'critical' => 'bg-red-500',
                    'warning'  => 'bg-amber-400',
                    'info'     => 'bg-blue-500',
                ];
                $dot = $dotMap[$n['severity']] ?? $dotMap['info'];
            @endphp
            <div class="rounded-2xl border {{ $cls }} p-4 shadow-sm">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex items-start gap-3 min-w-0">
                        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ $dot }}"></span>
                        <div class="min-w-0">
                            <div class="font-semibold text-base text-gray-900">{{ $n['title'] }}</div>
                            <p class="mt-0.5 text-base leading-6 text-gray-600">{{ $n['message'] }}</p>
                            <div class="mt-1 text-base text-gray-600">{{ $n['created_at'] }}</div>
                        </div>
                    </div>
                    <button wire:click="markAsRead({{ $n['id'] }})" type="button"
                        class="shrink-0 rounded-full border border-gray-200 bg-white px-3 py-1.5 text-base font-semibold leading-6 text-gray-600 transition hover:bg-gray-50">
                        Merk lest
                    </button>
                </div>
            </div>
        @empty
            <div class="rounded-2xl border border-gray-200 bg-white px-5 py-10 text-center shadow-sm">
                <p class="text-base text-gray-600">Ingen uleste varsler.</p>
            </div>
        @endforelse

        @if (count($recentNotifications) > 0)
            <div class="mt-6">
                <h2 class="mb-3 text-base font-bold uppercase tracking-wider text-gray-600">Leste varsler (siste 30)</h2>
                <div class="divide-y divide-gray-100 rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                    @foreach ($recentNotifications as $n)
                        <div class="px-5 py-3 text-base text-gray-600">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <span class="font-medium text-gray-700">{{ $n['title'] }}</span>
                                    <span class="ml-2 text-gray-600">{{ $n['message'] }}</span>
                                </div>
                                <span class="shrink-0 text-base text-gray-600">{{ $n['read_at'] }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
