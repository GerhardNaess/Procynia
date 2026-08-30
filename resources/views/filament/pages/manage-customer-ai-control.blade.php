<x-filament-panels::page>
    @if ($globalStopActive)
        <div class="rounded-xl border border-danger-300 bg-danger-50 p-4 text-danger-900 dark:border-danger-700 dark:bg-danger-950 dark:text-danger-200">
            <p class="font-semibold">{{ __('procynia.ai_admin.global.banner_title') }}</p>
            <p class="mt-1 text-base leading-6">{{ __('procynia.ai_admin.global.banner_body') }}</p>
        </div>
    @endif

    <section class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
        <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">
            {{ __('procynia.ai_admin.sections.quota') }}
        </h2>
        <p class="mt-1 text-base leading-6 text-gray-600 dark:text-gray-400">
            {{ __('procynia.ai_admin.sections.quota_help') }}
        </p>

        <dl class="mt-5 grid grid-cols-1 gap-x-8 gap-y-3 text-base sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <dt class="text-gray-600 dark:text-gray-400">{{ __('procynia.ai_admin.fields.plan') }}</dt>
                <dd class="font-medium text-gray-900 dark:text-gray-100">{{ $record->planName() }}</dd>
            </div>
            <div>
                <dt class="text-gray-600 dark:text-gray-400">{{ __('procynia.ai_admin.fields.quota_type') }}</dt>
                <dd class="font-medium text-gray-900 dark:text-gray-100">
                    {{ __('procynia.ai_admin.quota_types.' . ($quota['quota_type'] ?? 'none')) }}
                </dd>
            </div>
            <div>
                <dt class="text-gray-600 dark:text-gray-400">{{ __('procynia.ai_admin.fields.status') }}</dt>
                <dd>
                    <span @class([
                        'inline-flex items-center rounded-full px-2 py-1 text-base font-medium leading-6',
                        'bg-success-100 text-success-800' => ($quota['status'] ?? '') === 'normal',
                        'bg-warning-100 text-warning-800' => in_array($quota['status'] ?? '', ['warning', 'critical'], true),
                        'bg-danger-100 text-danger-800' => in_array($quota['status'] ?? '', ['exhausted', 'suspended'], true),
                    ])>
                        {{ __('procynia.ai_quota.statuses.' . ($quota['status'] ?? 'normal')) }}
                    </span>
                </dd>
            </div>
            <div>
                <dt class="text-gray-600 dark:text-gray-400">{{ __('procynia.ai_admin.fields.included') }}</dt>
                <dd class="font-medium text-gray-900 dark:text-gray-100">
                    {{ ($quota['is_unlimited'] ?? false) ? __('procynia.ai_quota.unlimited') : (int) ($quota['included'] ?? 0) }}
                </dd>
            </div>
            <div>
                <dt class="text-gray-600 dark:text-gray-400">{{ __('procynia.ai_admin.fields.extra') }}</dt>
                <dd class="font-medium text-gray-900 dark:text-gray-100">{{ (int) ($quota['extra'] ?? 0) }}</dd>
            </div>
            <div>
                <dt class="text-gray-600 dark:text-gray-400">{{ __('procynia.ai_admin.fields.used') }}</dt>
                <dd class="font-medium text-gray-900 dark:text-gray-100">{{ (int) ($quota['used'] ?? 0) }}</dd>
            </div>
            <div>
                <dt class="text-gray-600 dark:text-gray-400">{{ __('procynia.ai_admin.fields.reserved') }}</dt>
                <dd class="font-medium text-gray-900 dark:text-gray-100">{{ (int) ($quota['reserved'] ?? 0) }}</dd>
            </div>
            <div>
                <dt class="text-gray-600 dark:text-gray-400">{{ __('procynia.ai_admin.fields.remaining') }}</dt>
                <dd class="font-medium text-gray-900 dark:text-gray-100">
                    {{ ($quota['is_unlimited'] ?? false) ? __('procynia.ai_quota.unlimited') : (int) ($quota['remaining'] ?? 0) }}
                </dd>
            </div>
            <div>
                <dt class="text-gray-600 dark:text-gray-400">{{ __('procynia.ai_admin.fields.period') }}</dt>
                <dd class="font-medium text-gray-900 dark:text-gray-100">
                    {{ ($quota['period_start'] ?? '') }} – {{ ($quota['period_end'] ?? '') }}
                </dd>
            </div>
            <div>
                <dt class="text-gray-600 dark:text-gray-400">{{ __('procynia.ai_admin.fields.access_status') }}</dt>
                <dd class="font-medium text-gray-900 dark:text-gray-100">
                    {{ ($quota['is_suspended'] ?? false)
                        ? __('procynia.ai_admin.access.suspended')
                        : __('procynia.ai_admin.access.enabled') }}
                </dd>
            </div>
        </dl>

        @if ($uncertainReservations > 0)
            <p class="mt-5 rounded-lg bg-warning-50 p-3 text-base leading-6 text-warning-900 dark:bg-warning-950 dark:text-warning-200">
                {{ __('procynia.ai_admin.uncertain_reservations', ['count' => $uncertainReservations]) }}
            </p>
        @endif
    </section>

    <section class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
        <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">
            {{ __('procynia.ai_admin.sections.adjustments') }}
        </h2>
        <p class="mt-1 text-base leading-6 text-gray-600 dark:text-gray-400">
            {{ __('procynia.ai_admin.sections.adjustments_help') }}
        </p>

        @if ($adjustments === [])
            <p class="mt-4 text-base text-gray-600 dark:text-gray-400">{{ __('procynia.ai_admin.empty_adjustments') }}</p>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-base">
                    <thead class="text-gray-600 dark:text-gray-400">
                        <tr>
                            <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_admin.columns.time') }}</th>
                            <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_admin.columns.period') }}</th>
                            <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_admin.columns.amount') }}</th>
                            <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_admin.columns.actor') }}</th>
                            <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_admin.columns.reason') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($adjustments as $adjustment)
                            <tr>
                                <td class="py-2 pr-4 text-gray-600 dark:text-gray-400">{{ $adjustment['created_at'] }}</td>
                                <td class="py-2 pr-4 text-gray-600 dark:text-gray-400">{{ $adjustment['period'] }}</td>
                                <td class="py-2 pr-4 font-medium text-gray-900 dark:text-gray-100">{{ $adjustment['amount'] }}</td>
                                <td class="py-2 pr-4 text-gray-600 dark:text-gray-400">{{ $adjustment['actor'] }}</td>
                                <td class="py-2 pr-4 text-gray-600 dark:text-gray-400">{{ $adjustment['reason'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-gray-900">
        <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">
            {{ __('procynia.ai_admin.sections.audit') }}
        </h2>
        <p class="mt-1 text-base leading-6 text-gray-600 dark:text-gray-400">
            {{ __('procynia.ai_admin.sections.audit_help') }}
        </p>

        @if ($auditEvents === [])
            <p class="mt-4 text-base text-gray-600 dark:text-gray-400">{{ __('procynia.ai_admin.empty_audit') }}</p>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-base">
                    <thead class="text-gray-600 dark:text-gray-400">
                        <tr>
                            <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_admin.columns.time') }}</th>
                            <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_admin.columns.action') }}</th>
                            <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_admin.columns.actor') }}</th>
                            <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_admin.columns.reason') }}</th>
                            <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_admin.columns.before') }}</th>
                            <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_admin.columns.after') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($auditEvents as $event)
                            <tr>
                                <td class="py-2 pr-4 align-top text-gray-600 dark:text-gray-400">{{ $event['created_at'] }}</td>
                                <td class="py-2 pr-4 align-top font-medium text-gray-900 dark:text-gray-100">{{ $event['event_label'] }}</td>
                                <td class="py-2 pr-4 align-top text-gray-600 dark:text-gray-400">{{ $event['actor'] }}</td>
                                <td class="py-2 pr-4 align-top text-gray-600 dark:text-gray-400">{{ $event['reason'] }}</td>
                                <td class="py-2 pr-4 align-top text-gray-600 dark:text-gray-400">
                                    @foreach ($event['before'] as $line)
                                        <div>{{ $line }}</div>
                                    @endforeach
                                </td>
                                <td class="py-2 pr-4 align-top text-gray-600 dark:text-gray-400">
                                    @foreach ($event['after'] as $line)
                                        <div>{{ $line }}</div>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-filament-panels::page>
