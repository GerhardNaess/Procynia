<x-filament-panels::page>
    <div class="space-y-6">

        {{-- Stripe-kundestatus --}}
        <x-filament::section heading="Stripe-konto">
            @if ($record->stripe_id)
                <div class="text-sm text-gray-600 dark:text-gray-400">
                    Stripe Customer ID: <code class="font-mono">{{ $record->stripe_id }}</code>
                </div>
            @else
                <p class="text-sm text-warning-600">Ingen Stripe-kunde er registrert. Bruk «Opprett Stripe-kunde» for å initialisere.</p>
            @endif
        </x-filament::section>

        {{-- Abonnementsstatus --}}
        <x-filament::section heading="Abonnement">
            @if ($subscriptionData)
                <dl class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt class="font-medium text-gray-500">Status</dt>
                        <dd class="mt-1">
                            <span @class([
                                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                'bg-success-100 text-success-800' => $subscriptionData['status'] === 'active',
                                'bg-warning-100 text-warning-800' => $subscriptionData['status'] === 'trialing',
                                'bg-danger-100 text-danger-800' => in_array($subscriptionData['status'], ['past_due', 'canceled', 'incomplete']),
                                'bg-gray-100 text-gray-800' => !in_array($subscriptionData['status'], ['active', 'trialing', 'past_due', 'canceled', 'incomplete']),
                            ])>
                                {{ $subscriptionData['status'] }}
                            </span>
                            @if ($subscriptionData['cancel_at_period_end'])
                                <span class="ml-2 inline-flex items-center rounded-full bg-warning-100 px-2 py-0.5 text-xs font-medium text-warning-800">
                                    Avsluttes ved periodeslutt
                                </span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-500">Plan</dt>
                        <dd class="mt-1">{{ $subscriptionData['plan_label'] ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-500">Neste fornyelse</dt>
                        <dd class="mt-1">{{ $subscriptionData['current_period_end'] ?? '—' }}</dd>
                    </div>
                    @if ($subscriptionData['trial_ends_at'])
                        <div>
                            <dt class="font-medium text-gray-500">Prøveperiode slutter</dt>
                            <dd class="mt-1">{{ $subscriptionData['trial_ends_at'] }}</dd>
                        </div>
                    @endif
                </dl>
            @else
                <p class="text-sm text-gray-500">Ingen aktiv abonnement.</p>
            @endif
        </x-filament::section>

        {{-- Fakturahistorikk --}}
        <x-filament::section heading="Fakturaer">
            @if (count($invoices) > 0)
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="border-b border-gray-200 dark:border-gray-700">
                            <tr>
                                <th class="pb-2 font-medium text-gray-500">Fakturanr.</th>
                                <th class="pb-2 font-medium text-gray-500">Dato</th>
                                <th class="pb-2 font-medium text-gray-500">Beløp</th>
                                <th class="pb-2 font-medium text-gray-500">Status</th>
                                <th class="pb-2 font-medium text-gray-500">PDF</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($invoices as $invoice)
                                <tr>
                                    <td class="py-2 font-mono text-xs">{{ $invoice['number'] ?? $invoice['id'] }}</td>
                                    <td class="py-2">{{ $invoice['date'] }}</td>
                                    <td class="py-2">{{ $invoice['amount'] }} {{ $invoice['currency'] }}</td>
                                    <td class="py-2">{{ $invoice['status'] }}</td>
                                    <td class="py-2">
                                        @if ($invoice['pdf_url'])
                                            <a href="{{ $invoice['pdf_url'] }}" target="_blank" class="text-primary-600 hover:underline text-xs">
                                                Last ned
                                            </a>
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-sm text-gray-500">Ingen fakturaer funnet.</p>
            @endif
        </x-filament::section>

    </div>
</x-filament-panels::page>
