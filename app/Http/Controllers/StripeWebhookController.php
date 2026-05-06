<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Notifications\SubscriptionPaymentFailedNotification;
use Laravel\Cashier\Http\Controllers\WebhookController;

class StripeWebhookController extends WebhookController
{
    protected function handleCustomerSubscriptionDeleted(array $payload): void
    {
        parent::handleCustomerSubscriptionDeleted($payload);

        $stripeCustomerId = $payload['data']['object']['customer'] ?? null;

        if ($stripeCustomerId) {
            Customer::where('stripe_id', $stripeCustomerId)
                ->update(['subscription_plan' => null]);
        }
    }

    protected function handleCustomerSubscriptionUpdated(array $payload): void
    {
        parent::handleCustomerSubscriptionUpdated($payload);

        $stripeCustomerId = $payload['data']['object']['customer'] ?? null;
        $priceId = $payload['data']['object']['items']['data'][0]['price']['id'] ?? null;

        if ($stripeCustomerId && $priceId) {
            $planKey = $this->resolvePlanKey($priceId);

            Customer::where('stripe_id', $stripeCustomerId)
                ->update(['subscription_plan' => $planKey]);
        }
    }

    protected function handleInvoicePaymentFailed(array $payload): void
    {
        $stripeCustomerId = $payload['data']['object']['customer'] ?? null;

        if (! $stripeCustomerId) {
            return;
        }

        $customer = Customer::where('stripe_id', $stripeCustomerId)->first();

        if (! $customer) {
            return;
        }

        $invoiceNumber = $payload['data']['object']['number'] ?? null;
        $amountDue = $payload['data']['object']['amount_due'] ?? 0;

        $customer->users()
            ->where('bid_role', 'system_owner')
            ->where('is_active', true)
            ->each(function ($user) use ($customer, $invoiceNumber, $amountDue): void {
                $user->notify(new SubscriptionPaymentFailedNotification(
                    $customer->name,
                    $invoiceNumber,
                    $amountDue,
                ));
            });
    }

    private function resolvePlanKey(string $priceId): ?string
    {
        $plans = config('billing.plans', []);

        foreach ($plans as $key => $plan) {
            if (($plan['stripe_price_id'] ?? null) === $priceId) {
                return $key;
            }
        }

        return null;
    }
}
