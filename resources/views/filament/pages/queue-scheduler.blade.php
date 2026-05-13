<x-filament-panels::page>
    @php
        $queue = $snapshot['queue'] ?? [];
        $scheduler = $snapshot['scheduler'] ?? [];
    @endphp

    <div class="space-y-6">
        <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
            <div class="space-y-1">
                <div class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Queue</div>
                <h3 class="text-lg font-semibold text-gray-900">Queue operations</h3>
                <p class="text-sm text-gray-600">
                    Operational queue configuration and the current failed job count.
                </p>
            </div>

            <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                <div class="rounded-xl bg-gray-50 px-4 py-3">
                    <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Queue connection</dt>
                    <dd class="mt-1 text-sm font-medium text-gray-900">{{ $queue['connection'] ?? 'n/a' }}</dd>
                </div>

                <div class="rounded-xl bg-gray-50 px-4 py-3">
                    <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Queue driver</dt>
                    <dd class="mt-1 text-sm font-medium text-gray-900">{{ $queue['driver'] ?? 'n/a' }}</dd>
                </div>

                <div class="rounded-xl bg-gray-50 px-4 py-3">
                    <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Default queue</dt>
                    <dd class="mt-1 text-sm font-medium text-gray-900">{{ $queue['queue'] ?? 'default' }}</dd>
                </div>

                <div class="rounded-xl bg-gray-50 px-4 py-3">
                    <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Failed jobs</dt>
                    <dd class="mt-1 text-sm font-medium text-gray-900">{{ number_format((int) ($queue['failed_jobs_count'] ?? 0)) }}</dd>
                </div>

                <div class="rounded-xl bg-gray-50 px-4 py-3">
                    <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Known queue names</dt>
                    <dd class="mt-1 text-sm font-medium text-gray-900">{{ collect($queue['known_queues'] ?? [])->implode(', ') ?: 'n/a' }}</dd>
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
            <div class="space-y-1">
                <div class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Scheduler</div>
                <h3 class="text-lg font-semibold text-gray-900">Scheduler overview</h3>
                <p class="text-sm text-gray-600">
                    The scheduler snapshot comes directly from Laravel's schedule list command.
                </p>
            </div>

            <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl bg-gray-50 px-4 py-3">
                    <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Status</dt>
                    <dd class="mt-1 text-sm font-medium text-gray-900">{{ $scheduler['status_label'] ?? 'Unavailable' }}</dd>
                </div>

                <div class="rounded-xl bg-gray-50 px-4 py-3">
                    <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Task count</dt>
                    <dd class="mt-1 text-sm font-medium text-gray-900">{{ number_format((int) ($scheduler['task_count'] ?? 0)) }}</dd>
                </div>

                <div class="rounded-xl bg-gray-50 px-4 py-3">
                    <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Failed jobs</dt>
                    <dd class="mt-1 text-sm font-medium text-gray-900">{{ number_format((int) ($snapshot['failed_jobs_count'] ?? 0)) }}</dd>
                </div>

                <div class="rounded-xl bg-gray-50 px-4 py-3">
                    <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Availability</dt>
                    <dd class="mt-1 text-sm font-medium text-gray-900">{{ ($scheduler['available'] ?? false) ? 'Available' : 'Unavailable' }}</dd>
                </div>
            </div>

            @if (empty($scheduler['tasks']))
                <div class="mt-5 rounded-xl border border-dashed border-gray-300 bg-gray-50 px-5 py-6 text-sm text-gray-600">
                    No scheduled tasks configured.
                </div>
            @else
                <div class="mt-5 space-y-3">
                    @foreach ($scheduler['tasks'] as $task)
                        <article class="rounded-xl border border-gray-200 bg-gray-50 p-4">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div class="space-y-1">
                                    <div class="text-sm font-semibold text-gray-900">{{ $task['command'] }}</div>
                                    @if (!empty($task['description']))
                                        <div class="text-sm text-gray-600">{{ $task['description'] }}</div>
                                    @endif
                                </div>
                                <div class="text-sm font-medium text-gray-700">
                                    {{ $task['next_due_date_human'] !== '' ? $task['next_due_date_human'] : 'No next due date' }}
                                </div>
                            </div>

                            <dl class="mt-3 grid gap-2 sm:grid-cols-3">
                                <div class="rounded-lg bg-white px-3 py-2">
                                    <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Expression</dt>
                                    <dd class="mt-1 text-sm text-gray-900">{{ $task['expression'] }}</dd>
                                </div>
                                <div class="rounded-lg bg-white px-3 py-2">
                                    <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Timezone</dt>
                                    <dd class="mt-1 text-sm text-gray-900">{{ $task['timezone'] }}</dd>
                                </div>
                                <div class="rounded-lg bg-white px-3 py-2">
                                    <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Mutex</dt>
                                    <dd class="mt-1 text-sm text-gray-900">{{ $task['has_mutex'] ? 'Yes' : 'No' }}</dd>
                                </div>
                            </dl>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</x-filament-panels::page>
