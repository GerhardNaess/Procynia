<?php

namespace App\Console\Commands;

use App\Models\BillingProduct;
use App\Models\Customer;
use App\Models\CustomerBillingLine;
use App\Services\Billing\BillingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:backfill-free-plan-lines {--apply : Persist changes instead of dry-run.}')]
#[Description('Backfill active plan_free billing lines for existing free/null customers.')]
class BackfillFreePlanBillingLines extends Command
{
    /**
     * Purpose: Materialize missing free-plan billing lines for existing free/null customers.
     * Inputs: Optional --apply flag to persist changes.
     * Returns: An Artisan exit code.
     * Side effects: Dry-run only reports; --apply writes billing lines for qualified customers.
     */
    public function handle(BillingService $billingService): int
    {
        $apply = (bool) $this->option('apply');

        $summary = [
            'evaluated' => 0,
            'eligible' => 0,
            'materialized' => 0,
            'skipped_non_free_plan' => 0,
            'skipped_active_cashier_subscription' => 0,
            'skipped_active_paid_baseplan' => 0,
            'skipped_existing_active_plan_free' => 0,
            'skipped_missing_free_catalog' => 0,
        ];

        $examples = [];

        Customer::query()
            ->select(['id', 'name', 'subscription_plan'])
            ->with([
                'billingLines.billingPrice.product',
                'subscriptions',
            ])
            ->orderBy('id')
            ->chunkById(100, function ($customers) use (&$summary, &$examples, $apply, $billingService): void {
                foreach ($customers as $customer) {
                    $summary['evaluated']++;

                    if (! in_array($customer->subscription_plan, [null, Customer::PLAN_FREE], true)) {
                        $summary['skipped_non_free_plan']++;

                        continue;
                    }

                    if ($customer->subscribed('default')) {
                        $summary['skipped_active_cashier_subscription']++;

                        continue;
                    }

                    if ($this->hasActivePaidBasePlanLine($customer)) {
                        $summary['skipped_active_paid_baseplan']++;

                        continue;
                    }

                    if ($this->hasActivePlanFreeLine($customer)) {
                        $summary['skipped_existing_active_plan_free']++;

                        continue;
                    }

                    $summary['eligible']++;
                    $examples[] = $customer;

                    if (! $apply) {
                        continue;
                    }

                    if (! ($billingService->materializeFreePlanLine($customer) instanceof CustomerBillingLine)) {
                        $summary['skipped_missing_free_catalog']++;

                        continue;
                    }

                    $summary['materialized']++;
                }
            });

        $this->line('billing:backfill-free-plan-lines');
        $this->line('mode='.($apply ? 'apply' : 'dry-run'));
        $this->line('evaluated='.$summary['evaluated']);
        $this->line('eligible='.$summary['eligible']);
        $this->line('materialized='.$summary['materialized']);
        $this->line('skipped_non_free_plan='.$summary['skipped_non_free_plan']);
        $this->line('skipped_active_cashier_subscription='.$summary['skipped_active_cashier_subscription']);
        $this->line('skipped_active_paid_baseplan='.$summary['skipped_active_paid_baseplan']);
        $this->line('skipped_existing_active_plan_free='.$summary['skipped_existing_active_plan_free']);
        $this->line('skipped_missing_free_catalog='.$summary['skipped_missing_free_catalog']);
        $this->line('examples:');

        if ($examples === []) {
            $this->line('- none');
        } else {
            foreach ($examples as $customer) {
                $this->line(sprintf('- %s (#%d)', $customer->name, $customer->id));
            }
        }

        return self::SUCCESS;
    }

    private function hasActivePaidBasePlanLine(Customer $customer): bool
    {
        return $customer->billingLines
            ->filter(fn (CustomerBillingLine $line): bool => in_array($line->status, ['active', 'pending_cancel'], true))
            ->contains(function (CustomerBillingLine $line): bool {
                $product = $line->billingPrice?->product;

                return $product instanceof BillingProduct
                    && $product->category === BillingProduct::CATEGORY_BASE_PLAN
                    && $product->key !== 'plan_free';
            });
    }

    private function hasActivePlanFreeLine(Customer $customer): bool
    {
        return $customer->billingLines
            ->filter(fn (CustomerBillingLine $line): bool => in_array($line->status, ['active', 'pending_cancel'], true))
            ->contains(function (CustomerBillingLine $line): bool {
                $product = $line->billingPrice?->product;

                return $product instanceof BillingProduct
                    && $product->key === 'plan_free';
            });
    }
}
