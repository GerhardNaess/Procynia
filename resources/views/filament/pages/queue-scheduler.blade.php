<x-filament-panels::page>
    @php
        $queue = $snapshot['queue'] ?? [];
        $scheduler = $snapshot['scheduler'] ?? [];
    @endphp

    <div class="space-y-6">
        <section class="rounded-2xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 p-6 shadow-sm">
            <div class="space-y-1">
                <div class="text-base font-semibold uppercase tracking-[0.16em] text-gray-600 dark:text-gray-400">Queue</div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Queue operations</h2>
                <p class="text-base leading-6 text-gray-600 dark:text-gray-400">
                    Operational queue configuration and the current failed job count.
                </p>
            </div>

            <dl class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                <div class="rounded-xl bg-gray-50 dark:bg-white/5 px-4 py-3">
                    <dt class="text-base font-semibold uppercase tracking-[0.16em] text-gray-600 dark:text-gray-400">Queue connection</dt>
                    <dd class="mt-1 text-base font-medium text-gray-900 dark:text-white">{{ $queue['connection'] ?? 'n/a' }}</dd>
                </div>

                <div class="rounded-xl bg-gray-50 dark:bg-white/5 px-4 py-3">
                    <dt class="text-base font-semibold uppercase tracking-[0.16em] text-gray-600 dark:text-gray-400">Queue driver</dt>
                    <dd class="mt-1 text-base font-medium text-gray-900 dark:text-white">{{ $queue['driver'] ?? 'n/a' }}</dd>
                </div>

                <div class="rounded-xl bg-gray-50 dark:bg-white/5 px-4 py-3">
                    <dt class="text-base font-semibold uppercase tracking-[0.16em] text-gray-600 dark:text-gray-400">Default queue</dt>
                    <dd class="mt-1 text-base font-medium text-gray-900 dark:text-white">{{ $queue['queue'] ?? 'default' }}</dd>
                </div>

                <div class="rounded-xl bg-gray-50 dark:bg-white/5 px-4 py-3">
                    <dt class="text-base font-semibold uppercase tracking-[0.16em] text-gray-600 dark:text-gray-400">Failed jobs</dt>
                    <dd class="mt-1 text-base font-medium text-gray-900 dark:text-white">{{ number_format((int) ($queue['failed_jobs_count'] ?? 0)) }}</dd>
                </div>

                <div class="rounded-xl bg-gray-50 dark:bg-white/5 px-4 py-3">
                    <dt class="text-base font-semibold uppercase tracking-[0.16em] text-gray-600 dark:text-gray-400">Known queue names</dt>
                    <dd class="mt-1 text-base font-medium text-gray-900 dark:text-white">{{ collect($queue['known_queues'] ?? [])->implode(', ') ?: 'n/a' }}</dd>
                </div>
            </dl>
        </section>

        <section class="rounded-2xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 p-6 shadow-sm">
            <div class="space-y-1">
                <div class="text-base font-semibold uppercase tracking-[0.16em] text-gray-600 dark:text-gray-400">Scheduler</div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Scheduler overview</h2>
                <p class="text-base leading-6 text-gray-600 dark:text-gray-400">
                    The scheduler snapshot comes directly from Laravel's schedule list command.
                </p>
            </div>

            <dl class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl bg-gray-50 dark:bg-white/5 px-4 py-3">
                    <dt class="text-base font-semibold uppercase tracking-[0.16em] text-gray-600 dark:text-gray-400">Status</dt>
                    <dd class="mt-1 text-base font-medium text-gray-900 dark:text-white">{{ $scheduler['status_label'] ?? 'Unavailable' }}</dd>
                </div>

                <div class="rounded-xl bg-gray-50 dark:bg-white/5 px-4 py-3">
                    <dt class="text-base font-semibold uppercase tracking-[0.16em] text-gray-600 dark:text-gray-400">Task count</dt>
                    <dd class="mt-1 text-base font-medium text-gray-900 dark:text-white">{{ number_format((int) ($scheduler['task_count'] ?? 0)) }}</dd>
                </div>

                <div class="rounded-xl bg-gray-50 dark:bg-white/5 px-4 py-3">
                    <dt class="text-base font-semibold uppercase tracking-[0.16em] text-gray-600 dark:text-gray-400">Failed jobs</dt>
                    <dd class="mt-1 text-base font-medium text-gray-900 dark:text-white">{{ number_format((int) ($snapshot['failed_jobs_count'] ?? 0)) }}</dd>
                </div>

                <div class="rounded-xl bg-gray-50 dark:bg-white/5 px-4 py-3">
                    <dt class="text-base font-semibold uppercase tracking-[0.16em] text-gray-600 dark:text-gray-400">Availability</dt>
                    <dd class="mt-1 text-base font-medium text-gray-900 dark:text-white">{{ ($scheduler['available'] ?? false) ? 'Available' : 'Unavailable' }}</dd>
                </div>
            </dl>

            @if (empty($scheduler['tasks']))
                <div class="mt-5 rounded-xl border border-dashed border-gray-300 dark:border-white/20 bg-gray-50 dark:bg-white/5 px-5 py-6 text-base leading-6 text-gray-600 dark:text-gray-400">
                    No scheduled tasks configured.
                </div>
            @else
                <div class="mt-5 space-y-3">
                    @foreach ($scheduler['tasks'] as $task)
                        <article class="rounded-xl border border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-white/5 p-4">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div class="space-y-1">
                                    <div class="text-base font-semibold text-gray-900 dark:text-white">{{ $task['command'] }}</div>
                                    @if (!empty($task['description']))
                                        <div class="text-base leading-6 text-gray-600 dark:text-gray-400">{{ $task['description'] }}</div>
                                    @endif
                                </div>
                                <div class="text-base font-medium text-gray-700 dark:text-gray-200">
                                    {{ $task['next_due_date_human'] !== '' ? $task['next_due_date_human'] : 'No next due date' }}
                                </div>
                            </div>

                            <dl class="mt-3 grid gap-2 sm:grid-cols-3">
                                <div class="rounded-lg bg-white dark:bg-gray-900 px-3 py-2">
                                    <dt class="text-base font-semibold uppercase tracking-[0.16em] text-gray-600 dark:text-gray-400">Expression</dt>
                                    <dd class="mt-1 text-base text-gray-900 dark:text-white">{{ $task['expression'] }}</dd>
                                </div>
                                <div class="rounded-lg bg-white dark:bg-gray-900 px-3 py-2">
                                    <dt class="text-base font-semibold uppercase tracking-[0.16em] text-gray-600 dark:text-gray-400">Timezone</dt>
                                    <dd class="mt-1 text-base text-gray-900 dark:text-white">{{ $task['timezone'] }}</dd>
                                </div>
                                <div class="rounded-lg bg-white dark:bg-gray-900 px-3 py-2">
                                    <dt class="text-base font-semibold uppercase tracking-[0.16em] text-gray-600 dark:text-gray-400">Mutex</dt>
                                    <dd class="mt-1 text-base text-gray-900 dark:text-white">{{ $task['has_mutex'] ? 'Yes' : 'No' }}</dd>
                                </div>
                            </dl>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</x-filament-panels::page>
