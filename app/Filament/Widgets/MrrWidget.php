<?php

namespace App\Filament\Widgets;

use App\Models\BillingPrice;
use App\Models\Customer;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class MrrWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $mrr = 0;
        $activeCount = 0;
        $trialingCount = 0;

        Customer::query()
            ->get()
            ->each(function (Customer $customer) use (&$mrr, &$activeCount, &$trialingCount) {
                $subscription = $customer->subscription('default');

                if (! $subscription) {
                    return;
                }

                if ($subscription->stripe_status === 'trialing') {
                    $trialingCount++;
                }

                if (! in_array($subscription->stripe_status, ['active', 'trialing'], true)) {
                    return;
                }

                $activeCount++;

                $customer->billingLines()
                    ->whereIn('status', ['active', 'pending_cancel'])
                    ->with('billingPrice')
                    ->get()
                    ->each(function ($line) use (&$mrr): void {
                        $price = $line->billingPrice;

                        if (! $price instanceof BillingPrice || ! $price->is_recurring || $price->unit_amount === null) {
                            return;
                        }

                        $lineAmount = (int) $price->unit_amount * max(1, (int) $line->quantity);

                        if ($price->interval === BillingPrice::INTERVAL_YEARLY) {
                            $mrr += $lineAmount / 12;
                            return;
                        }

                        if ($price->interval === BillingPrice::INTERVAL_MONTHLY) {
                            $mrr += $lineAmount;
                        }
                    });
            });

        $arr = $mrr * 12;

        return [
            Stat::make('MRR', number_format($mrr, 0, ',', ' ') . ' kr')
                ->description('Månedlig gjentakende omsetning')
                ->color('success'),

            Stat::make('ARR', number_format($arr, 0, ',', ' ') . ' kr')
                ->description('Årlig gjentakende omsetning')
                ->color('primary'),

            Stat::make('Aktive abonnementer', $activeCount)
                ->description($trialingCount . ' i prøveperiode')
                ->color('info'),
        ];
    }
}
