<x-filament-panels::page>
    <x-filament::section>
        @if (count($customers) > 0)
            <div class="relative overflow-x-auto [contain:inline-size]" @include('filament.components.scroll-region-attrs', ['label' => \App\Filament\Support\AdminText::get('admin_a11y.billing_overview_table', 'Kunder og abonnementer')])>
                <table class="w-full text-base text-left">
                    <caption class="sr-only">{{ \App\Filament\Support\AdminText::get('admin_a11y.billing_overview_table', 'Kunder og abonnementer') }}</caption>
                    <thead class="border-b border-gray-200 dark:border-gray-700">
                        <tr>
                            <th scope="col" class="pb-2 pr-6 font-medium text-gray-600 dark:text-gray-400">Kunde</th>
                            <th scope="col" class="pb-2 pr-6 font-medium text-gray-600 dark:text-gray-400">Plan</th>
                            <th scope="col" class="pb-2 pr-6 font-medium text-gray-600 dark:text-gray-400">Status</th>
                            <th scope="col" class="pb-2 pr-6 font-medium text-gray-600 dark:text-gray-400">Prøveperiode slutter</th>
                            <th scope="col" class="pb-2 font-medium text-gray-600 dark:text-gray-400"><span class="sr-only">{{ \App\Filament\Support\AdminText::get('admin_a11y.actions_column', 'Handlinger') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($customers as $customer)
                            <tr>
                                <th scope="row" class="py-3 pr-6 text-left font-medium">{{ $customer['name'] }}</th>
                                <td class="py-3 pr-6">{{ $customer['subscription_plan'] ?? '—' }}</td>
                                <td class="py-3 pr-6">
                                    @if ($customer['stripe_status'])
                                        <span @class([
                                            'inline-flex items-center rounded-full px-2.5 py-1 text-base font-medium leading-6',
                                            'bg-success-100 dark:bg-success-500/20 text-success-800 dark:text-success-300' => $customer['stripe_status'] === 'active',
                                            'bg-warning-100 dark:bg-warning-500/20 text-warning-800 dark:text-warning-300' => $customer['stripe_status'] === 'trialing',
                                            'bg-danger-100 dark:bg-danger-500/20 text-danger-800 dark:text-danger-300' => in_array($customer['stripe_status'], ['past_due', 'canceled', 'incomplete']),
                                            'bg-gray-100 dark:bg-white/10 text-gray-800 dark:text-gray-100' => !in_array($customer['stripe_status'], ['active', 'trialing', 'past_due', 'canceled', 'incomplete']),
                                        ])>
                                            {{ $customer['stripe_status'] }}
                                        </span>
                                    @elseif ($customer['stripe_id'])
                                        <span class="inline-flex items-center rounded-full bg-gray-100 dark:bg-white/10 px-2.5 py-1 text-base font-medium leading-6 text-gray-600 dark:text-gray-400">
                                            Ingen abonnement
                                        </span>
                                    @else
                                        <span class="text-gray-600 dark:text-gray-400 text-base">Ikke koblet til Stripe</span>
                                    @endif
                                </td>
                                <td class="py-3 pr-6 text-gray-600 dark:text-gray-400">{{ $customer['trial_ends_at'] ?? '—' }}</td>
                                <td class="py-3">
                                    <a href="{{ $customer['billing_url'] }}" aria-label="Administrer {{ $customer['name'] }}"
                                       class="text-primary-600 dark:text-primary-400 hover:underline text-base font-medium">
                                        Administrer
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-base text-gray-600 dark:text-gray-400">Ingen kunder funnet.</p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
