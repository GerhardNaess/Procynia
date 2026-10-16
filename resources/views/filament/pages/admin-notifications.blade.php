<x-filament-panels::page>
    @php
        $severityLabels = [
            'critical' => \App\Filament\Support\AdminText::get('admin_a11y.severity_critical', 'Kritisk'),
            'warning'  => \App\Filament\Support\AdminText::get('admin_a11y.severity_warning', 'Advarsel'),
            'info'     => \App\Filament\Support\AdminText::get('admin_a11y.severity_info', 'Info'),
        ];
    @endphp

    {{--
        WCAG Fase 6 (A11Y-125): severity is shown as text, not only as the card colour and dot; the unread
        notifications are a list; «Merk lest» names the notification it acts on; and marking one as read
        moves focus to the next notification's button (then the previous one, then the read-list heading)
        instead of dropping it on <body> when the card disappears. The calls are the same Livewire
        methods the buttons called before.
    --}}
    <div
        class="space-y-6"
        data-notifications-root
        x-data="{
            focusAfterRemoval(index) {
                this.$nextTick(() => {
                    const root = document.querySelector('[data-notifications-root]');
                    const buttons = root ? root.querySelectorAll('[data-notification-mark-read]') : [];
                    const target = buttons[index]
                        ?? buttons[index - 1]
                        ?? root?.querySelector('[data-notifications-focus-fallback]')
                        ?? document.querySelector('h1');

                    if (target) {
                        if (! target.matches('button, a, [tabindex]')) {
                            target.setAttribute('tabindex', '-1');
                        }

                        target.focus();
                    }
                });
            },
            markOne(id, index) {
                this.$wire.markAsRead(id).then(() => this.focusAfterRemoval(index));
            },
            markAll() {
                this.$wire.markAllAsRead().then(() => this.focusAfterRemoval(-1));
            },
        }"
    >

        @if ($unreadCount > 0)
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="admin-notifications-unread-heading" class="text-base font-semibold text-gray-900 dark:text-white">{{ $unreadCount }} uleste varsel{{ $unreadCount !== 1 ? 'er' : '' }}</h2>
                <button x-on:click="markAll()" type="button" data-notifications-mark-all
                    class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 px-3 py-1.5 text-base font-semibold text-gray-700 dark:text-gray-200 shadow-sm transition hover:bg-gray-50 dark:hover:bg-white/5">
                    Merk alle som lest
                </button>
            </div>
        @endif

        @if (count($unreadNotifications) > 0)
            <ul role="list" class="space-y-6" aria-labelledby="admin-notifications-unread-heading">
                @foreach ($unreadNotifications as $n)
                    @php
                        $severity = array_key_exists($n['severity'], $severityLabels) ? $n['severity'] : 'info';
                        $cls = [
                            'critical' => 'border-red-200 dark:border-red-500/30 bg-red-50 dark:bg-red-500/10',
                            'warning'  => 'border-amber-200 dark:border-amber-500/30 bg-amber-50 dark:bg-amber-500/10',
                            'info'     => 'border-blue-200 dark:border-blue-500/30 bg-blue-50 dark:bg-blue-500/10',
                        ][$severity];
                        $dot = [
                            'critical' => 'bg-red-500',
                            'warning'  => 'bg-amber-400',
                            'info'     => 'bg-blue-500',
                        ][$severity];
                        $badge = [
                            'critical' => 'bg-white text-red-800 ring-red-300 dark:bg-gray-900 dark:text-red-300 dark:ring-red-500/40',
                            'warning'  => 'bg-white text-amber-900 ring-amber-300 dark:bg-gray-900 dark:text-amber-300 dark:ring-amber-500/40',
                            'info'     => 'bg-white text-blue-800 ring-blue-300 dark:bg-gray-900 dark:text-blue-300 dark:ring-blue-500/40',
                        ][$severity];
                        $titleId = 'admin-notification-title-'.$n['id'];
                    @endphp
                    <li class="rounded-2xl border {{ $cls }} p-4 shadow-sm" wire:key="admin-notification-{{ $n['id'] }}">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="flex items-start gap-3 min-w-0">
                                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ $dot }}" aria-hidden="true"></span>
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-base font-semibold leading-6 ring-1 ring-inset {{ $badge }}" data-notification-severity="{{ $severity }}">{{ $severityLabels[$severity] }}</span>
                                        <span id="{{ $titleId }}" class="font-semibold text-base text-gray-900 dark:text-white">{{ $n['title'] }}</span>
                                    </div>
                                    <p class="mt-0.5 text-base leading-6 text-gray-600 dark:text-gray-400">{{ $n['message'] }}</p>
                                    <div class="mt-1 text-base text-gray-600 dark:text-gray-400">{{ $n['created_at'] }}</div>
                                </div>
                            </div>
                            <button x-on:click="markOne({{ (int) $n['id'] }}, {{ $loop->index }})" type="button"
                                data-notification-mark-read
                                aria-describedby="{{ $titleId }}"
                                class="shrink-0 rounded-full border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 px-3 py-1.5 text-base font-semibold leading-6 text-gray-700 dark:text-gray-200 transition hover:bg-gray-50 dark:hover:bg-white/5">
                                Merk lest
                            </button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @else
            <div class="rounded-2xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 px-5 py-10 text-center shadow-sm">
                <p class="text-base text-gray-600 dark:text-gray-400" @if (count($recentNotifications) === 0) data-notifications-focus-fallback @endif>Ingen uleste varsler.</p>
            </div>
        @endif

        @if (count($recentNotifications) > 0)
            <div class="mt-6">
                <h2 class="mb-3 text-base font-bold uppercase tracking-wider text-gray-600 dark:text-gray-400" data-notifications-focus-fallback>Leste varsler (siste 30)</h2>
                <ul role="list" class="divide-y divide-gray-100 dark:divide-white/10 rounded-2xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 shadow-sm overflow-hidden">
                    @foreach ($recentNotifications as $n)
                        <li class="px-5 py-3 text-base text-gray-600 dark:text-gray-400" wire:key="admin-notification-read-{{ $n['id'] }}">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <span class="font-medium text-gray-700 dark:text-gray-200">{{ $n['title'] }}</span>
                                    <span class="ml-2 text-gray-600 dark:text-gray-400">{{ $n['message'] }}</span>
                                </div>
                                <span class="shrink-0 text-base text-gray-600 dark:text-gray-400">{{ $n['read_at'] }}</span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</x-filament-panels::page>
