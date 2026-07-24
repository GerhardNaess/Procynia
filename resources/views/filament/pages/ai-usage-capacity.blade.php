<x-filament-panels::page>
    <div class="space-y-6">
        <section class="max-w-6xl rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div class="space-y-1">
                    <div class="text-base font-semibold uppercase tracking-[0.16em] text-gray-600">
                        {{ __('procynia.ai_usage_capacity.page_title') }}
                    </div>
                    <p class="max-w-3xl text-base leading-6 text-gray-600">
                        {{ __('procynia.ai_usage_capacity.note') }}
                    </p>
                </div>
                <div class="text-base text-gray-600">
                    {{ __('procynia.common.updated_at') }}: {{ $generatedAt !== '' ? $generatedAt : __('procynia.common.not_available') }}
                </div>
            </div>
        </section>

        <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
            @foreach ($summaryCards as $card)
                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="text-base font-semibold uppercase tracking-[0.16em] text-gray-600">{{ $card['label'] }}</div>
                    <div class="mt-3 text-3xl font-semibold text-gray-950">{{ number_format((int) $card['value'], 0, ',', ' ') }}</div>
                    <p class="mt-2 text-base leading-6 text-gray-600">{{ $card['hint'] }}</p>
                </div>
            @endforeach
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <div class="space-y-1">
                    <div class="text-base font-semibold uppercase tracking-[0.16em] text-gray-600">
                        {{ __('procynia.ai_usage_capacity.sections.customers') }}
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900">
                        {{ __('procynia.ai_usage_capacity.sections.customers') }}
                    </h3>
                    <p class="text-base leading-6 text-gray-600">
                        {{ __('procynia.ai_usage_capacity.sections.customers_help') }}
                    </p>
                </div>
                <div class="text-base text-gray-600">
                    @if ($customerTotal > 0)
                        {{ __('procynia.ai_usage_capacity.controls.showing', ['from' => $customerShowingFrom, 'to' => $customerShowingTo, 'total' => $customerTotal]) }}
                    @endif
                </div>
            </div>

            <div class="mt-5 space-y-4">
                <div class="w-full lg:max-w-2xl">
                    <label class="block space-y-1 text-base">
                        <span class="font-medium text-gray-700">{{ __('procynia.ai_usage_capacity.controls.customer_search') }}</span>
                        <input
                            type="search"
                            wire:model.live.debounce.350ms="customerSearch"
                            placeholder="{{ __('procynia.ai_usage_capacity.controls.customer_search_placeholder') }}"
                            class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-base text-gray-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-primary-500"
                        >
                    </label>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    <label class="space-y-1 text-base">
                        <span class="font-medium text-gray-700">{{ __('procynia.ai_usage_capacity.controls.status_filter') }}</span>
                        <select
                            wire:model.live="customerStatusFilter"
                            class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-base text-gray-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-primary-500"
                        >
                            @foreach ($this->customerStatusOptions() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="space-y-1 text-base">
                        <span class="font-medium text-gray-700">{{ __('procynia.ai_usage_capacity.controls.sort_by') }}</span>
                        <select
                            wire:model.live="customerSortField"
                            class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-base text-gray-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-primary-500"
                        >
                            @foreach ($this->customerSortOptions() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="space-y-1 text-base">
                        <span class="font-medium text-gray-700">{{ __('procynia.ai_usage_capacity.controls.sort_direction') }}</span>
                        <select
                            wire:model.live="customerSortDirection"
                            class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-base text-gray-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-primary-500"
                        >
                            <option value="desc">{{ __('procynia.ai_usage_capacity.controls.sort_descending') }}</option>
                            <option value="asc">{{ __('procynia.ai_usage_capacity.controls.sort_ascending') }}</option>
                        </select>
                    </label>

                    <label class="space-y-1 text-base">
                        <span class="font-medium text-gray-700">{{ __('procynia.ai_usage_capacity.controls.per_page') }}</span>
                        <select
                            wire:model.live="customerPerPage"
                            class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-base text-gray-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-primary-500"
                        >
                            @foreach ($this->perPageOptions() as $value)
                                <option value="{{ $value }}">{{ $value }}</option>
                            @endforeach
                        </select>
                    </label>

                    <div class="flex items-end">
                        <button
                            type="button"
                            wire:click="resetCustomerFilters"
                            class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-2 text-base font-semibold text-gray-700 transition hover:bg-gray-100 xl:min-w-40"
                        >
                            {{ __('procynia.ai_usage_capacity.controls.clear_filters') }}
                        </button>
                    </div>
                </div>
            </div>

            @if ($this->customerFiltersActive())
                <p class="mt-3 text-base font-medium uppercase tracking-[0.12em] text-primary-600">
                    {{ __('procynia.ai_usage_capacity.controls.filtered_results') }}
                </p>
            @endif

            @if ($customerTotal > 0)
                <div class="mt-5 overflow-x-auto">
                    <table class="w-full min-w-[1080px] text-left text-base">
                        <thead class="border-b border-gray-200 text-gray-600">
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
                                            <span class="text-base leading-6 text-gray-600">({{ $row['capacity']['source_label'] }})</span>
                                        @else
                                            {{ $row['capacity']['label'] }}
                                        @endif
                                    </td>
                                    <td class="py-3 pr-4">
                                        <span @class([
                                            'inline-flex items-center rounded-full px-2 py-1 text-base leading-6 font-medium',
                                            'bg-success-100 text-success-800' => $row['status'] === 'within',
                                            'bg-warning-100 text-warning-800' => $row['status'] === 'near',
                                            'bg-danger-100 text-danger-800' => $row['status'] === 'over',
                                            'bg-gray-100 text-gray-700' => $row['status'] === 'undefined',
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
                                        <div class="text-base text-gray-600">
                                            {{ __('procynia.ai_usage_capacity.columns.blocked_user') }}: {{ number_format((int) $row['counts']['blocked_user'], 0, ',', ' ') }}
                                        </div>
                                        <div class="text-base text-gray-600">
                                            {{ __('procynia.ai_usage_capacity.columns.blocked_customer') }}: {{ number_format((int) $row['counts']['blocked_customer'], 0, ',', ' ') }}
                                        </div>
                                    </td>
                                    <td class="py-3 pr-4 text-gray-600">
                                        @if ($row['top_operation'] !== null)
                                            <div class="font-medium text-gray-900">{{ $row['top_operation']['label'] }}</div>
                                            <div class="font-mono text-base leading-6 text-gray-600">{{ $row['top_operation']['key'] }}</div>
                                        @else
                                            <span class="text-gray-600">{{ __('procynia.common.none') }}</span>
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

                <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="text-base text-gray-600">
                        {{ __('procynia.ai_usage_capacity.controls.page_of', ['current' => $customerPage, 'last' => $customerLastPage]) }}
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            wire:click="previousCustomerPage"
                            @disabled($customerPage <= 1)
                            class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-base font-medium text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            {{ __('procynia.common.previous') }}
                        </button>
                        <button
                            type="button"
                            wire:click="nextCustomerPage"
                            @disabled($customerPage >= $customerLastPage)
                            class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-base font-medium text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            {{ __('procynia.common.next') }}
                        </button>
                    </div>
                </div>
            @else
                <p class="mt-4 text-base leading-6 text-gray-600">{{ __('procynia.ai_usage_capacity.empty_customers') }}</p>
            @endif
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <div class="space-y-1">
                    <div class="text-base font-semibold uppercase tracking-[0.16em] text-gray-600">
                        {{ __('procynia.ai_usage_capacity.sections.users') }}
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900">
                        {{ __('procynia.ai_usage_capacity.sections.users') }}
                    </h3>
                    <p class="text-base leading-6 text-gray-600">
                        {{ __('procynia.ai_usage_capacity.sections.users_help') }}
                    </p>
                </div>
                <div class="text-base text-gray-600">
                    @if ($userTotal > 0)
                        {{ __('procynia.ai_usage_capacity.controls.showing', ['from' => $userShowingFrom, 'to' => $userShowingTo, 'total' => $userTotal]) }}
                    @endif
                </div>
            </div>

            <div class="mt-5 space-y-4">
                <div class="w-full lg:max-w-2xl">
                    <label class="block space-y-1 text-base">
                        <span class="font-medium text-gray-700">{{ __('procynia.ai_usage_capacity.controls.user_search') }}</span>
                        <input
                            type="search"
                            wire:model.live.debounce.350ms="userSearch"
                            placeholder="{{ __('procynia.ai_usage_capacity.controls.user_search_placeholder') }}"
                            class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-base text-gray-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-primary-500"
                        >
                    </label>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    <label class="space-y-1 text-base">
                        <span class="font-medium text-gray-700">{{ __('procynia.ai_usage_capacity.controls.status_filter') }}</span>
                        <select
                            wire:model.live="userStatusFilter"
                            class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-base text-gray-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-primary-500"
                        >
                            @foreach ($this->userStatusOptions() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="space-y-1 text-base">
                        <span class="font-medium text-gray-700">{{ __('procynia.ai_usage_capacity.controls.sort_by') }}</span>
                        <select
                            wire:model.live="userSortField"
                            class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-base text-gray-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-primary-500"
                        >
                            @foreach ($this->userSortOptions() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="space-y-1 text-base">
                        <span class="font-medium text-gray-700">{{ __('procynia.ai_usage_capacity.controls.sort_direction') }}</span>
                        <select
                            wire:model.live="userSortDirection"
                            class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-base text-gray-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-primary-500"
                        >
                            <option value="desc">{{ __('procynia.ai_usage_capacity.controls.sort_descending') }}</option>
                            <option value="asc">{{ __('procynia.ai_usage_capacity.controls.sort_ascending') }}</option>
                        </select>
                    </label>

                    <label class="space-y-1 text-base">
                        <span class="font-medium text-gray-700">{{ __('procynia.ai_usage_capacity.controls.per_page') }}</span>
                        <select
                            wire:model.live="userPerPage"
                            class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-base text-gray-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-primary-500"
                        >
                            @foreach ($this->perPageOptions() as $value)
                                <option value="{{ $value }}">{{ $value }}</option>
                            @endforeach
                        </select>
                    </label>

                    <div class="flex items-end">
                        <button
                            type="button"
                            wire:click="resetUserFilters"
                            class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-2 text-base font-semibold text-gray-700 transition hover:bg-gray-100 xl:min-w-40"
                        >
                            {{ __('procynia.ai_usage_capacity.controls.clear_filters') }}
                        </button>
                    </div>
                </div>
            </div>

            @if ($this->userFiltersActive())
                <p class="mt-3 text-base font-medium uppercase tracking-[0.12em] text-primary-600">
                    {{ __('procynia.ai_usage_capacity.controls.filtered_results') }}
                </p>
            @endif

            @if ($userTotal > 0)
                <div class="mt-5 overflow-x-auto">
                    <table class="w-full min-w-[1120px] text-left text-base">
                        <thead class="border-b border-gray-200 text-gray-600">
                            <tr>
                                <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_usage_capacity.columns.user') }}</th>
                                <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_usage_capacity.columns.email') }}</th>
                                <th class="pb-2 pr-4 font-medium">{{ __('procynia.ai_usage_capacity.columns.customer') }}</th>
                                <th class="pb-2 pr-4 font-medium">{{ __('procynia.common.status') }}</th>
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
                                    <td class="py-3 pr-4">
                                        <span @class([
                                            'inline-flex items-center rounded-full px-2 py-1 text-base leading-6 font-medium',
                                            'bg-danger-100 text-danger-800' => $row['status'] === 'blocked',
                                            'bg-warning-100 text-warning-800' => $row['status'] === 'near',
                                            'bg-success-100 text-success-800' => $row['status'] === 'within',
                                        ])>
                                            {{ $row['status_label'] }}
                                        </span>
                                    </td>
                                    <td class="py-3 pr-4 text-gray-600">{{ number_format((int) $row['periods']['24h'], 0, ',', ' ') }}</td>
                                    <td class="py-3 pr-4 text-gray-600">{{ number_format((int) $row['periods']['7d'], 0, ',', ' ') }}</td>
                                    <td class="py-3 pr-4 text-gray-600">{{ number_format((int) $row['periods']['30d'], 0, ',', ' ') }}</td>
                                    <td class="py-3 pr-4 text-gray-600">{{ number_format((int) $row['counts']['allowed'], 0, ',', ' ') }}</td>
                                    <td class="py-3 pr-4 text-gray-600">
                                        <div>{{ number_format((int) $row['counts']['blocked'], 0, ',', ' ') }}</div>
                                        <div class="text-base text-gray-600">
                                            {{ __('procynia.ai_usage_capacity.columns.blocked_user') }}: {{ number_format((int) $row['counts']['blocked_user'], 0, ',', ' ') }}
                                        </div>
                                        <div class="text-base text-gray-600">
                                            {{ __('procynia.ai_usage_capacity.columns.blocked_customer') }}: {{ number_format((int) $row['counts']['blocked_customer'], 0, ',', ' ') }}
                                        </div>
                                    </td>
                                    <td class="py-3 pr-4 text-gray-600">
                                        @if ($row['last_operation'] !== null)
                                            <div class="font-medium text-gray-900">{{ $row['last_operation']['label'] }}</div>
                                            <div class="font-mono text-base leading-6 text-gray-600">{{ $row['last_operation']['key'] }}</div>
                                        @else
                                            <span class="text-gray-600">{{ __('procynia.common.none') }}</span>
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

                <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="text-base text-gray-600">
                        {{ __('procynia.ai_usage_capacity.controls.page_of', ['current' => $userPage, 'last' => $userLastPage]) }}
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            wire:click="previousUserPage"
                            @disabled($userPage <= 1)
                            class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-base font-medium text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            {{ __('procynia.common.previous') }}
                        </button>
                        <button
                            type="button"
                            wire:click="nextUserPage"
                            @disabled($userPage >= $userLastPage)
                            class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-base font-medium text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            {{ __('procynia.common.next') }}
                        </button>
                    </div>
                </div>
            @else
                <p class="mt-4 text-base leading-6 text-gray-600">{{ __('procynia.ai_usage_capacity.empty_users') }}</p>
            @endif
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
            <div class="space-y-1">
                <div class="text-base font-semibold uppercase tracking-[0.16em] text-gray-600">{{ __('procynia.ai_usage_capacity.sections.operations') }}</div>
                <h3 class="text-lg font-semibold text-gray-900">{{ __('procynia.ai_usage_capacity.sections.operations') }}</h3>
            </div>

            @if (count($operationRows) > 0)
                <div class="mt-5 overflow-x-auto">
                    <table class="w-full text-left text-base">
                        <thead class="border-b border-gray-200 text-gray-600">
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
                                    <td class="py-3 pr-4 font-mono text-base text-gray-900">{{ $row['operation_key'] }}</td>
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
                <p class="mt-4 text-base leading-6 text-gray-600">{{ __('procynia.ai_usage_capacity.empty') }}</p>
            @endif
        </section>
    </div>
</x-filament-panels::page>
