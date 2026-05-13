<x-filament-panels::page>
    @php
        $database = $snapshot['database'] ?? [];
        $redis = $snapshot['redis'] ?? [];
        $queue = $snapshot['queue'] ?? [];
        $scheduler = $snapshot['scheduler'] ?? [];
        $uptime = $snapshot['uptime'] ?? [];
        $databaseBadgeClasses = ($database['available'] ?? false)
            ? 'bg-success-50 text-success-700 ring-1 ring-inset ring-success-200'
            : 'bg-danger-50 text-danger-700 ring-1 ring-inset ring-danger-200';
        $redisBadgeClasses = ($redis['available'] ?? false)
            ? 'bg-success-50 text-success-700 ring-1 ring-inset ring-success-200'
            : 'bg-danger-50 text-danger-700 ring-1 ring-inset ring-danger-200';
        $schedulerBadgeClasses = ($scheduler['available'] ?? false)
            ? (($scheduler['task_count'] ?? 0) > 0
                ? 'bg-success-50 text-success-700 ring-1 ring-inset ring-success-200'
                : 'bg-warning-50 text-warning-700 ring-1 ring-inset ring-warning-200')
            : 'bg-danger-50 text-danger-700 ring-1 ring-inset ring-danger-200';
    @endphp

    <div class="space-y-6">
        <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl bg-gray-50 px-4 py-3">
                    <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">APP_ENV</dt>
                    <dd class="mt-1 text-sm font-medium text-gray-900">{{ $snapshot['app_env'] ?? 'n/a' }}</dd>
                </div>

                <div class="rounded-xl bg-gray-50 px-4 py-3">
                    <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">APP_DEBUG</dt>
                    <dd class="mt-1 text-sm font-medium text-gray-900">{{ ($snapshot['app_debug'] ?? false) ? 'Enabled' : 'Disabled' }}</dd>
                </div>

                <div class="rounded-xl bg-gray-50 px-4 py-3">
                    <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Laravel version</dt>
                    <dd class="mt-1 text-sm font-medium text-gray-900">{{ $snapshot['laravel_version'] ?? 'n/a' }}</dd>
                </div>

                <div class="rounded-xl bg-gray-50 px-4 py-3">
                    <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">PHP version</dt>
                    <dd class="mt-1 text-sm font-medium text-gray-900">{{ $snapshot['php_version'] ?? 'n/a' }}</dd>
                </div>
            </div>
        </section>

        <section class="grid gap-6 xl:grid-cols-2">
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="space-y-1">
                    <div class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Infrastructure</div>
                    <h3 class="text-lg font-semibold text-gray-900">Database and Redis</h3>
                </div>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4">
                        <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Database connection</dt>
                        <dd class="mt-2 inline-flex items-center rounded-full px-3 py-1 text-sm font-medium {{ $databaseBadgeClasses }}">
                            {{ $database['status_label'] ?? 'Unavailable' }}
                        </dd>
                        <dl class="mt-3 space-y-2 text-sm text-gray-700">
                            <div class="flex items-start justify-between gap-4">
                                <dt class="font-medium text-gray-500">Connection</dt>
                                <dd class="text-right text-gray-900">{{ $database['connection'] ?? 'n/a' }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-4">
                                <dt class="font-medium text-gray-500">Driver</dt>
                                <dd class="text-right text-gray-900">{{ $database['driver'] ?? 'n/a' }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-4">
                                <dt class="font-medium text-gray-500">Database</dt>
                                <dd class="text-right text-gray-900">{{ $database['database'] ?? 'n/a' }}</dd>
                            </div>
                        </dl>
                        @if (!empty($database['error_message']))
                            <p class="mt-3 text-sm text-danger-700">{{ $database['error_message'] }}</p>
                        @endif
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4">
                        <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Redis connection</dt>
                        <dd class="mt-2 inline-flex items-center rounded-full px-3 py-1 text-sm font-medium {{ $redisBadgeClasses }}">
                            {{ $redis['status_label'] ?? 'Unavailable' }}
                        </dd>
                        <dl class="mt-3 space-y-2 text-sm text-gray-700">
                            <div class="flex items-start justify-between gap-4">
                                <dt class="font-medium text-gray-500">Connection</dt>
                                <dd class="text-right text-gray-900">{{ $redis['connection'] ?? 'n/a' }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-4">
                                <dt class="font-medium text-gray-500">Host</dt>
                                <dd class="text-right text-gray-900">{{ $redis['host'] ?? 'n/a' }}</dd>
                            </div>
                            <div class="flex items-start justify-between gap-4">
                                <dt class="font-medium text-gray-500">Database</dt>
                                <dd class="text-right text-gray-900">{{ $redis['database'] ?? 'n/a' }}</dd>
                            </div>
                        </dl>
                        @if (!empty($redis['error_message']))
                            <p class="mt-3 text-sm text-danger-700">{{ $redis['error_message'] }}</p>
                        @endif
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="space-y-1">
                    <div class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Runtime drivers</div>
                    <h3 class="text-lg font-semibold text-gray-900">Application settings</h3>
                </div>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div class="rounded-xl bg-gray-50 px-4 py-3">
                        <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Queue driver</dt>
                        <dd class="mt-1 text-sm font-medium text-gray-900">{{ $queue['driver'] ?? 'n/a' }}</dd>
                    </div>

                    <div class="rounded-xl bg-gray-50 px-4 py-3">
                        <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Queue connection</dt>
                        <dd class="mt-1 text-sm font-medium text-gray-900">{{ $queue['connection'] ?? 'n/a' }}</dd>
                    </div>

                    <div class="rounded-xl bg-gray-50 px-4 py-3">
                        <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Cache driver</dt>
                        <dd class="mt-1 text-sm font-medium text-gray-900">{{ $snapshot['cache_driver'] ?? 'n/a' }}</dd>
                    </div>

                    <div class="rounded-xl bg-gray-50 px-4 py-3">
                        <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Session driver</dt>
                        <dd class="mt-1 text-sm font-medium text-gray-900">{{ $snapshot['session_driver'] ?? 'n/a' }}</dd>
                    </div>

                    <div class="rounded-xl bg-gray-50 px-4 py-3 sm:col-span-2">
                        <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">App URL</dt>
                        <dd class="mt-1 break-all text-sm font-medium text-gray-900">{{ $snapshot['app_url'] ?? 'n/a' }}</dd>
                    </div>

                    <div class="rounded-xl bg-gray-50 px-4 py-3">
                        <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">System uptime</dt>
                        <dd class="mt-1 text-sm font-medium text-gray-900">{{ $uptime['label'] ?? 'Not available' }}</dd>
                    </div>

                    <div class="rounded-xl bg-gray-50 px-4 py-3">
                        <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Failed jobs</dt>
                        <dd class="mt-1 text-sm font-medium text-gray-900">{{ number_format((int) ($snapshot['failed_jobs_count'] ?? 0)) }}</dd>
                    </div>

                    <div class="rounded-xl bg-gray-50 px-4 py-3 sm:col-span-2">
                        <dt class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Scheduler status</dt>
                        <dd class="mt-1 inline-flex items-center rounded-full px-3 py-1 text-sm font-medium {{ $schedulerBadgeClasses }}">
                            {{ $scheduler['status_label'] ?? 'Unavailable' }}
                        </dd>
                    </div>
                </div>
            </div>
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div class="space-y-1">
                    <div class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">Scheduler</div>
                    <h3 class="text-lg font-semibold text-gray-900">Scheduled tasks</h3>
                </div>
                <div class="text-sm text-gray-600">{{ number_format((int) ($scheduler['task_count'] ?? 0)) }} tasks</div>
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
