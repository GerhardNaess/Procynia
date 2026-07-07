<?php

namespace App\Services;

use App\Models\BillingPrice;
use App\Models\BillingProduct;
use App\Models\Customer;
use App\Services\Billing\BillingService;
use RuntimeException;

class SubscriptionService
{
    public function __construct(
        private readonly BillingService $billingService,
    ) {
    }

    public function subscribe(Customer $customer, string $plan, string $interval): void
    {
        if ($plan === Customer::PLAN_FREE) {
            $this->billingService->closeAccountBilling($customer, 'immediate');
            $this->syncPlanMeta($customer, $plan, $interval);

            return;
        }

        if ($plan === Customer::PLAN_ENTERPRISE) {
            throw new RuntimeException('Enterprise-abonnementer håndteres manuelt. Ta kontakt med Procynia.');
        }

        $price = $this->billingService->resolvePlanPrice($plan, $interval);

        if (! $price instanceof BillingPrice || ! $price->is_active || ! $price->is_recurring) {
            throw new RuntimeException("Billing-katalogen mangler en aktiv pris for plan '{$plan}' ({$interval}).");
        }

        $price->loadMissing('product');

        if (! $price->product instanceof BillingProduct || $price->product->category !== BillingProduct::CATEGORY_BASE_PLAN) {
            throw new RuntimeException("Billing-katalogen mangler en base-planpris for plan '{$plan}' ({$interval}).");
        }

        if (! $this->billingService->hasStripeCustomer($customer)) {
            $this->billingService->syncLocalAccountBilling($customer, [
                'plan_key' => $plan,
                'billing_interval' => $interval,
                'source' => 'system',
            ]);

            return;
        }

        $this->billingService->createAccountBilling($customer, [
            'plan_key' => $plan,
            'billing_interval' => $interval,
            'source' => 'system',
        ]);
    }

    public function changePlan(Customer $customer, string $newPlan, string $interval): void
    {
        $this->subscribe($customer, $newPlan, $interval);
    }

    public function cancel(Customer $customer): void
    {
        $this->billingService->closeAccountBilling($customer, 'period_end');
    }

    public function cancelImmediately(Customer $customer): void
    {
        $this->billingService->closeAccountBilling($customer, 'immediate');
    }

    public function resume(Customer $customer): void
    {
        $this->billingService->resumeAccountBilling($customer);
    }

    public function chargeOneOff(Customer $customer, string $description, int $amountNok): void
    {
        $customer->invoiceFor($description, $amountNok * 100);
    }

    public function planOptions(): array
    {
        return $this->billingService->planOptions();
    }

    public function resolvePriceId(string $plan, string $interval): ?string
    {
        return $this->billingService->resolvePriceId($plan, $interval);
    }

    public function syncPlanMeta(Customer $customer, string $plan, string $interval): void
    {
        $this->billingService->syncPlanMeta($customer, $plan, $interval);
    }

    public function syncPlanFromPriceId(Customer $customer, string $stripePriceId): void
    {
        $this->billingService->syncPlanFromPriceId($customer, $stripePriceId);
    }
}
