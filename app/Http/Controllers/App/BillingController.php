<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\BillingProduct;
use App\Models\Customer;
use App\Services\Ai\Commercial\AiCapacityLevelService;
use App\Services\Ai\Commercial\CustomerAiCapacityService;
use App\Services\Billing\BillingEntitlementService;
use App\Services\Billing\BillingService;
use App\Services\Billing\CustomerBillingPeriodResolver;
use App\Services\Modules\ModuleEntitlementService;
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

        abort_unless($user->canManageCustomerBilling(), 403);

        $customer = $user->customer;
        $billingService = app(BillingService::class);
        $activeBillingLines = $billingService->activeBillingLines($customer);
        $basePlanLines = $activeBillingLines->filter(fn ($line): bool => $line->billingProduct?->category === BillingProduct::CATEGORY_BASE_PLAN);
        $basePlanLine = $basePlanLines->sortByDesc(fn ($line): int => $line->created_at?->timestamp ?? 0)->first();
        // The legacy plan key (free/pro/max/ultra/enterprise) still decides whether a subscription
        // is registered, but it is never sent to the page: customers see Basis + options + AI
        // capacity, not the old commercial tiers that still live under the hood.
        $planKey = data_get($basePlanLine?->metadata, 'plan_key') ?? $customer->subscription_plan ?? Customer::PLAN_FREE;
        $hasRegisteredSubscription = $basePlanLine !== null || $planKey !== Customer::PLAN_FREE;

        $subscriptionData = null;

        if ($hasRegisteredSubscription) {
            $includedUsers = app(BillingEntitlementService::class)->includedUsers($customer);
            $period = app(CustomerBillingPeriodResolver::class)->current($customer);

            $subscriptionData = [
                'status' => $basePlanLine?->status === 'pending_cancel'
                    ? 'active'
                    : ($basePlanLine?->status ?? 'active'),
                'billing_interval' => $basePlanLine?->billingPrice?->interval ?? $customer->billing_interval ?? Customer::BILLING_MONTHLY,
                'cancel_at_period_end' => $basePlanLine?->status === 'pending_cancel',
                // The number canAddUser() enforces; zero or less means no limit, so nothing is shown.
                'included_users' => $includedUsers > 0 ? $includedUsers : null,
                // Only a period Stripe reported is a reliable invoice date; a derived one is not shown.
                'period_end' => $period->isProviderPeriod() ? $period->end->toDateString() : null,
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
            'subscription' => $subscriptionData,
            'invoices' => $invoices,
            'billing_lines' => $billingLines,
            // The shared AI capacity, in AI units — the same figures the capacity gate reads. The
            // Anbud AI-case quota is Tender's own and is shown in the AI workspace, not here.
            'ai_capacity' => app(CustomerAiCapacityService::class)->forCustomer($customer)->toArray(),
            // What each level would include for this customer right now — units only, never the
            // multiplier or the weights behind them.
            'ai_capacity_levels' => app(CustomerAiCapacityService::class)->levelOptions($customer),
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

    /**
     * Choose the AI capacity level (Nivå 1/2/3). Same permission as ordering and cancelling options.
     * The key is validated against the catalog here, never trusted from the page; the customer is
     * always the signed-in user's own. Applies at once; usage and the billing period are untouched.
     */
    public function changeAiCapacityLevel(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->canManageCustomerBilling(), 403);

        $customer = $user->customer;
        abort_unless($customer instanceof Customer, 404);

        $level = (string) $request->validate(['level' => ['required', 'string', 'max:64']])['level'];
        $refused = app(AiCapacityLevelService::class)->change($customer, $level, $user);

        if ($refused !== null) {
            return redirect()
                ->route('app.billing.index')
                ->with('error', __("procynia.billing.ai_capacity.level_refused.{$refused}"));
        }

        return redirect()
            ->route('app.billing.index')
            ->with('success', __('procynia.billing.ai_capacity.level_changed', [
                'level' => app(CustomerAiCapacityService::class)->forCustomer($customer->fresh())->tierName,
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
}
