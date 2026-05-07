<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\InvoiceLog;
use App\Notifications\SubscriptionPaymentFailedNotification;
use App\Services\Billing\BillingService;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Http\Controllers\WebhookController;
use Symfony\Component\HttpFoundation\Response;

class StripeWebhookController extends WebhookController
{
    public function handleWebhook(Request $request): Response
    {
        return parent::handleWebhook($request);
    }

    protected function handleInvoicePaymentSucceeded(array $payload): Response
    {
        $customer = $this->resolveCustomer($payload);
        $invoiceId = $payload['data']['object']['id'] ?? null;

        if ($customer && $invoiceId && ! InvoiceLog::query()->where('stripe_invoice_id', $invoiceId)->exists()) {
            InvoiceLog::create([
                'customer_id' => $customer->id,
                'stripe_invoice_id' => $invoiceId,
                'status' => 'paid',
                'amount_paid' => $payload['data']['object']['amount_paid'] ?? 0,
                'currency' => $payload['data']['object']['currency'] ?? 'nok',
                'line_items' => $payload['data']['object']['lines']['data'] ?? [],
                'invoice_date' => now(),
            ]);
        }

        return $this->successMethod();
    }

    protected function handleCustomerSubscriptionDeleted(array $payload): Response
    {
        $customer = $this->resolveCustomer($payload);

        if ($customer) {
            app(BillingService::class)->closeAccountBilling($customer, 'immediate');
            app(SubscriptionService::class)->syncPlanMeta($customer, Customer::PLAN_FREE, Customer::BILLING_MONTHLY);

            $customer->users()
                ->where('bid_role', 'system_owner')
                ->where('is_active', true)
                ->each(fn ($user) => $user->notify(new SubscriptionPaymentFailedNotification($customer->name, null, 0)));
        }

        return parent::handleCustomerSubscriptionDeleted($payload);
    }

    protected function handleCustomerSubscriptionUpdated(array $payload): Response
    {
        $customer = $this->resolveCustomer($payload);

        if ($customer) {
            app(BillingService::class)->syncSubscriptionFromStripe($customer);
            app(BillingService::class)->syncPlanFromCurrentSubscription($customer);
        }

        return parent::handleCustomerSubscriptionUpdated($payload);
    }

    protected function handleInvoicePaymentFailed(array $payload): Response
    {
        $customer = $this->resolveCustomer($payload);
        $invoiceId = $payload['data']['object']['id'] ?? null;

        if ($customer) {
            if ($invoiceId) {
                InvoiceLog::query()->updateOrCreate(
                    ['stripe_invoice_id' => $invoiceId],
                    [
                        'customer_id' => $customer->id,
                        'status' => 'open',
                        'amount_paid' => 0,
                        'currency' => $payload['data']['object']['currency'] ?? 'nok',
                        'line_items' => $payload['data']['object']['lines']['data'] ?? [],
                        'invoice_date' => now(),
                    ]
                );
            }

            $customer->users()
                ->where('bid_role', 'system_owner')
                ->where('is_active', true)
                ->each(fn ($user) => $user->notify(new SubscriptionPaymentFailedNotification(
                    $customer->name,
                    $payload['data']['object']['number'] ?? null,
                    $payload['data']['object']['amount_due'] ?? 0,
                )));

            Log::warning('[PROCYNIA][BILLING] Invoice payment failed.', [
                'customer_id' => $customer->id,
                'stripe_invoice_id' => $invoiceId,
            ]);
        }

        return $this->successMethod();
    }

    private function resolveCustomer(array $payload): ?Customer
    {
        $stripeCustomerId = $payload['data']['object']['customer'] ?? null;

        return $stripeCustomerId
            ? Customer::query()->where('stripe_id', $stripeCustomerId)->first()
            : null;
    }
}
