<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
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
                'plan_label' => $this->planLabel($customer->subscription_plan),
                'current_period_end' => $stripeSubscription
                    ? date('Y-m-d', $stripeSubscription->current_period_end)
                    : null,
                'cancel_at_period_end' => $stripeSubscription
                    ? $stripeSubscription->cancel_at_period_end
                    : false,
                'trial_ends_at' => $customer->trial_ends_at?->toDateString(),
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
                'invoice_pdf' => $invoice->invoice_pdf,
                'hosted_invoice_url' => $invoice->hosted_invoice_url,
            ])->values()->all(),
            [],
            false,
        );

        return Inertia::render('App/Billing/Index', [
            'subscription' => $subscriptionData,
            'invoices' => $invoices,
            'plans' => config('billing.plans'),
        ]);
    }

    public function cancel(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->isSystemOwner(), 403);

        $customer = $user->customer;
        $subscription = $customer->subscription('default');

        if ($subscription && $subscription->active()) {
            $subscription->cancel();
        }

        return redirect()->route('app.billing.index')
            ->with('success', __('procynia.billing.cancel_success'));
    }

    public function resume(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->isSystemOwner(), 403);

        $customer = $user->customer;
        $subscription = $customer->subscription('default');

        if ($subscription && $subscription->onGracePeriod()) {
            $subscription->resume();
        }

        return redirect()->route('app.billing.index')
            ->with('success', __('procynia.billing.resume_success'));
    }

    private function planLabel(?string $planKey): string
    {
        if (! $planKey) {
            return '';
        }

        return config("billing.plans.{$planKey}.label", $planKey);
    }
}
