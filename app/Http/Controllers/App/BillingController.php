<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\BillingProduct;
use App\Models\Customer;
use App\Services\Ai\Commercial\CustomerAiCapacityService;
use App\Services\Billing\BillingService;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class BillingController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        abort_unless($user->canManageCustomerBilling(), 403);

        $customer = $user->customer;
        $billingService = app(BillingService::class);
        $availablePlans = $this->availablePlanOptions($customer);
        $activeBillingLines = $billingService->activeBillingLines($customer);
        $basePlanLines = $activeBillingLines->filter(fn ($line): bool => $line->billingProduct?->category === BillingProduct::CATEGORY_BASE_PLAN);
        $basePlanLine = $basePlanLines->sortByDesc(fn ($line): int => $line->created_at?->timestamp ?? 0)->first();
        $planKey = data_get($basePlanLine?->metadata, 'plan_key') ?? $customer->subscription_plan ?? Customer::PLAN_FREE;
        $hasRegisteredSubscription = $basePlanLine !== null || $planKey !== Customer::PLAN_FREE;

        $subscriptionData = null;

        if ($hasRegisteredSubscription) {
            $subscriptionData = [
                'status' => $basePlanLine?->status === 'pending_cancel'
                    ? 'active'
                    : ($basePlanLine?->status ?? 'active'),
                'plan' => $planKey,
                'plan_label' => config("procynia_plans.{$planKey}.name", $customer->planName()),
                'billing_interval' => $basePlanLine?->billingPrice?->interval ?? $customer->billing_interval,
                'cancel_at_period_end' => $basePlanLine?->status === 'pending_cancel',
                'included_users' => $customer->included_users,
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

        $billingLines = $activeBillingLines
            ->reject(fn ($line): bool => $line->billingProduct?->category === BillingProduct::CATEGORY_BASE_PLAN)
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

        return Inertia::render('App/Billing/Index', [
            'customer_plan' => $this->customerPlanContext($customer),
            'available_plans' => $availablePlans,
            'subscription' => $subscriptionData,
            'invoices' => $invoices,
            'billing_lines' => $billingLines,
            // The shared AI capacity, in AI units — the same figures the capacity gate reads. The
            // Anbud AI-case quota is Tender's own and is shown in the AI workspace, not here.
            'ai_capacity' => app(CustomerAiCapacityService::class)->forCustomer($customer)->toArray(),
            // Resolved server-side: the page renders this verdict rather than deciding for itself
            // which packages are active.
            'module_packages' => app(ModuleEntitlementService::class)->overviewFor($customer),
            'active_modules' => app(ModuleEntitlementService::class)->modulesFor($customer),
        ]);
    }

    /**
     * Order Basis or an option. Self-service ordering completes in this request: the entitlement
     * is written active, so the left rail, the Abonnement page and EnsureModuleIsEnabled all agree
     * on the next response. No payment is started here, and no permission is granted: people still
     * reach the module only through their roles.
     *
     * Ordering a cancelled option again reactivates its row; everything registered under it before
     * is reachable again, untouched.
     *
     * The customer is always the signed-in user's own; the package key is the only input, so there
     * is no way to name another customer. Bundles (Styring, ISO, GRC) are not orderable here.
     */
    public function requestPackage(Request $request, string $package): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->canManageCustomerBilling(), 403);

        $customer = $user->customer;
        abort_unless($customer instanceof Customer, 404);

        $service = app(ModuleEntitlementService::class);
        $catalogEntry = $service->package($package);

        abort_unless($catalogEntry !== null && $catalogEntry['orderable'], 404);

        if ($service->hasPackage($customer, $package)) {
            return redirect()
                ->route('app.billing.index')
                ->with('error', __('procynia.billing.modules.order_already_active'));
        }

        $service->activatePackage($customer, $package, $user);

        // The redirect is what refreshes the shared entitlement props: the rail reads
        // `entitlements.modules` from HandleInertiaRequests, which is recomputed on this GET.
        return redirect()
            ->route('app.billing.index')
            ->with('success', __('procynia.billing.modules.order_success', [
                'package' => __("procynia.billing.modules.package_labels.{$package}"),
            ]));
    }

    /**
     * Cancel one option. Basis is never cancelled — it is not an option — so asking for it is a
     * 404, as is an unknown key. Nothing the customer registered under the option is deleted.
     */
    public function cancelPackage(Request $request, string $package): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->canManageCustomerBilling(), 403);

        $customer = $user->customer;
        abort_unless($customer instanceof Customer, 404);

        $service = app(ModuleEntitlementService::class);

        abort_unless($service->isOption($package), 404);

        if (! $service->cancelOption($customer, $package)) {
            return redirect()
                ->route('app.billing.index')
                ->with('error', __('procynia.billing.modules.cancel_not_active'));
        }

        return redirect()
            ->route('app.billing.index')
            ->with('success', __('procynia.billing.modules.cancel_success', [
                'package' => __("procynia.billing.modules.package_labels.{$package}"),
            ]));
    }

    public function cancel(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->canManageCustomerBilling(), 403);

        app(SubscriptionService::class)->cancel($user->customer);

        return redirect()
            ->route('app.billing.index')
            ->with('success', 'Abonnementet er satt til å avsluttes ved periodeslutt.');
    }

    public function resume(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->canManageCustomerBilling(), 403);

        app(SubscriptionService::class)->resume($user->customer);

        return redirect()
            ->route('app.billing.index')
            ->with('success', 'Abonnementet er gjenopptatt.');
    }

    public function changePlan(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->canManageCustomerBilling(), 403);

        $customer = $user->customer;
        abort_unless($customer instanceof Customer, 404);

        $availablePlans = $this->availablePlanOptions($customer);
        $allowedPlans = array_values(array_map(
            fn (array $plan): string => (string) $plan['key'],
            $availablePlans
        ));

        $validated = $request->validate([
            'plan' => ['required', 'string', Rule::in($allowedPlans)],
            'interval' => ['required', 'string', Rule::in([Customer::BILLING_MONTHLY, Customer::BILLING_YEARLY])],
        ], [
            'plan.required' => __('procynia.billing.plan_change.validation_plan'),
            'plan.in' => __('procynia.billing.plan_change.validation_plan'),
            'interval.required' => __('procynia.billing.plan_change.validation_interval'),
            'interval.in' => __('procynia.billing.plan_change.validation_interval'),
        ]);

        $selectedPlan = collect($availablePlans)->firstWhere('key', $validated['plan']);

        if (! $selectedPlan || ! collect($selectedPlan['intervals'] ?? [])->contains(fn (array $interval): bool => ($interval['interval'] ?? null) === $validated['interval'])) {
            return back()->withErrors([
                'interval' => __('procynia.billing.plan_change.validation_interval'),
            ]);
        }

        try {
            app(SubscriptionService::class)->changePlan($customer, $validated['plan'], $validated['interval']);
        } catch (Throwable $throwable) {
            return back()->withErrors([
                'plan' => $this->planChangeErrorMessage($throwable),
            ]);
        }

        return redirect()
            ->route('app.billing.index')
            ->with('success', __('procynia.billing.plan_change.success'));
    }

    private function customerPlanContext(Customer $customer): array
    {
        $billingInterval = $customer->billing_interval ?? Customer::BILLING_MONTHLY;

        return [
            'plan' => $customer->subscription_plan ?? Customer::PLAN_FREE,
            'plan_label' => $customer->planName(),
            'billing_interval' => $billingInterval,
            'billing_interval_label' => $billingInterval === Customer::BILLING_YEARLY
                ? __('procynia.billing.plan_change.yearly')
                : __('procynia.billing.plan_change.monthly'),
        ];
    }

    private function availablePlanOptions(Customer $customer): array
    {
        $currentPlan = $customer->subscription_plan ?? Customer::PLAN_FREE;
        $currentInterval = $customer->billing_interval ?? Customer::BILLING_MONTHLY;
        $plans = [];

        foreach (config('procynia_plans', []) as $planKey => $plan) {
            if (in_array($planKey, [Customer::PLAN_FREE, Customer::PLAN_ENTERPRISE], true)) {
                continue;
            }

            $intervals = [];

            foreach ([Customer::BILLING_MONTHLY, Customer::BILLING_YEARLY] as $interval) {
                $priceKey = $interval === Customer::BILLING_YEARLY ? 'yearly_price_nok' : 'monthly_price_nok';
                $price = $plan[$priceKey] ?? null;

                if ($price === null) {
                    continue;
                }

                $intervals[] = [
                    'interval' => $interval,
                    'label' => $interval === Customer::BILLING_YEARLY
                        ? __('procynia.billing.plan_change.yearly')
                        : __('procynia.billing.plan_change.monthly'),
                    'price_nok' => $price,
                    'is_current' => $currentPlan === $planKey && $currentInterval === $interval,
                ];
            }

            if ($intervals === []) {
                continue;
            }

            $plans[] = [
                'key' => $planKey,
                'name' => $plan['name'] ?? ucfirst($planKey),
                'included_users' => $plan['included_users'] ?? null,
                // Per month; a yearly period includes twelve times this.
                'included_ai_units' => $plan['included_ai_units'] ?? null,
                'is_current' => $currentPlan === $planKey,
                'intervals' => $intervals,
            ];
        }

        return $plans;
    }

    private function planChangeErrorMessage(Throwable $throwable): string
    {
        $paymentSetupMessage = __('procynia.billing.plan_change.payment_setup_missing');
        $message = strtolower($throwable->getMessage());

        if (
            str_contains($message, 'payment setup')
            || str_contains($message, 'betalingsoppsettet')
            || str_starts_with($throwable::class, 'Stripe\\')
            || str_starts_with($throwable::class, 'Laravel\\Cashier\\')
        ) {
            return $paymentSetupMessage;
        }

        return __('procynia.billing.plan_change.error');
    }
}
