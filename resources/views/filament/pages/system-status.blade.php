<x-filament-panels::page>
    @php
        $db        = $snapshot['database'] ?? [];
        $redis     = $snapshot['redis'] ?? [];
        $queue     = $snapshot['queue'] ?? [];
        $scheduler = $snapshot['scheduler'] ?? [];
        $uptime    = $snapshot['uptime'] ?? [];
        $schedulerLive = [
            'progress' => __('procynia.system_status.scheduler.progress'),
            'next_run' => __('procynia.system_status.scheduler.next_run'),
            'second' => __('procynia.system_status.scheduler.second'),
            'seconds' => __('procynia.system_status.scheduler.seconds'),
            'minute' => __('procynia.system_status.scheduler.minute'),
            'minutes' => __('procynia.system_status.scheduler.minutes'),
            'hour' => __('procynia.system_status.scheduler.hour'),
            'hours' => __('procynia.system_status.scheduler.hours'),
            'day' => __('procynia.system_status.scheduler.day'),
            'days' => __('procynia.system_status.scheduler.days'),
            'in_prefix' => __('procynia.system_status.scheduler.in_prefix'),
            'now' => __('procynia.system_status.scheduler.now'),
            'unavailable' => __('procynia.system_status.scheduler.unavailable'),
        ];

        $dbOk         = (bool) ($db['available'] ?? false);
        $redisOk      = (bool) ($redis['available'] ?? false);
        $failedCount  = (int) ($snapshot['failed_jobs_count'] ?? 0);
        $schedulerHb  = ($health['scheduler'] ?? 'stale') === 'ok';
        $queueHb      = ($health['queue'] ?? 'stale') === 'ok';
        $qsOk         = (bool) ($health['ok'] ?? false);

        $backupStatus   = $snapshot['backup'] ?? [];
        $backupWarnings = (array) ($backupStatus['warnings'] ?? []);
        $backupOk       = (bool) ($backupStatus['ok'] ?? true);

        // Three severity levels: error = critical infra issue, warning = failed jobs / backup only, ok = all clear
        $criticalIssues = ! $dbOk || ! $redisOk || ! $qsOk;
        $overallStatus  = $criticalIssues ? 'error' : ($failedCount > 0 || ! $backupOk ? 'warning' : 'ok');
        $hasIssues      = $overallStatus !== 'ok';

        $okBadge   = 'bg-success-50 text-success-700 ring-1 ring-inset ring-success-200';
        $errBadge  = 'bg-danger-50 text-danger-700 ring-1 ring-inset ring-danger-200';
        $warnBadge = 'bg-warning-50 text-warning-700 ring-1 ring-inset ring-warning-200';

        $systemStatusBg    = match($overallStatus) { 'ok' => 'bg-success-50', 'warning' => 'bg-warning-50', 'error' => 'bg-danger-50' };
        $systemStatusText  = match($overallStatus) { 'ok' => 'text-success-700', 'warning' => 'text-warning-700', 'error' => 'text-danger-700' };
        $systemStatusBadge = match($overallStatus) { 'ok' => $okBadge, 'warning' => $warnBadge, 'error' => $errBadge };
        $systemStatusLabel = match($overallStatus) {
            'ok'      => __('procynia.system_status.statuses.ok'),
            'warning' => __('procynia.system_status.statuses.warning'),
            'error'   => __('procynia.system_status.statuses.error'),
        };
    @endphp

    <div class="space-y-4">

        {{-- ① Driftstatus cockpit --}}
        <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
            <div class="mb-3 text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">
                {{ __('procynia.system_status.sections.operational_status') }}
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">

                {{-- Systemstatus --}}
                <div class="rounded-xl {{ $systemStatusBg }} px-4 py-3">
                    <div class="text-xs font-semibold uppercase tracking-[0.12em] {{ $systemStatusText }}">
                        {{ __('procynia.system_status.fields.system_status') }}
                    </div>
                    <div class="mt-1.5 inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $systemStatusBadge }}">
                        {{ $systemStatusLabel }}
                    </div>
                </div>

                {{-- Database --}}
                <div class="rounded-xl bg-gray-50 px-4 py-3">
                    <div class="text-xs font-semibold uppercase tracking-[0.12em] text-gray-500">
                        {{ __('procynia.system_status.fields.database') }}
                    </div>
                    <div class="mt-1.5 inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $dbOk ? $okBadge : $errBadge }}">
                        {{ $dbOk ? __('procynia.system_status.statuses.connected') : __('procynia.system_status.statuses.not_connected') }}
                    </div>
                </div>

                {{-- Redis --}}
                <div class="rounded-xl bg-gray-50 px-4 py-3">
                    <div class="text-xs font-semibold uppercase tracking-[0.12em] text-gray-500">
                        {{ __('procynia.system_status.fields.redis') }}
                    </div>
                    <div class="mt-1.5 inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $redisOk ? $okBadge : $errBadge }}">
                        {{ $redisOk ? __('procynia.system_status.statuses.connected') : __('procynia.system_status.statuses.not_connected') }}
                    </div>
                </div>

                {{-- Queue/Scheduler --}}
                <div class="rounded-xl bg-gray-50 px-4 py-3">
                    <div class="text-xs font-semibold uppercase tracking-[0.12em] text-gray-500">
                        {{ __('procynia.system_status.fields.queue_scheduler') }}
                    </div>
                    <div class="mt-1.5 inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $qsOk ? $okBadge : $errBadge }}">
                        {{ $qsOk ? __('procynia.system_status.statuses.ok') : __('procynia.system_status.statuses.error') }}
                    </div>
                </div>

                {{-- Failed jobs --}}
                <div class="rounded-xl bg-gray-50 px-4 py-3">
                    <div class="text-xs font-semibold uppercase tracking-[0.12em] text-gray-500">
                        {{ __('procynia.system_status.fields.failed_jobs') }}
                    </div>
                    @if ($failedCount > 0)
                        <div class="mt-1.5 inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $warnBadge }}">
                            {{ number_format($failedCount) }}
                        </div>
                    @else
                        <div class="mt-1.5 inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $okBadge }}">
                            {{ __('procynia.system_status.statuses.ok') }}
                        </div>
                    @endif
                </div>

            </div>
        </section>

        {{-- ② Avvik og oppfølging (always visible) --}}
        <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm" x-data="{ showFailedJobs: false }">
            <div class="mb-3 text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">
                {{ __('procynia.system_status.sections.issues') }}
            </div>

            @if (! $hasIssues)
                {{-- All clear --}}
                <div class="flex items-center gap-2 text-sm text-success-700">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 shrink-0" aria-hidden="true">
                        <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd"/>
                    </svg>
                    {{ __('procynia.system_status.statuses.no_issues') }}
                </div>
            @else
                <div class="space-y-3">

                    {{-- Database issue (critical) --}}
                    @if (! $dbOk)
                        <div class="rounded-xl border border-danger-200 bg-danger-50 p-3">
                            <div class="flex items-start gap-2.5">
                                <span class="mt-1 inline-block h-1.5 w-1.5 shrink-0 rounded-full bg-danger-500"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-semibold text-danger-900">
                                        {{ __('procynia.system_status.fields.database') }}: {{ __('procynia.system_status.statuses.not_connected') }}
                                    </p>
                                    <p class="mt-1 text-xs text-danger-700">{{ __('procynia.system_status.issues.database.reason') }}</p>
                                    <p class="mt-1.5 text-xs text-danger-600">→ {{ __('procynia.system_status.issues.database.next_step') }}</p>
                                </div>
                            </div>
                        </div>
                    @endif

                    {{-- Redis issue (critical) --}}
                    @if (! $redisOk)
                        <div class="rounded-xl border border-danger-200 bg-danger-50 p-3">
                            <div class="flex items-start gap-2.5">
                                <span class="mt-1 inline-block h-1.5 w-1.5 shrink-0 rounded-full bg-danger-500"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-semibold text-danger-900">
                                        {{ __('procynia.system_status.fields.redis') }}: {{ __('procynia.system_status.statuses.not_connected') }}
                                    </p>
                                    <p class="mt-1 text-xs text-danger-700">{{ __('procynia.system_status.issues.redis.reason') }}</p>
                                    <p class="mt-1.5 text-xs text-danger-600">→ {{ __('procynia.system_status.issues.redis.next_step') }}</p>
                                </div>
                            </div>
                        </div>
                    @endif

                    {{-- Queue/Scheduler issue (critical) --}}
                    @if (! $schedulerHb || ! $queueHb)
                        <div class="rounded-xl border border-danger-200 bg-danger-50 p-3">
                            <div class="flex items-start gap-2.5">
                                <span class="mt-1 inline-block h-1.5 w-1.5 shrink-0 rounded-full bg-danger-500"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-semibold text-danger-900">
                                        {{ __('procynia.system_status.fields.queue_scheduler') }}: {{ __('procynia.system_status.statuses.error') }}
                                        @if (! $schedulerHb && $queueHb)
                                            ({{ __('procynia.system_status.fields.scheduler_status') }})
                                        @elseif ($schedulerHb && ! $queueHb)
                                            (Queue)
                                        @endif
                                    </p>
                                    <p class="mt-1 text-xs text-danger-700">{{ __('procynia.system_status.issues.queue_scheduler.reason') }}</p>
                                    <p class="mt-1.5 text-xs text-danger-600">→ {{ __('procynia.system_status.issues.queue_scheduler.next_step') }}</p>
                                </div>
                            </div>
                        </div>
                    @endif

                    {{-- Failed jobs (warning, expandable detail table) --}}
                    @if ($failedCount > 0)
                        <div class="rounded-xl border border-warning-200 bg-warning-50 p-3">
                            <div class="flex items-start gap-2.5">
                                <span class="mt-1 inline-block h-1.5 w-1.5 shrink-0 rounded-full bg-warning-500"></span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-start justify-between gap-2">
                                        <div>
                                            <p class="text-sm font-semibold text-warning-900">
                                                {{ __('procynia.system_status.issues.failed_jobs.title') }}: {{ number_format($failedCount) }}
                                                &mdash; {{ __('procynia.system_status.statuses.requires_follow_up') }}
                                            </p>
                                            <p class="mt-1 text-xs text-warning-700">{{ __('procynia.system_status.issues.failed_jobs.reason') }}</p>
                                        </div>
                                        <button
                                            @click="showFailedJobs = !showFailedJobs"
                                            class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-warning-300 bg-white px-2.5 py-1 text-xs font-medium text-warning-800 transition-colors hover:bg-warning-100"
                                            type="button"
                                        >
                                            <span x-show="!showFailedJobs">{{ __('procynia.system_status.fields.show_details') }}</span>
                                            <span x-show="showFailedJobs" x-cloak>{{ __('procynia.system_status.fields.hide_details') }}</span>
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor"
                                                :class="showFailedJobs ? 'rotate-180' : ''"
                                                class="h-3.5 w-3.5 transition-transform duration-150" aria-hidden="true">
                                                <path fill-rule="evenodd" d="M4.22 6.22a.75.75 0 0 1 1.06 0L8 8.94l2.72-2.72a.75.75 0 1 1 1.06 1.06l-3.25 3.25a.75.75 0 0 1-1.06 0L4.22 7.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/>
                                            </svg>
                                        </button>
                                    </div>

                                    {{-- Expandable failed jobs table --}}
                                    <div x-show="showFailedJobs" x-cloak class="mt-3 overflow-hidden rounded-lg border border-warning-200">
                                        @if (empty($failedJobs))
                                            <div class="px-4 py-3 text-xs text-warning-700">—</div>
                                        @else
                                            <table class="min-w-full divide-y divide-warning-100 text-xs">
                                                <thead class="bg-warning-100">
                                                    <tr>
                                                        <th class="px-3 py-2 text-left font-semibold uppercase tracking-[0.12em] text-warning-700">{{ __('procynia.system_status.fields.job_type') }}</th>
                                                        <th class="hidden px-3 py-2 text-left font-semibold uppercase tracking-[0.12em] text-warning-700 whitespace-nowrap sm:table-cell">Queue</th>
                                                        <th class="hidden px-3 py-2 text-left font-semibold uppercase tracking-[0.12em] text-warning-700 whitespace-nowrap sm:table-cell">{{ __('procynia.system_status.fields.connection') }}</th>
                                                        <th class="px-3 py-2 text-left font-semibold uppercase tracking-[0.12em] text-warning-700 whitespace-nowrap">{{ __('procynia.system_status.fields.failed_at') }}</th>
                                                        <th class="px-3 py-2 text-left font-semibold uppercase tracking-[0.12em] text-warning-700">{{ __('procynia.system_status.fields.error_reason') }}</th>
                                                        <th class="px-3 py-2 text-left font-semibold uppercase tracking-[0.12em] text-warning-700 whitespace-nowrap">{{ __('procynia.system_status.fields.actions') }}</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-warning-100 bg-white">
                                                    @foreach ($failedJobs as $job)
                                                        <tr class="transition-colors hover:bg-warning-50">
                                                            <td class="px-3 py-2 font-mono text-gray-800" title="{{ $job['display_name'] }}">{{ \Illuminate\Support\Str::afterLast($job['display_name'], '\\') }}</td>
                                                            <td class="hidden px-3 py-2 text-gray-600 whitespace-nowrap sm:table-cell">{{ $job['queue'] }}</td>
                                                            <td class="hidden px-3 py-2 text-gray-600 whitespace-nowrap sm:table-cell">{{ $job['connection'] }}</td>
                                                            <td class="px-3 py-2 text-gray-600 whitespace-nowrap" title="{{ $job['failed_at'] }}">{{ $job['failed_at_human'] }}</td>
                                                            <td class="px-3 py-2 text-gray-700 max-w-xs truncate" title="{{ $job['error_first_line'] }}">{{ $job['error_first_line'] }}</td>
                                                            <td class="px-3 py-2 whitespace-nowrap">
                                                                <x-filament::button
                                                                    color="danger"
                                                                    size="xs"
                                                                    icon="heroicon-m-trash"
                                                                    wire:click="promptDeleteFailedJob({{ (int) $job['id'] }})"
                                                                >
                                                                    {{ __('procynia.system_status.actions.delete_failed_job') }}
                                                                </x-filament::button>
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        @endif
                                    </div>

                                    <p class="mt-2 text-xs text-warning-600">→ {{ __('procynia.system_status.issues.failed_jobs.next_step') }}</p>
                                </div>
                            </div>
                        </div>
                    @endif

                    {{-- Backup warnings --}}
                    @foreach ($backupWarnings as $backupWarnKey)
                        @php
                            $isCriticalBackup = in_array($backupWarnKey, ['directory_missing', 'backup_overdue', 'no_scheduler_heartbeat'], true);
                        @endphp
                        <div class="rounded-xl border {{ $isCriticalBackup ? 'border-danger-200 bg-danger-50' : 'border-warning-200 bg-warning-50' }} p-3">
                            <div class="flex items-start gap-2.5">
                                <span class="mt-1 inline-block h-1.5 w-1.5 shrink-0 rounded-full {{ $isCriticalBackup ? 'bg-danger-500' : 'bg-warning-500' }}"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-semibold {{ $isCriticalBackup ? 'text-danger-900' : 'text-warning-900' }}">
                                        {{ __('procynia.backup_recovery.warnings.'.$backupWarnKey) }}
                                    </p>
                                    <p class="mt-1.5 text-xs {{ $isCriticalBackup ? 'text-danger-600' : 'text-warning-600' }}">
                                        → {{ __('procynia.backup_recovery.warnings.'.$backupWarnKey.'_step') }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    @endforeach

                </div>
            @endif
        </section>

        {{-- ③ Scheduler og planlagte oppgaver --}}
        <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm" wire:poll.60s="refreshRuntimeState">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <div class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">
                    {{ __('procynia.system_status.sections.scheduler') }}
                </div>
                <div class="flex flex-wrap items-center gap-3 text-xs">
                    <span class="font-medium text-gray-500">{{ __('procynia.system_status.fields.scheduler_status') }}:</span>
                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $schedulerHb ? $okBadge : $errBadge }}">
                        {{ $schedulerHb ? __('procynia.system_status.statuses.ok') : __('procynia.system_status.statuses.error') }}
                    </span>
                    <span class="font-medium text-gray-500">Queue:</span>
                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $queueHb ? $okBadge : $errBadge }}">
                        {{ $queueHb ? __('procynia.system_status.statuses.ok') : __('procynia.system_status.statuses.error') }}
                    </span>
                </div>
            </div>

            <div class="mb-2 text-xs font-semibold uppercase tracking-[0.12em] text-gray-400">
                {{ __('procynia.system_status.sections.scheduled_tasks') }}
            </div>

            @if (empty($scheduler['tasks']))
                <div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 px-5 py-4 text-sm text-gray-500">
                    {{ __('procynia.system_status.statuses.no_tasks') }}
                </div>
            @else
                <div class="overflow-hidden rounded-xl border border-gray-200">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-[0.12em] text-gray-500">{{ __('procynia.system_status.fields.task') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-[0.12em] text-gray-500 whitespace-nowrap">{{ __('procynia.system_status.fields.frequency') }}</th>
                                <th class="hidden px-3 py-2 text-left text-xs font-semibold uppercase tracking-[0.12em] text-gray-500 whitespace-nowrap sm:table-cell">{{ __('procynia.system_status.fields.timezone') }}</th>
                                <th class="hidden px-3 py-2 text-left text-xs font-semibold uppercase tracking-[0.12em] text-gray-500 whitespace-nowrap sm:table-cell">{{ __('procynia.system_status.fields.mutex') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-[0.12em] text-gray-500 whitespace-nowrap">{{ __('procynia.system_status.fields.next_run') }}</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-[0.12em] text-gray-500 whitespace-nowrap">{{ __('procynia.system_status.scheduler.progress') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @foreach ($scheduler['tasks'] as $task)
                                @php
                                    $progressRatio = isset($task['progress_ratio']) && $task['progress_ratio'] !== null ? (float) $task['progress_ratio'] : null;
                                    $progressAvailable = $progressRatio !== null && (int) ($task['cycle_duration_seconds'] ?? 0) > 0 && filled($task['previous_run_at_iso'] ?? null) && filled($task['next_run_at_iso'] ?? null);
                                    $strokeOffset = $progressAvailable ? number_format((1 - $progressRatio) * 87.96, 2, '.', '') : '87.96';
                                    $progressStrokeClass = $progressAvailable ? 'stroke-emerald-500' : 'stroke-gray-300';
                                @endphp
                                <tr
                                    class="transition-colors hover:bg-gray-50"
                                    data-scheduler-task-row
                                    data-scheduler-previous-run-at="{{ $task['previous_run_at_iso'] ?? '' }}"
                                    data-scheduler-next-run-at="{{ $task['next_run_at_iso'] ?? '' }}"
                                    data-scheduler-cycle-duration-seconds="{{ $task['cycle_duration_seconds'] ?? 0 }}"
                                    data-scheduler-progress-ratio="{{ $task['progress_ratio'] ?? '' }}"
                                >
                                    <td class="px-3 py-2" style="font-size: 0.75rem;">
                                        <div class="font-medium text-gray-900">{{ $task['task_name'] ?: $task['command'] }}</div>
                                        @if (($task['task_name'] ?? '') !== ($task['command'] ?? ''))
                                            <code class="mt-1 block rounded bg-gray-100 px-1.5 py-0.5 font-mono text-[0.72rem] text-gray-600">{{ $task['command'] }}</code>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap">
                                        <code class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-xs text-gray-700">{{ $task['expression'] }}</code>
                                    </td>
                                    <td class="hidden px-3 py-2 text-xs text-gray-600 whitespace-nowrap sm:table-cell">{{ $task['timezone'] }}</td>
                                    <td class="hidden px-3 py-2 text-xs text-gray-600 whitespace-nowrap sm:table-cell">
                                        {{ $task['has_mutex'] ? __('procynia.system_status.statuses.yes') : __('procynia.system_status.statuses.no') }}
                                    </td>
                                    <td class="px-3 py-2 text-xs text-gray-600 whitespace-nowrap">
                                        <span data-scheduler-countdown>{{ $task['next_run_at_human'] !== '' ? $task['next_run_at_human'] : '—' }}</span>
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap text-right">
                                        <div class="inline-flex items-center justify-end">
                                            <div
                                                class="relative h-8 w-8"
                                                title="{{ __('procynia.system_status.scheduler.progress') }}"
                                            >
                                                <svg viewBox="0 0 36 36" class="h-8 w-8 -rotate-90">
                                                    <circle cx="18" cy="18" r="14" fill="none" class="stroke-gray-200" stroke-width="3"></circle>
                                                    <circle
                                                        data-scheduler-progress-ring
                                                        cx="18"
                                                        cy="18"
                                                        r="14"
                                                        fill="none"
                                                        class="{{ $progressStrokeClass }} transition-[stroke-dashoffset] duration-500"
                                                        stroke-linecap="round"
                                                        stroke-width="3"
                                                        style="stroke-dasharray: 87.96; stroke-dashoffset: {{ $strokeOffset }};"
                                                    ></circle>
                                                </svg>
                                                <span class="sr-only" data-scheduler-progress-label>
                                                    {{ $progressAvailable ? number_format($progressRatio * 100, 0) . '%' : __('procynia.system_status.scheduler.unavailable') }}
                                                </span>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        {{-- ④ Infrastruktur --}}
        <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
            <div class="mb-3 text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">
                {{ __('procynia.system_status.sections.infrastructure') }}
            </div>
            <div class="grid gap-4 sm:grid-cols-2">

                {{-- Database --}}
                <div class="rounded-xl border border-gray-200 bg-gray-50 p-3">
                    <div class="mb-2 flex items-center justify-between">
                        <dt class="text-xs font-semibold uppercase tracking-[0.12em] text-gray-500">{{ __('procynia.system_status.fields.database_connection') }}</dt>
                        <dd>
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $dbOk ? $okBadge : $errBadge }}">
                                {{ $dbOk ? __('procynia.system_status.statuses.connected') : __('procynia.system_status.statuses.not_connected') }}
                            </span>
                        </dd>
                    </div>
                    <dl class="space-y-1 text-xs text-gray-700">
                        <div class="flex items-center justify-between gap-4">
                            <dt class="font-medium text-gray-500">{{ __('procynia.system_status.fields.connection') }}</dt>
                            <dd class="text-gray-900">{{ $db['connection'] ?? 'n/a' }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="font-medium text-gray-500">{{ __('procynia.system_status.fields.driver') }}</dt>
                            <dd class="text-gray-900">{{ $db['driver'] ?? 'n/a' }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="font-medium text-gray-500">{{ __('procynia.system_status.fields.database') }}</dt>
                            <dd class="text-gray-900">{{ $db['database'] ?? 'n/a' }}</dd>
                        </div>
                    </dl>
                    @if (! empty($db['error_message']))
                        <p class="mt-2 text-xs text-danger-700">{{ $db['error_message'] }}</p>
                    @endif
                </div>

                {{-- Redis --}}
                <div class="rounded-xl border border-gray-200 bg-gray-50 p-3">
                    <div class="mb-2 flex items-center justify-between">
                        <dt class="text-xs font-semibold uppercase tracking-[0.12em] text-gray-500">{{ __('procynia.system_status.fields.redis_connection') }}</dt>
                        <dd>
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $redisOk ? $okBadge : $errBadge }}">
                                {{ $redisOk ? __('procynia.system_status.statuses.connected') : __('procynia.system_status.statuses.not_connected') }}
                            </span>
                        </dd>
                    </div>
                    <dl class="space-y-1 text-xs text-gray-700">
                        <div class="flex items-center justify-between gap-4">
                            <dt class="font-medium text-gray-500">{{ __('procynia.system_status.fields.connection') }}</dt>
                            <dd class="text-gray-900">{{ $redis['connection'] ?? 'n/a' }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="font-medium text-gray-500">{{ __('procynia.system_status.fields.host') }}</dt>
                            <dd class="text-gray-900">{{ $redis['host'] ?? 'n/a' }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="font-medium text-gray-500">{{ __('procynia.system_status.fields.database') }}</dt>
                            <dd class="text-gray-900">{{ $redis['database'] ?? 'n/a' }}</dd>
                        </div>
                    </dl>
                    @if (! empty($redis['error_message']))
                        <p class="mt-2 text-xs text-danger-700">{{ $redis['error_message'] }}</p>
                    @endif
                </div>

            </div>
        </section>

        <script>
            (() => {
                const schedulerConfig = @js($schedulerLive);
                const circumference = 87.96;
                const tickIntervalMs = 1000;
                const rowSelector = '[data-scheduler-task-row]';

                const formatCount = (value, singular, plural) => `${value} ${value === 1 ? singular : plural}`;

                const formatCountdown = (remainingMs) => {
                    const seconds = Math.max(0, Math.ceil(remainingMs / 1000));

                    if (seconds === 0) {
                        return schedulerConfig.now;
                    }

                    if (seconds < 60) {
                        return `${schedulerConfig.in_prefix} ${formatCount(seconds, schedulerConfig.second, schedulerConfig.seconds)}`;
                    }

                    const minutes = Math.ceil(seconds / 60);

                    if (seconds < 3600) {
                        return `${schedulerConfig.in_prefix} ${formatCount(minutes, schedulerConfig.minute, schedulerConfig.minutes)}`;
                    }

                    const hours = Math.ceil(seconds / 3600);

                    if (seconds < 86400) {
                        return `${schedulerConfig.in_prefix} ${formatCount(hours, schedulerConfig.hour, schedulerConfig.hours)}`;
                    }

                    const days = Math.ceil(seconds / 86400);

                    return `${schedulerConfig.in_prefix} ${formatCount(days, schedulerConfig.day, schedulerConfig.days)}`;
                };

                const parseDate = (value) => {
                    if (!value) {
                        return null;
                    }

                    const timestamp = Date.parse(value);

                    return Number.isNaN(timestamp) ? null : timestamp;
                };

                const updateRow = (row) => {
                    const nextRunAttr = row.dataset.schedulerNextRunAt || '';
                    const previousRunAttr = row.dataset.schedulerPreviousRunAt || '';
                    const cycleSeconds = Number(row.dataset.schedulerCycleDurationSeconds || 0);
                    const countdownEl = row.querySelector('[data-scheduler-countdown]');
                    const progressRing = row.querySelector('[data-scheduler-progress-ring]');
                    const progressLabel = row.querySelector('[data-scheduler-progress-label]');

                    const nextRunAt = parseDate(nextRunAttr);
                    let previousRunAt = parseDate(previousRunAttr);
                    const hasProgressData = cycleSeconds > 0 && previousRunAt !== null;

                    if (nextRunAt === null) {
                        if (countdownEl) {
                            countdownEl.textContent = schedulerConfig.unavailable;
                        }

                        if (progressRing) {
                            progressRing.style.strokeDashoffset = String(circumference);
                            progressRing.classList.remove('stroke-emerald-500');
                            progressRing.classList.add('stroke-gray-300');
                        }

                        if (progressLabel) {
                            progressLabel.textContent = schedulerConfig.unavailable;
                        }

                        return;
                    }

                    let normalizedNextRunAt = nextRunAt;
                    const nowMs = Date.now();

                    if (cycleSeconds > 0) {
                        while (nowMs >= normalizedNextRunAt) {
                            previousRunAt = normalizedNextRunAt;
                            normalizedNextRunAt += cycleSeconds * 1000;
                        }
                    }

                    const remainingMs = normalizedNextRunAt - nowMs;
                    const countdownText = formatCountdown(remainingMs);

                    if (countdownEl) {
                        countdownEl.textContent = countdownText;
                    }

                    if (progressRing) {
                        let progressRatio = 0;

                        if (hasProgressData) {
                            progressRatio = Math.max(0, Math.min(1, (nowMs - previousRunAt) / (cycleSeconds * 1000)));
                        }

                        const offset = circumference * (1 - progressRatio);
                        progressRing.style.strokeDasharray = String(circumference);
                        progressRing.style.strokeDashoffset = String(offset);
                        if (hasProgressData) {
                            progressRing.classList.remove('stroke-gray-300');
                            progressRing.classList.add('stroke-emerald-500');
                        } else {
                            progressRing.classList.remove('stroke-emerald-500');
                            progressRing.classList.add('stroke-gray-300');
                        }
                        progressRing.setAttribute(
                            'aria-label',
                            hasProgressData
                                ? `${schedulerConfig.progress}: ${Math.round(progressRatio * 100)}%`
                                : schedulerConfig.unavailable,
                        );

                        if (progressLabel) {
                            progressLabel.textContent = hasProgressData
                                ? `${schedulerConfig.progress}: ${Math.round(progressRatio * 100)}%`
                                : schedulerConfig.unavailable;
                        }
                    }
                };

                const tick = () => {
                    document.querySelectorAll(rowSelector).forEach(updateRow);
                };

                const start = () => {
                    tick();

                    if (window.__procyniaSchedulerTickInterval) {
                        return;
                    }

                    window.__procyniaSchedulerTickInterval = window.setInterval(tick, tickIntervalMs);
                };

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', start, { once: true });
                } else {
                    start();
                }
            })();
        </script>

        {{-- ⑤ Teknisk miljø --}}
        <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
            <div class="mb-3 text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">
                {{ __('procynia.system_status.sections.technical_environment') }}
            </div>
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-lg bg-gray-50 px-3 py-2">
                    <dt class="text-xs font-semibold uppercase tracking-[0.12em] text-gray-500">{{ __('procynia.system_status.fields.app_env') }}</dt>
                    <dd class="mt-0.5 text-sm font-medium text-gray-900">{{ $snapshot['app_env'] ?? 'n/a' }}</dd>
                </div>
                <div class="rounded-lg bg-gray-50 px-3 py-2">
                    <dt class="text-xs font-semibold uppercase tracking-[0.12em] text-gray-500">{{ __('procynia.system_status.fields.app_debug') }}</dt>
                    <dd class="mt-0.5 text-sm font-medium text-gray-900">{{ ($snapshot['app_debug'] ?? false) ? 'Enabled' : 'Disabled' }}</dd>
                </div>
                <div class="rounded-lg bg-gray-50 px-3 py-2">
                    <dt class="text-xs font-semibold uppercase tracking-[0.12em] text-gray-500">{{ __('procynia.system_status.fields.laravel_version') }}</dt>
                    <dd class="mt-0.5 text-sm font-medium text-gray-900">{{ $snapshot['laravel_version'] ?? 'n/a' }}</dd>
                </div>
                <div class="rounded-lg bg-gray-50 px-3 py-2">
                    <dt class="text-xs font-semibold uppercase tracking-[0.12em] text-gray-500">{{ __('procynia.system_status.fields.php_version') }}</dt>
                    <dd class="mt-0.5 text-sm font-medium text-gray-900">{{ $snapshot['php_version'] ?? 'n/a' }}</dd>
                </div>
                <div class="rounded-lg bg-gray-50 px-3 py-2">
                    <dt class="text-xs font-semibold uppercase tracking-[0.12em] text-gray-500">{{ __('procynia.system_status.fields.queue_driver') }}</dt>
                    <dd class="mt-0.5 text-sm font-medium text-gray-900">{{ $queue['driver'] ?? 'n/a' }}</dd>
                </div>
                <div class="rounded-lg bg-gray-50 px-3 py-2">
                    <dt class="text-xs font-semibold uppercase tracking-[0.12em] text-gray-500">{{ __('procynia.system_status.fields.queue_connection') }}</dt>
                    <dd class="mt-0.5 text-sm font-medium text-gray-900">{{ $queue['connection'] ?? 'n/a' }}</dd>
                </div>
                <div class="rounded-lg bg-gray-50 px-3 py-2">
                    <dt class="text-xs font-semibold uppercase tracking-[0.12em] text-gray-500">{{ __('procynia.system_status.fields.cache_driver') }}</dt>
                    <dd class="mt-0.5 text-sm font-medium text-gray-900">{{ $snapshot['cache_driver'] ?? 'n/a' }}</dd>
                </div>
                <div class="rounded-lg bg-gray-50 px-3 py-2">
                    <dt class="text-xs font-semibold uppercase tracking-[0.12em] text-gray-500">{{ __('procynia.system_status.fields.session_driver') }}</dt>
                    <dd class="mt-0.5 text-sm font-medium text-gray-900">{{ $snapshot['session_driver'] ?? 'n/a' }}</dd>
                </div>
                <div class="rounded-lg bg-gray-50 px-3 py-2 sm:col-span-2">
                    <dt class="text-xs font-semibold uppercase tracking-[0.12em] text-gray-500">{{ __('procynia.system_status.fields.app_url') }}</dt>
                    <dd class="mt-0.5 break-all text-sm font-medium text-gray-900">{{ $snapshot['app_url'] ?? 'n/a' }}</dd>
                </div>
                <div class="rounded-lg bg-gray-50 px-3 py-2 sm:col-span-2">
                    <dt class="text-xs font-semibold uppercase tracking-[0.12em] text-gray-500">{{ __('procynia.system_status.fields.system_uptime') }}</dt>
                    <dd class="mt-0.5 text-sm font-medium text-gray-900">
                        {{ ($uptime['available'] ?? false) ? ($uptime['label'] ?? '') : __('procynia.system_status.statuses.not_available') }}
                    </dd>
                </div>
            </div>
        </section>

    </div>
</x-filament-panels::page>
