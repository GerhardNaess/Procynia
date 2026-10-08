<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\CustomerBillingPeriod;
use App\Services\Billing\CustomerBillingPeriodRecorder;
use Illuminate\Console\Attribute\AsCommand;
use Illuminate\Console\Command;
use Throwable;

/**
 * Initial (and repair) sync of each customer's current Stripe billing period into
 * customer_billing_periods. Webhooks keep the table current afterwards; this exists for customers
 * whose subscription predates the table, or after a missed webhook.
 *
 * The only bulk path that calls the Stripe API for billing periods. Safe to re-run: recording the
 * same period twice changes nothing.
 */
#[AsCommand(name: 'billing:sync-subscriptions')]
class BillingSyncSubscriptions extends Command
{
    protected $signature = 'billing:sync-subscriptions
                            {--customer= : Only this customer id}';

    protected $description = 'Record the current Stripe billing period of every customer with a billing subscription.';

    public function handle(CustomerBillingPeriodRecorder $recorder): int
    {
        $customers = Customer::query()
            ->whereNotNull('stripe_id')
            ->whereHas('subscriptions', fn ($query) => $query->where('type', 'default')->whereIn('stripe_status', CustomerBillingPeriod::BILLING_SUBSCRIPTION_STATUSES))
            ->when($this->option('customer'), fn ($query, $id) => $query->whereKey((int) $id))
            ->orderBy('id')
            ->get();

        $failures = 0;

        foreach ($customers as $customer) {
            try {
                $period = $recorder->syncFromProvider($customer);
            } catch (Throwable $exception) {
                $failures++;
                $this->error(sprintf('Customer %d: %s', $customer->id, $exception->getMessage()));

                continue;
            }

            if (! $period instanceof CustomerBillingPeriod) {
                $failures++;
                $this->error(sprintf('Customer %d: no billing period could be recorded.', $customer->id));

                continue;
            }

            $this->line(sprintf(
                'Customer %d: %s → %s (%s, %s).',
                $customer->id,
                $period->period_start->toIso8601String(),
                $period->period_end->toIso8601String(),
                $period->interval ?? 'unknown interval',
                $period->subscription_status,
            ));
        }

        $this->info(sprintf('%d customer(s) synced, %d failed.', $customers->count() - $failures, $failures));

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
