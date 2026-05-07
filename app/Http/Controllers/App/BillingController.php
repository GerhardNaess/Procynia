<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Services\Billing\BillingService;
use App\Services\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BillingController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        abort_unless($user->isSystemOwner(), 403);

        $customer = $user->customer;
        $billingService = app(BillingService::class);
        $subscription = $customer->subscription('default');

        $subscriptionData = null;

        if ($subscription) {
            $stripeSubscription = rescue(
                fn () => $subscription->asStripeSubscription(),
                null,
                false,
            );

            $subscriptionData = [
                'status' => $subscription->stripe_status,
                'plan' => $customer->subscription_plan,
                'plan_label' => $customer->planName(),
                'billing_interval' => $customer->billing_interval,
                'current_period_end' => $stripeSubscription
                    ? date('Y-m-d', $stripeSubscription->current_period_end)
                    : null,
                'cancel_at_period_end' => $stripeSubscription?->cancel_at_period_end ?? false,
                'trial_ends_at' => $customer->trial_ends_at?->toDateString(),
                'included_users' => $customer->included_users,
                'included_ai_credits' => $customer->included_ai_credits,
            ];
        }

        $invoices = rescue(
            fn () => $customer->invoices()->map(fn ($invoice) => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'amount_due' => $invoice->total(),
                'currency' => strtoupper($invoice->rawInvoice()->currency),
                'status' => $invoice->paid ? 'paid' : 'open',
                'date' => $invoice->date()->toDateString(),
                'date_sort' => $invoice->date()->timestamp,
                'month' => $invoice->date()->locale('nb')->translatedFormat('F Y'),
                'month_sort' => $invoice->date()->format('Y-m'),
                'invoice_pdf' => $invoice->invoice_pdf,
                'hosted_invoice_url' => $invoice->hosted_invoice_url,
            ])->values()->all(),
            [],
            false,
        );

        $billingLines = $billingService->activeBillingLines($customer)
            ->map(fn ($line) => [
                'id' => $line->id,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'status' => $line->status,
                'source' => $line->source,
                'user_id' => $line->user_id,
                'user_name' => $line->user?->name,
                'billing_product' => $line->billingProduct?->name,
                'billing_product_key' => $line->billingProduct?->key,
                'billing_price' => $line->billingPrice?->name,
                'billing_price_key' => $line->billingPrice?->key,
                'interval' => $line->billingPrice?->interval,
                'stripe_subscription_item_id' => $line->stripe_subscription_item_id,
                'stripe_invoice_id' => $line->stripe_invoice_id,
                'starts_at' => $line->starts_at?->toDateString(),
                'ends_at' => $line->ends_at?->toDateString(),
            ])
            ->values()
            ->all();

        $serviceLevels = $billingService->activeServiceLevels($customer)
            ->map(fn ($level) => [
                'id' => $level->id,
                'user_id' => $level->user_id,
                'user_name' => $level->user?->name,
                'billing_product' => $level->billingProduct?->name,
                'billing_product_key' => $level->billingProduct?->key,
                'billing_price' => $level->billingPrice?->name,
                'billing_price_key' => $level->billingPrice?->key,
                'level_key' => $level->level_key,
                'status' => $level->status,
                'assigned_by' => $level->assignedByUser?->name,
                'starts_at' => $level->starts_at?->toDateString(),
                'ends_at' => $level->ends_at?->toDateString(),
            ])
            ->values()
            ->all();

        return Inertia::render('App/Billing/Index', [
            'subscription' => $subscriptionData,
            'invoices' => $invoices,
            'billing_lines' => $billingLines,
            'service_levels' => $serviceLevels,
        ]);
    }

    public function cancel(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->isSystemOwner(), 403);

        app(SubscriptionService::class)->cancel($user->customer);

        return redirect()
            ->route('app.billing.index')
            ->with('success', 'Abonnementet er satt til å avsluttes ved periodeslutt.');
    }

    public function resume(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->isSystemOwner(), 403);

        app(SubscriptionService::class)->resume($user->customer);

        return redirect()
            ->route('app.billing.index')
            ->with('success', 'Abonnementet er gjenopptatt.');
    }
}
