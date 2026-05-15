<x-filament-panels::page>
    <div class="space-y-6">
        <section class="max-w-6xl rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div class="space-y-1">
                    <div class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">
                        {{ __('procynia.ai_usage_capacity.page_title') }}
                    </div>
                    <p class="max-w-3xl text-sm text-gray-600">
                        {{ __('procynia.ai_usage_capacity.note') }}
                    </p>
                </div>
                <div class="text-xs text-gray-500">
                    {{ __('procynia.common.updated_at') }}: {{ $generatedAt !== '' ? $generatedAt : __('procynia.common.not_available') }}
                </div>
            </div>
        </section>

        <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
            @foreach ($summaryCards as $card)
                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">{{ $card['label'] }}</div>
                    <div class="mt-3 text-3xl font-semibold text-gray-950">{{ number_format((int) $card['value'], 0, ',', ' ') }}</div>
                    <p class="mt-2 text-sm text-gray-500">{{ $card['hint'] }}</p>
                </div>
            @endforeach
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
            <div class="space-y-1">
                <div class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">{{ __('procynia.ai_usage_capacity.sections.customers') }}</div>
                <h3 class="text-lg font-semibold text-gray-900">{{ __('procynia.ai_usage_capacity.sections.customers') }}</h3>
            </div>

            @if (count($customerRows) > 0)
                <div class="mt-5 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-gray-200 text-gray-500">
                            <tr>
                                <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_usage_capacity.columns.customer') }}</th>
                                <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_usage_capacity.columns.plan') }}</th>
                                <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_usage_capacity.columns.capacity') }}</th>
                                <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_usage_capacity.columns.capacity_status') }}</th>
                                <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_usage_capacity.columns.usage_24h') }}</th>
                                <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_usage_capacity.columns.usage_7d') }}</th>
                                <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_usage_capacity.columns.usage_30d') }}</th>
                                <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_usage_capacity.columns.allowed') }}</th>
                                <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_usage_capacity.columns.blocked') }}</th>
                                <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_usage_capacity.columns.top_operation') }}</th>
                                <th class="pb-2 font-medium">{{ __('procynia.ai_usage_capacity.columns.last_blocked_at') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($customerRows as $row)
                                <tr>
                                    <td class="py-3 pr-4 font-medium text-gray-900">{{ $row['name'] }}</td>
                                    <td class="py-3 pr-4 text-gray-600">{{ $row['plan'] }}</td>
                                    <td class="py-3 pr-4 text-gray-600">
                                        @if ($row['capacity']['defined'])
                                            {{ $row['capacity']['label'] }}
                                            <span class="text-xs text-gray-500">({{ $row['capacity']['source_label'] }})</span>
                                        @else
                                            {{ $row['capacity']['label'] }}
                                        @endif
                                    </td>
                                    <td class="py-3 pr-4">
                                        <span @class([
                                            'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                            'bg-success-100 text-success-800' => $row['capacity']['tone'] === 'success',
                                            'bg-warning-100 text-warning-800' => $row['capacity']['tone'] === 'warning',
                                            'bg-danger-100 text-danger-800' => $row['capacity']['tone'] === 'danger',
                                            'bg-gray-100 text-gray-700' => $row['capacity']['tone'] === 'gray',
                                        ])>
                                            {{ $row['capacity']['status_label'] }}
                                        </span>
                                    </td>
                                    <td class="py-3 pr-4 text-gray-600">{{ number_format((int) $row['periods']['24h'], 0, ',', ' ') }}</td>
                                    <td class="py-3 pr-4 text-gray-600">{{ number_format((int) $row['periods']['7d'], 0, ',', ' ') }}</td>
                                    <td class="py-3 pr-4 text-gray-600">{{ number_format((int) $row['periods']['30d'], 0, ',', ' ') }}</td>
                                    <td class="py-3 pr-4 text-gray-600">{{ number_format((int) $row['counts']['allowed'], 0, ',', ' ') }}</td>
                                    <td class="py-3 pr-4 text-gray-600">
                                        <div>{{ number_format((int) $row['counts']['blocked'], 0, ',', ' ') }}</div>
                                        <div class="text-xs text-gray-500">
                                            {{ __('procynia.ai_usage_capacity.columns.blocked_user') }}: {{ number_format((int) $row['counts']['blocked_user'], 0, ',', ' ') }}
                                        </div>
                                        <div class="text-xs text-gray-500">
                                            {{ __('procynia.ai_usage_capacity.columns.blocked_customer') }}: {{ number_format((int) $row['counts']['blocked_customer'], 0, ',', ' ') }}
                                        </div>
                                    </td>
                                    <td class="py-3 pr-4 text-gray-600">
                                        @if ($row['top_operation'] !== null)
                                            <div class="font-medium text-gray-900">{{ $row['top_operation']['label'] }}</div>
                                            <div class="font-mono text-xs text-gray-500">{{ $row['top_operation']['key'] }}</div>
                                        @else
                                            <span class="text-gray-400">{{ __('procynia.common.none') }}</span>
                                        @endif
                                    </td>
                                    <td class="py-3 text-gray-600">
                                        {{ $row['last_blocked_at'] ?? __('procynia.common.none') }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="mt-4 text-sm text-gray-500">{{ __('procynia.ai_usage_capacity.empty') }}</p>
            @endif
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
            <div class="space-y-1">
                <div class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">{{ __('procynia.ai_usage_capacity.sections.users') }}</div>
                <h3 class="text-lg font-semibold text-gray-900">{{ __('procynia.ai_usage_capacity.sections.users') }}</h3>
            </div>

            @if (count($userRows) > 0)
                <div class="mt-5 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-gray-200 text-gray-500">
                            <tr>
                                <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_usage_capacity.columns.user') }}</th>
                                <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_usage_capacity.columns.email') }}</th>
                                <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_usage_capacity.columns.customer') }}</th>
                                <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_usage_capacity.columns.usage_24h') }}</th>
                                <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_usage_capacity.columns.usage_7d') }}</th>
                                <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_usage_capacity.columns.usage_30d') }}</th>
                                <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_usage_capacity.columns.allowed') }}</th>
                                <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_usage_capacity.columns.blocked') }}</th>
                                <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_usage_capacity.columns.last_operation') }}</th>
                                <th class="pb-2 font-medium">{{ __('procynia.ai_usage_capacity.columns.last_blocked_at') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($userRows as $row)
                                <tr>
                                    <td class="py-3 pr-4 font-medium text-gray-900">{{ $row['name'] }}</td>
                                    <td class="py-3 pr-4 text-gray-600">{{ $row['email'] }}</td>
                                    <td class="py-3 pr-4 text-gray-600">{{ $row['customer_name'] }}</td>
                                    <td class="py-3 pr-4 text-gray-600">{{ number_format((int) $row['periods']['24h'], 0, ',', ' ') }}</td>
                                    <td class="py-3 pr-4 text-gray-600">{{ number_format((int) $row['periods']['7d'], 0, ',', ' ') }}</td>
                                    <td class="py-3 pr-4 text-gray-600">{{ number_format((int) $row['periods']['30d'], 0, ',', ' ') }}</td>
                                    <td class="py-3 pr-4 text-gray-600">{{ number_format((int) $row['counts']['allowed'], 0, ',', ' ') }}</td>
                                    <td class="py-3 pr-4 text-gray-600">
                                        <div>{{ number_format((int) $row['counts']['blocked'], 0, ',', ' ') }}</div>
                                        <div class="text-xs text-gray-500">
                                            {{ __('procynia.ai_usage_capacity.columns.blocked_user') }}: {{ number_format((int) $row['counts']['blocked_user'], 0, ',', ' ') }}
                                        </div>
                                        <div class="text-xs text-gray-500">
                                            {{ __('procynia.ai_usage_capacity.columns.blocked_customer') }}: {{ number_format((int) $row['counts']['blocked_customer'], 0, ',', ' ') }}
                                        </div>
                                    </td>
                                    <td class="py-3 pr-4 text-gray-600">
                                        @if ($row['last_operation'] !== null)
                                            <div class="font-medium text-gray-900">{{ $row['last_operation']['label'] }}</div>
                                            <div class="font-mono text-xs text-gray-500">{{ $row['last_operation']['key'] }}</div>
                                        @else
                                            <span class="text-gray-400">{{ __('procynia.common.none') }}</span>
                                        @endif
                                    </td>
                                    <td class="py-3 text-gray-600">
                                        {{ $row['last_blocked_at'] ?? __('procynia.common.none') }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="mt-4 text-sm text-gray-500">{{ __('procynia.ai_usage_capacity.empty') }}</p>
            @endif
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
            <div class="space-y-1">
                <div class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-500">{{ __('procynia.ai_usage_capacity.sections.operations') }}</div>
                <h3 class="text-lg font-semibold text-gray-900">{{ __('procynia.ai_usage_capacity.sections.operations') }}</h3>
            </div>

            @if (count($operationRows) > 0)
                <div class="mt-5 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-gray-200 text-gray-500">
                            <tr>
                                <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_usage_capacity.columns.operation_key') }}</th>
                                <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_usage_capacity.columns.description') }}</th>
                                <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_usage_capacity.columns.usage_24h') }}</th>
                                <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_usage_capacity.columns.usage_7d') }}</th>
                                <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_usage_capacity.columns.usage_30d') }}</th>
                                <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_usage_capacity.columns.allowed') }}</th>
                                <th class="pb-2 font-medium">{{ __('procynia.ai_usage_capacity.columns.blocked') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($operationRows as $row)
                                <tr>
                                    <td class="py-3 pr-4 font-mono text-xs text-gray-900">{{ $row['operation_key'] }}</td>
                                    <td class="py-3 pr-4 text-gray-600">{{ $row['label'] }}</td>
                                    <td class="py-3 pr-4 text-gray-600">{{ number_format((int) $row['periods']['24h'], 0, ',', ' ') }}</td>
                                    <td class="py-3 pr-4 text-gray-600">{{ number_format((int) $row['periods']['7d'], 0, ',', ' ') }}</td>
                                    <td class="py-3 pr-4 text-gray-600">{{ number_format((int) $row['periods']['30d'], 0, ',', ' ') }}</td>
                                    <td class="py-3 pr-4 text-gray-600">{{ number_format((int) $row['counts']['allowed'], 0, ',', ' ') }}</td>
                                    <td class="py-3 text-gray-600">{{ number_format((int) $row['counts']['blocked'], 0, ',', ' ') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="mt-4 text-sm text-gray-500">{{ __('procynia.ai_usage_capacity.empty') }}</p>
            @endif
        </section>
    </div>
</x-filament-panels::page>
