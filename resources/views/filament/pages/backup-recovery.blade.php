<x-filament-panels::page>
    @php
        $t        = fn(string $key, array $replace = []) => __('procynia.backup_recovery.'.$key, $replace);
        $br       = $statusSummary;
        $enabled  = (bool) ($br['enabled'] ?? false);
        $warnings = (array) ($br['warnings'] ?? []);

        $okBadge   = 'bg-success-50 text-success-700 ring-1 ring-inset ring-success-200';
        $errBadge  = 'bg-danger-50 text-danger-700 ring-1 ring-inset ring-danger-200';
        $warnBadge = 'bg-warning-50 text-warning-700 ring-1 ring-inset ring-warning-200';
        $grayBadge = 'bg-gray-100 text-gray-600 ring-1 ring-inset ring-gray-200';

        $statusColors = [
            'success' => $okBadge,
            'running' => 'bg-primary-50 text-primary-700 ring-1 ring-inset ring-primary-200',
            'failed'  => $errBadge,
            'skipped' => $grayBadge,
        ];

        $warningKeys = [
            'no_scheduler_heartbeat',
            'backup_overdue',
            'last_run_failed',
            'directory_missing',
            'no_files',
            'backup_stopped',
        ];
    @endphp

    <div class="space-y-4">

        {{-- Midlertidig notice --}}
        <div class="rounded-xl border border-warning-200 bg-warning-50 p-3 text-sm text-warning-800">
            <span class="font-semibold">{{ $t('messages.temp_notice') }}</span>
        </div>

        {{-- ① Status-kort --}}
        <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
            <div class="mb-3 text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">
                {{ $t('sections.status') }}
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">

                {{-- Backup aktivert --}}
                <div class="rounded-xl {{ $enabled ? 'bg-success-50' : 'bg-gray-50' }} px-4 py-3">
                    <div class="text-xs font-semibold uppercase tracking-[0.12em] {{ $enabled ? 'text-success-700' : 'text-gray-500' }}">
                        {{ $t('fields.backup_enabled') }}
                    </div>
                    <div class="mt-1.5 inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $enabled ? $okBadge : $errBadge }}">
                        {{ $enabled ? $t('statuses.enabled') : $t('statuses.disabled') }}
                    </div>
                </div>

                {{-- Scheduler heartbeat --}}
                <div class="rounded-xl bg-gray-50 px-4 py-3">
                    <div class="text-xs font-semibold uppercase tracking-[0.12em] text-gray-500">
                        {{ $t('fields.scheduler_heartbeat') }}
                    </div>
                    <div class="mt-1.5 text-xs font-medium text-gray-700">
                        @if ($br['last_scheduler_heartbeat_at'] ?? null)
                            <span title="{{ \Illuminate\Support\Carbon::parse($br['last_scheduler_heartbeat_at'])->format('d.m.Y H:i:s') }}">
                                {{ $br['last_scheduler_heartbeat_at_human'] }}
                            </span>
                        @else
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 {{ $errBadge }}">{{ $t('statuses.missing') }}</span>
                        @endif
                    </div>
                </div>

                {{-- Siste vellykkede backup --}}
                <div class="rounded-xl bg-gray-50 px-4 py-3">
                    <div class="text-xs font-semibold uppercase tracking-[0.12em] text-gray-500">
                        {{ $t('fields.last_success') }}
                    </div>
                    <div class="mt-1.5 text-xs font-medium text-gray-700">
                        @if ($br['last_success_at'] ?? null)
                            <span title="{{ \Illuminate\Support\Carbon::parse($br['last_success_at'])->format('d.m.Y H:i:s') }}">
                                {{ $br['last_success_at_human'] }}
                            </span>
                        @else
                            <span class="text-gray-400">{{ $t('statuses.no_backup_yet') }}</span>
                        @endif
                    </div>
                </div>

                {{-- Siste feilede backup --}}
                <div class="rounded-xl bg-gray-50 px-4 py-3">
                    <div class="text-xs font-semibold uppercase tracking-[0.12em] text-gray-500">
                        {{ $t('fields.last_failed') }}
                    </div>
                    <div class="mt-1.5 text-xs font-medium text-gray-700">
                        @if ($br['last_failed_at'] ?? null)
                            <span class="text-danger-700" title="{{ \Illuminate\Support\Carbon::parse($br['last_failed_at'])->format('d.m.Y H:i:s') }}">
                                {{ $br['last_failed_at_human'] }}
                            </span>
                        @else
                            <span class="text-gray-400">{{ $t('statuses.not_available') }}</span>
                        @endif
                    </div>
                </div>

                {{-- Backupfiler --}}
                <div class="rounded-xl bg-gray-50 px-4 py-3">
                    <div class="text-xs font-semibold uppercase tracking-[0.12em] text-gray-500">
                        {{ $t('fields.file_count') }}
                    </div>
                    <div class="mt-1.5 text-xs font-medium text-gray-700">
                        {{ count($backupFiles) }}
                    </div>
                </div>

                {{-- RPO / RTO --}}
                <div class="rounded-xl bg-gray-50 px-4 py-3">
                    <div class="text-xs font-semibold uppercase tracking-[0.12em] text-gray-500">
                        {{ $t('fields.rpo') }} / {{ $t('fields.rto') }}
                    </div>
                    <div class="mt-1.5 text-xs font-medium text-gray-700">
                        {{ $t('rpo_value') }} / {{ $t('rto_value') }}
                    </div>
                </div>

            </div>
        </section>

        {{-- ② Varsler --}}
        @if (count($warnings) > 0)
            <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <div class="mb-3 text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">
                    {{ __('procynia.system_status.sections.issues') }}
                </div>
                <div class="space-y-3">
                    @foreach ($warnings as $warningKey)
                        @php
                            $isCritical = in_array($warningKey, ['directory_missing', 'backup_overdue', 'no_scheduler_heartbeat'], true);
                        @endphp
                        <div class="rounded-xl border {{ $isCritical ? 'border-danger-200 bg-danger-50' : 'border-warning-200 bg-warning-50' }} p-3">
                            <div class="flex items-start gap-2.5">
                                <span class="mt-1 inline-block h-1.5 w-1.5 shrink-0 rounded-full {{ $isCritical ? 'bg-danger-500' : 'bg-warning-500' }}"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-semibold {{ $isCritical ? 'text-danger-900' : 'text-warning-900' }}">
                                        {{ $t('warnings.'.$warningKey) }}
                                    </p>
                                    <p class="mt-1.5 text-xs {{ $isCritical ? 'text-danger-600' : 'text-warning-600' }}">
                                        → {{ $t('warnings.'.$warningKey.'_step') }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @else
            <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <div class="mb-3 text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">
                    {{ __('procynia.system_status.sections.issues') }}
                </div>
                <div class="flex items-center gap-2 text-sm text-success-700">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 shrink-0" aria-hidden="true">
                        <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd"/>
                    </svg>
                    {{ __('procynia.system_status.statuses.no_issues') }}
                </div>
            </section>
        @endif

        {{-- ③ Siste backup-kjøringer --}}
        <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
            <div class="mb-3 text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">
                {{ $t('sections.runs') }}
            </div>

            @if (empty($recentRuns))
                <p class="text-sm text-gray-500">{{ $t('messages.no_runs') }}</p>
            @else
                <div class="overflow-x-auto rounded-lg border border-gray-100">
                    <table class="min-w-full divide-y divide-gray-100 text-xs">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left font-semibold uppercase tracking-[0.12em] text-gray-500 whitespace-nowrap">{{ $t('fields.type') }}</th>
                                <th class="px-3 py-2 text-left font-semibold uppercase tracking-[0.12em] text-gray-500 whitespace-nowrap">{{ $t('fields.status') }}</th>
                                <th class="hidden px-3 py-2 text-left font-semibold uppercase tracking-[0.12em] text-gray-500 whitespace-nowrap sm:table-cell">{{ $t('fields.started_at') }}</th>
                                <th class="hidden px-3 py-2 text-left font-semibold uppercase tracking-[0.12em] text-gray-500 whitespace-nowrap sm:table-cell">{{ $t('fields.finished_at') }}</th>
                                <th class="hidden px-3 py-2 text-left font-semibold uppercase tracking-[0.12em] text-gray-500 whitespace-nowrap md:table-cell">{{ $t('fields.duration') }}</th>
                                <th class="hidden px-3 py-2 text-left font-semibold uppercase tracking-[0.12em] text-gray-500 whitespace-nowrap lg:table-cell">{{ $t('fields.database_file') }}</th>
                                <th class="hidden px-3 py-2 text-left font-semibold uppercase tracking-[0.12em] text-gray-500 whitespace-nowrap lg:table-cell">{{ $t('fields.triggered_by') }}</th>
                                <th class="px-3 py-2 text-left font-semibold uppercase tracking-[0.12em] text-gray-500">{{ $t('fields.error_message') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @foreach ($recentRuns as $run)
                                <tr class="transition-colors hover:bg-gray-50">
                                    <td class="px-3 py-2 text-gray-700 whitespace-nowrap">{{ $run['type_label'] }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $statusColors[$run['status']] ?? $grayBadge }}">
                                            {{ $run['status_label'] }}
                                        </span>
                                    </td>
                                    <td class="hidden px-3 py-2 text-gray-600 whitespace-nowrap sm:table-cell">{{ $run['started_at'] ?? '—' }}</td>
                                    <td class="hidden px-3 py-2 text-gray-600 whitespace-nowrap sm:table-cell">{{ $run['finished_at'] ?? '—' }}</td>
                                    <td class="hidden px-3 py-2 text-gray-600 whitespace-nowrap md:table-cell">
                                        {{ $run['duration_seconds'] !== null ? $run['duration_seconds'].'s' : '—' }}
                                    </td>
                                    <td class="hidden px-3 py-2 font-mono text-gray-600 lg:table-cell" title="{{ $run['database_backup_path'] }}">
                                        {{ $run['database_backup_path'] ? \Illuminate\Support\Str::limit($run['database_backup_path'], 40) : '—' }}
                                    </td>
                                    <td class="hidden px-3 py-2 text-gray-600 whitespace-nowrap lg:table-cell">{{ $run['triggered_by'] ?? '—' }}</td>
                                    <td class="px-3 py-2 text-xs text-danger-700 max-w-xs truncate" title="{{ $run['error_message'] }}">
                                        {{ $run['error_message'] ? \Illuminate\Support\Str::limit($run['error_message'], 80) : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        {{-- ④ Backupfiler --}}
        <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
            <div class="mb-3 text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">
                {{ $t('sections.files') }}
            </div>

            @if (! ($br['directory_exists'] ?? false))
                <p class="text-sm text-warning-700">{{ $t('messages.directory_missing') }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ $br['directory'] ?? '' }}</p>
            @elseif (empty($backupFiles))
                <p class="text-sm text-gray-500">{{ $t('messages.no_files') }}</p>
            @else
                <div class="overflow-x-auto rounded-lg border border-gray-100">
                    <table class="min-w-full divide-y divide-gray-100 text-xs">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left font-semibold uppercase tracking-[0.12em] text-gray-500">{{ $t('fields.filename') }}</th>
                                <th class="hidden px-3 py-2 text-right font-semibold uppercase tracking-[0.12em] text-gray-500 whitespace-nowrap sm:table-cell">{{ $t('fields.file_size') }}</th>
                                <th class="hidden px-3 py-2 text-left font-semibold uppercase tracking-[0.12em] text-gray-500 whitespace-nowrap sm:table-cell">{{ $t('fields.last_modified') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @foreach ($backupFiles as $file)
                                <tr class="transition-colors hover:bg-gray-50">
                                    <td class="px-3 py-2 font-mono text-gray-700">{{ $file['filename'] }}</td>
                                    <td class="hidden px-3 py-2 text-right text-gray-600 whitespace-nowrap sm:table-cell">
                                        @if ($file['size_bytes'] !== null)
                                            {{ number_format((int)$file['size_bytes'] / 1024 / 1024, 1) }} MB
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="hidden px-3 py-2 text-gray-600 whitespace-nowrap sm:table-cell">{{ $file['modified_at'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 text-xs text-gray-400">{{ $br['directory'] ?? '' }}</p>
            @endif
        </section>

        {{-- ⑤ Restore --}}
        <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
            <div class="mb-3 text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">
                {{ $t('sections.restore') }}
            </div>
            <div class="rounded-xl border border-warning-200 bg-warning-50 p-3 text-sm text-warning-800">
                <p class="font-semibold">{{ $t('messages.restore_note') }}</p>
                <p class="mt-2 font-mono text-xs text-warning-700">docs/operations/backup-restore.md</p>
            </div>
        </section>

    </div>
</x-filament-panels::page>
