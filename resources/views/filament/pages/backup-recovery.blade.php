<x-filament-panels::page>
    @php
        $t        = fn(string $key, array $replace = []) => __('procynia.backup_recovery.'.$key, $replace);
        $br       = $statusSummary;
        $enabled  = (bool) ($br['enabled'] ?? false);
        $warnings = (array) ($br['warnings'] ?? []);

        $okBadge   = 'bg-success-50 dark:bg-success-500/10 text-success-700 dark:text-success-400 ring-1 ring-inset ring-success-200 dark:ring-success-500/30';
        $errBadge  = 'bg-danger-50 dark:bg-danger-500/10 text-danger-700 dark:text-danger-400 ring-1 ring-inset ring-danger-200 dark:ring-danger-500/30';
        $warnBadge = 'bg-warning-50 dark:bg-warning-500/10 text-warning-700 dark:text-warning-400 ring-1 ring-inset ring-warning-200 dark:ring-warning-500/30';
        $grayBadge = 'bg-gray-100 dark:bg-white/10 text-gray-600 dark:text-gray-400 ring-1 ring-inset ring-gray-200 dark:ring-white/10';

        $statusColors = [
            'success' => $okBadge,
            'running' => 'bg-primary-50 dark:bg-primary-500/10 text-primary-700 dark:text-primary-400 ring-1 ring-inset ring-primary-200 dark:ring-primary-500/30',
            'failed'  => $errBadge,
            'skipped' => $grayBadge,
        ];

        $severityCritical = \App\Filament\Support\AdminText::get('admin_a11y.severity_critical', 'Kritisk');
        $severityWarning  = \App\Filament\Support\AdminText::get('admin_a11y.severity_warning', 'Advarsel');

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
        <div class="rounded-xl border border-warning-200 dark:border-warning-500/30 bg-warning-50 dark:bg-warning-500/10 p-3 text-base leading-6 text-warning-800 dark:text-warning-300">
            <span class="font-semibold">{{ $t('messages.temp_notice') }}</span>
        </div>

        {{-- ① Status-kort --}}
        <section class="rounded-2xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 p-4 shadow-sm">
            <h2 class="mb-3 text-base font-semibold uppercase tracking-[0.16em] text-gray-600 dark:text-gray-400">
                {{ $t('sections.status') }}
            </h2>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">

                {{-- Backup aktivert --}}
                <div class="rounded-xl {{ $enabled ? 'bg-success-50 dark:bg-success-500/10' : 'bg-gray-50 dark:bg-white/5' }} px-4 py-3">
                    <div class="text-base font-semibold uppercase tracking-[0.12em] {{ $enabled ? 'text-success-700 dark:text-success-400' : 'text-gray-600 dark:text-gray-400' }}">
                        {{ $t('fields.backup_enabled') }}
                    </div>
                    <div class="mt-1.5 inline-flex items-center rounded-full px-2.5 py-1 text-base leading-6 font-medium {{ $enabled ? $okBadge : $errBadge }}">
                        {{ $enabled ? $t('statuses.enabled') : $t('statuses.disabled') }}
                    </div>
                </div>

                {{-- Scheduler heartbeat --}}
                <div class="rounded-xl bg-gray-50 dark:bg-white/5 px-4 py-3">
                    <div class="text-base font-semibold uppercase tracking-[0.12em] text-gray-600 dark:text-gray-400">
                        {{ $t('fields.scheduler_heartbeat') }}
                    </div>
                    <div class="mt-1.5 text-base leading-6 font-medium text-gray-700 dark:text-gray-200">
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
                <div class="rounded-xl bg-gray-50 dark:bg-white/5 px-4 py-3">
                    <div class="text-base font-semibold uppercase tracking-[0.12em] text-gray-600 dark:text-gray-400">
                        {{ $t('fields.last_success') }}
                    </div>
                    <div class="mt-1.5 text-base leading-6 font-medium text-gray-700 dark:text-gray-200">
                        @if ($br['last_success_at'] ?? null)
                            <span title="{{ \Illuminate\Support\Carbon::parse($br['last_success_at'])->format('d.m.Y H:i:s') }}">
                                {{ $br['last_success_at_human'] }}
                            </span>
                        @else
                            <span class="text-gray-600 dark:text-gray-400">{{ $t('statuses.no_backup_yet') }}</span>
                        @endif
                    </div>
                </div>

                {{-- Siste feilede backup --}}
                <div class="rounded-xl bg-gray-50 dark:bg-white/5 px-4 py-3">
                    <div class="text-base font-semibold uppercase tracking-[0.12em] text-gray-600 dark:text-gray-400">
                        {{ $t('fields.last_failed') }}
                    </div>
                    <div class="mt-1.5 text-base leading-6 font-medium text-gray-700 dark:text-gray-200">
                        @if ($br['last_failed_at'] ?? null)
                            <span class="text-danger-700 dark:text-danger-400" title="{{ \Illuminate\Support\Carbon::parse($br['last_failed_at'])->format('d.m.Y H:i:s') }}">
                                {{ $br['last_failed_at_human'] }}
                            </span>
                        @else
                            <span class="text-gray-600 dark:text-gray-400">{{ $t('statuses.not_available') }}</span>
                        @endif
                    </div>
                </div>

                {{-- Backupfiler --}}
                <div class="rounded-xl bg-gray-50 dark:bg-white/5 px-4 py-3">
                    <div class="text-base font-semibold uppercase tracking-[0.12em] text-gray-600 dark:text-gray-400">
                        {{ $t('fields.file_count') }}
                    </div>
                    <div class="mt-1.5 text-base leading-6 font-medium text-gray-700 dark:text-gray-200">
                        {{ count($backupFiles) }}
                    </div>
                </div>

                {{-- RPO / RTO --}}
                <div class="rounded-xl bg-gray-50 dark:bg-white/5 px-4 py-3">
                    <div class="text-base font-semibold uppercase tracking-[0.12em] text-gray-600 dark:text-gray-400">
                        {{ $t('fields.rpo') }} / {{ $t('fields.rto') }}
                    </div>
                    <div class="mt-1.5 text-base leading-6 font-medium text-gray-700 dark:text-gray-200">
                        {{ $t('rpo_value') }} / {{ $t('rto_value') }}
                    </div>
                </div>

            </div>
        </section>

        {{-- ② Varsler --}}
        @if (count($warnings) > 0)
            <section class="rounded-2xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 p-4 shadow-sm">
                <h2 class="mb-3 text-base font-semibold uppercase tracking-[0.16em] text-gray-600 dark:text-gray-400">
                    {{ __('procynia.system_status.sections.issues') }}
                </h2>
                <div class="space-y-3">
                    @foreach ($warnings as $warningKey)
                        @php
                            $isCritical = in_array($warningKey, ['directory_missing', 'backup_overdue', 'no_scheduler_heartbeat'], true);
                        @endphp
                        <div class="rounded-xl border {{ $isCritical ? 'border-danger-200 dark:border-danger-500/30 bg-danger-50 dark:bg-danger-500/10' : 'border-warning-200 dark:border-warning-500/30 bg-warning-50 dark:bg-warning-500/10' }} p-3">
                            <div class="flex items-start gap-2.5">
                                <span class="mt-1 inline-block h-1.5 w-1.5 shrink-0 rounded-full {{ $isCritical ? 'bg-danger-500' : 'bg-warning-500' }}" aria-hidden="true"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-base leading-6 font-semibold {{ $isCritical ? 'text-danger-900 dark:text-danger-300' : 'text-warning-900 dark:text-warning-300' }}">
                                        {{-- A11Y-125: the severity is said in words, not only by the card colour. --}}
                                        <span data-backup-warning-severity="{{ $isCritical ? 'critical' : 'warning' }}">{{ $isCritical ? $severityCritical : $severityWarning }}:</span>
                                        {{ $t('warnings.'.$warningKey) }}
                                    </p>
                                    <p class="mt-1.5 text-base leading-6 {{ $isCritical ? 'text-danger-700 dark:text-danger-400' : 'text-warning-800 dark:text-warning-300' }}">
                                        → {{ $t('warnings.'.$warningKey.'_step') }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @else
            <section class="rounded-2xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 p-4 shadow-sm">
                <h2 class="mb-3 text-base font-semibold uppercase tracking-[0.16em] text-gray-600 dark:text-gray-400">
                    {{ __('procynia.system_status.sections.issues') }}
                </h2>
                <div class="flex items-center gap-2 text-base text-success-700 dark:text-success-400">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 shrink-0" aria-hidden="true">
                        <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd"/>
                    </svg>
                    {{ __('procynia.system_status.statuses.no_issues') }}
                </div>
            </section>
        @endif

        {{-- ③ Siste backup-kjøringer --}}
        <section class="rounded-2xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 p-4 shadow-sm">
            <h2 id="backup-runs-heading" class="mb-3 text-base font-semibold uppercase tracking-[0.16em] text-gray-600 dark:text-gray-400">
                {{ $t('sections.runs') }}
            </h2>

            @if (empty($recentRuns))
                <p class="text-base text-gray-600 dark:text-gray-400">{{ $t('messages.no_runs') }}</p>
            @else
                <div class="relative overflow-x-auto rounded-lg border border-gray-100 dark:border-white/10" @include('filament.components.scroll-region-attrs', ['labelledby' => 'backup-runs-heading'])>
                    <table aria-labelledby="backup-runs-heading" class="min-w-full divide-y divide-gray-100 dark:divide-white/10 text-base">
                        <thead class="bg-gray-50 dark:bg-white/5">
                            <tr>
                                <th scope="col" class="px-3 py-2 text-left font-semibold uppercase tracking-[0.12em] text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $t('fields.type') }}</th>
                                <th scope="col" class="px-3 py-2 text-left font-semibold uppercase tracking-[0.12em] text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $t('fields.status') }}</th>
                                <th scope="col" class="hidden px-3 py-2 text-left font-semibold uppercase tracking-[0.12em] text-gray-600 dark:text-gray-400 whitespace-nowrap sm:table-cell">{{ $t('fields.started_at') }}</th>
                                <th scope="col" class="hidden px-3 py-2 text-left font-semibold uppercase tracking-[0.12em] text-gray-600 dark:text-gray-400 whitespace-nowrap sm:table-cell">{{ $t('fields.finished_at') }}</th>
                                <th scope="col" class="hidden px-3 py-2 text-left font-semibold uppercase tracking-[0.12em] text-gray-600 dark:text-gray-400 whitespace-nowrap md:table-cell">{{ $t('fields.duration') }}</th>
                                <th scope="col" class="hidden px-3 py-2 text-left font-semibold uppercase tracking-[0.12em] text-gray-600 dark:text-gray-400 whitespace-nowrap lg:table-cell">{{ $t('fields.database_file') }}</th>
                                <th scope="col" class="hidden px-3 py-2 text-left font-semibold uppercase tracking-[0.12em] text-gray-600 dark:text-gray-400 whitespace-nowrap lg:table-cell">{{ $t('fields.triggered_by') }}</th>
                                <th scope="col" class="px-3 py-2 text-left font-semibold uppercase tracking-[0.12em] text-gray-600 dark:text-gray-400">{{ $t('fields.error_message') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/10 bg-white dark:bg-gray-900">
                            @foreach ($recentRuns as $run)
                                <tr class="transition-colors hover:bg-gray-50 dark:hover:bg-white/5">
                                    <td class="px-3 py-2 text-gray-700 dark:text-gray-200 whitespace-nowrap">{{ $run['type_label'] }}</td>
                                    <td class="px-3 py-2 whitespace-nowrap">
                                        <span class="inline-flex items-center rounded-full px-2 py-1 text-base leading-6 font-medium {{ $statusColors[$run['status']] ?? $grayBadge }}">
                                            {{ $run['status_label'] }}
                                        </span>
                                    </td>
                                    <td class="hidden px-3 py-2 text-gray-600 dark:text-gray-400 whitespace-nowrap sm:table-cell">{{ $run['started_at'] ?? '—' }}</td>
                                    <td class="hidden px-3 py-2 text-gray-600 dark:text-gray-400 whitespace-nowrap sm:table-cell">{{ $run['finished_at'] ?? '—' }}</td>
                                    <td class="hidden px-3 py-2 text-gray-600 dark:text-gray-400 whitespace-nowrap md:table-cell">
                                        {{ $run['duration_seconds'] !== null ? $run['duration_seconds'].'s' : '—' }}
                                    </td>
                                    <td class="hidden px-3 py-2 font-mono text-gray-600 dark:text-gray-400 lg:table-cell" title="{{ $run['database_backup_path'] }}">
                                        {{ $run['database_backup_path'] ? \Illuminate\Support\Str::limit($run['database_backup_path'], 40) : '—' }}
                                    </td>
                                    <td class="hidden px-3 py-2 text-gray-600 dark:text-gray-400 whitespace-nowrap lg:table-cell">{{ $run['triggered_by'] ?? '—' }}</td>
                                    <td class="px-3 py-2 text-base leading-6 text-danger-700 dark:text-danger-400 max-w-xs truncate" title="{{ $run['error_message'] }}">
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
        <section class="rounded-2xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 p-4 shadow-sm">
            <h2 id="backup-files-heading" class="mb-3 text-base font-semibold uppercase tracking-[0.16em] text-gray-600 dark:text-gray-400">
                {{ $t('sections.files') }}
            </h2>

            @if (! ($br['directory_exists'] ?? false))
                <p class="text-base leading-6 text-warning-800 dark:text-warning-300">{{ $t('messages.directory_missing') }}</p>
                <p class="mt-1 text-base leading-6 text-gray-600 dark:text-gray-400">{{ $br['directory'] ?? '' }}</p>
            @elseif (empty($backupFiles))
                <p class="text-base text-gray-600 dark:text-gray-400">{{ $t('messages.no_files') }}</p>
            @else
                <div class="relative overflow-x-auto rounded-lg border border-gray-100 dark:border-white/10" @include('filament.components.scroll-region-attrs', ['labelledby' => 'backup-files-heading'])>
                    <table aria-labelledby="backup-files-heading" class="min-w-full divide-y divide-gray-100 dark:divide-white/10 text-base">
                        <thead class="bg-gray-50 dark:bg-white/5">
                            <tr>
                                <th scope="col" class="px-3 py-2 text-left font-semibold uppercase tracking-[0.12em] text-gray-600 dark:text-gray-400">{{ $t('fields.filename') }}</th>
                                <th scope="col" class="hidden px-3 py-2 text-right font-semibold uppercase tracking-[0.12em] text-gray-600 dark:text-gray-400 whitespace-nowrap sm:table-cell">{{ $t('fields.file_size') }}</th>
                                <th scope="col" class="hidden px-3 py-2 text-left font-semibold uppercase tracking-[0.12em] text-gray-600 dark:text-gray-400 whitespace-nowrap sm:table-cell">{{ $t('fields.last_modified') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/10 bg-white dark:bg-gray-900">
                            @foreach ($backupFiles as $file)
                                <tr class="transition-colors hover:bg-gray-50 dark:hover:bg-white/5">
                                    <td class="px-3 py-2 font-mono text-gray-700 dark:text-gray-200">{{ $file['filename'] }}</td>
                                    <td class="hidden px-3 py-2 text-right text-gray-600 dark:text-gray-400 whitespace-nowrap sm:table-cell">
                                        @if ($file['size_bytes'] !== null)
                                            {{ number_format((int)$file['size_bytes'] / 1024 / 1024, 1) }} MB
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="hidden px-3 py-2 text-gray-600 dark:text-gray-400 whitespace-nowrap sm:table-cell">{{ $file['modified_at'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 text-base leading-6 text-gray-600 dark:text-gray-400">{{ $br['directory'] ?? '' }}</p>
            @endif
        </section>

        {{-- ⑤ Restore --}}
        <section class="rounded-2xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 p-4 shadow-sm">
            <h2 class="mb-3 text-base font-semibold uppercase tracking-[0.16em] text-gray-600 dark:text-gray-400">
                {{ $t('sections.restore') }}
            </h2>
            <div class="rounded-xl border border-warning-200 dark:border-warning-500/30 bg-warning-50 dark:bg-warning-500/10 p-3 text-base leading-6 text-warning-800 dark:text-warning-300">
                <p class="font-semibold">{{ $t('messages.restore_note') }}</p>
                <p class="mt-2 font-mono text-base text-warning-800 dark:text-warning-300">docs/operations/backup-restore.md</p>
            </div>
        </section>

    </div>
</x-filament-panels::page>
