<x-filament-panels::page>
    <x-filament::section>
        @if (count($customers) > 0)
            <div class="overflow-x-auto">
                <table class="w-full text-base text-left">
                    <thead class="border-b border-gray-200 dark:border-gray-700">
                        <tr>
                            <th class="pb-2 pr-6 font-medium text-gray-600">Kunde</th>
                            <th class="pb-2 pr-6 font-medium text-gray-600">Plan</th>
                            <th class="pb-2 pr-6 font-medium text-gray-600">Status</th>
                            <th class="pb-2 pr-6 font-medium text-gray-600">Prøveperiode slutter</th>
                            <th class="pb-2 font-medium text-gray-600"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($customers as $customer)
                            <tr>
                                <td class="py-3 pr-6 font-medium">{{ $customer['name'] }}</td>
                                <td class="py-3 pr-6">{{ $customer['subscription_plan'] ?? '—' }}</td>
                                <td class="py-3 pr-6">
                                    @if ($customer['stripe_status'])
                                        <span @class([
                                            'inline-flex items-center rounded-full px-2.5 py-1 text-base font-medium leading-6',
                                            'bg-success-100 text-success-800' => $customer['stripe_status'] === 'active',
                                            'bg-warning-100 text-warning-800' => $customer['stripe_status'] === 'trialing',
                                            'bg-danger-100 text-danger-800' => in_array($customer['stripe_status'], ['past_due', 'canceled', 'incomplete']),
                                            'bg-gray-100 text-gray-800' => !in_array($customer['stripe_status'], ['active', 'trialing', 'past_due', 'canceled', 'incomplete']),
                                        ])>
                                            {{ $customer['stripe_status'] }}
                                        </span>
                                    @elseif ($customer['stripe_id'])
                                        <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-1 text-base font-medium leading-6 text-gray-600">
                                            Ingen abonnement
                                        </span>
                                    @else
                                        <span class="text-gray-600 text-base">Ikke koblet til Stripe</span>
                                    @endif
                                </td>
                                <td class="py-3 pr-6 text-gray-600">{{ $customer['trial_ends_at'] ?? '—' }}</td>
                                <td class="py-3">
                                    <a href="{{ $customer['billing_url'] }}"
                                       class="text-primary-600 hover:underline text-base font-medium">
                                        Administrer
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-base text-gray-600">Ingen kunder funnet.</p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
