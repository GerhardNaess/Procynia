<?php

namespace Tests\Feature\Billing;

use App\Data\Billing\BillingPeriod;
use App\Models\Customer;
use App\Models\CustomerBillingPeriod;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Billing\CustomerBillingPeriodRecorder;
use App\Services\Billing\CustomerBillingPeriodResolver;
use App\Services\Operations\RuntimePreflightService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Cashier\Subscription;
use Tests\TestCase;

/**
 * customer_billing_periods as the local source of truth for "which billing period does timestamp
 * X belong to for customer Y": kept current by webhooks, history-preserving, tenant-safe, and read
 * without ever calling Stripe.
 */
class CustomerBillingPeriodTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_billing_periods';

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    // ── webhooks ───────────────────────────────────────────────────────────

    public function test_a_subscription_created_webhook_records_the_current_period_from_the_items(): void
    {
        $customer = $this->customer('cus_created');

        $this->postWebhook('customer.subscription.created', $this->subscription('sub_1', 'cus_created', '2026-10-05 08:00:00', '2026-11-05 08:00:00'))->assertOk();

        $period = CustomerBillingPeriod::query()->sole();
        $this->assertSame($customer->id, $period->customer_id);
        $this->assertSame('sub_1', $period->provider_subscription_id);
        $this->assertSame('2026-10-05 08:00:00', $period->period_start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-05 08:00:00', $period->period_end->format('Y-m-d H:i:s'));
        $this->assertSame('month', $period->interval);
        $this->assertSame('active', $period->subscription_status);
        $this->assertNotNull($period->synced_at);
    }

    public function test_a_renewal_adds_a_period_and_keeps_the_previous_one(): void
    {
        $customer = $this->customer('cus_renew');
        $this->localSubscription($customer, 'sub_r');

        $this->postWebhook('customer.subscription.updated', $this->subscription('sub_r', 'cus_renew', '2026-09-05 00:00:00', '2026-10-05 00:00:00'))->assertOk();
        $this->postWebhook('customer.subscription.updated', $this->subscription('sub_r', 'cus_renew', '2026-10-05 00:00:00', '2026-11-05 00:00:00'))->assertOk();
        // The same event delivered twice changes nothing.
        $this->postWebhook('customer.subscription.updated', $this->subscription('sub_r', 'cus_renew', '2026-10-05 00:00:00', '2026-11-05 00:00:00'))->assertOk();

        $this->assertSame(
            [['2026-09-05', '2026-10-05'], ['2026-10-05', '2026-11-05']],
            $this->periods($customer),
        );
    }

    public function test_an_older_webhook_delivered_late_does_not_overwrite_newer_state(): void
    {
        $customer = $this->customer('cus_order');
        $recorder = app(CustomerBillingPeriodRecorder::class);
        $subscription = $this->subscription('sub_o', 'cus_order', '2026-10-01 00:00:00', '2026-11-01 00:00:00');

        $recorder->recordStripeSubscription($customer, ['cancel_at_period_end' => true] + $subscription, providerEventAt: 2_000);
        $recorder->recordStripeSubscription($customer, ['cancel_at_period_end' => false] + $subscription, providerEventAt: 1_000);

        $this->assertTrue(CustomerBillingPeriod::query()->sole()->cancel_at_period_end);
    }

    public function test_a_webhook_for_another_customers_stripe_id_records_nothing(): void
    {
        $owner = $this->customer('cus_owner');
        $other = $this->customer('cus_other');
        $recorder = app(CustomerBillingPeriodRecorder::class);

        // Payload names cus_owner, but is recorded against the other customer: refused.
        $this->assertNull($recorder->recordStripeSubscription($other, $this->subscription('sub_t', 'cus_owner', '2026-10-01', '2026-11-01')));

        // A subscription id already recorded for one customer cannot be claimed by another.
        $recorder->recordStripeSubscription($owner, $this->subscription('sub_t', 'cus_owner', '2026-10-01', '2026-11-01'));
        $other->forceFill(['stripe_id' => 'cus_owner_dup'])->save();
        $this->assertNull($recorder->recordStripeSubscription($other, $this->subscription('sub_t', 'cus_owner_dup', '2026-11-01', '2026-12-01')));

        $this->assertSame(1, CustomerBillingPeriod::query()->count());
        $this->assertSame($owner->id, CustomerBillingPeriod::query()->sole()->customer_id);
    }

    // ── mid-period changes ─────────────────────────────────────────────────

    public function test_a_monthly_to_yearly_reset_truncates_the_old_period_instead_of_overlapping_it(): void
    {
        $customer = $this->customer('cus_switch');
        $recorder = app(CustomerBillingPeriodRecorder::class);

        $recorder->recordStripeSubscription($customer, $this->subscription('sub_s', 'cus_switch', '2026-10-01 00:00:00', '2026-11-01 00:00:00'), 1_000);
        $recorder->recordStripeSubscription($customer, $this->subscription('sub_s', 'cus_switch', '2026-10-15 12:00:00', '2027-10-15 12:00:00', 'year'), 2_000);
        // A late copy of the pre-switch state must not re-extend the old period.
        $recorder->recordStripeSubscription($customer, ['cancel_at_period_end' => true] + $this->subscription('sub_s', 'cus_switch', '2026-10-01 00:00:00', '2026-11-01 00:00:00'), 1_500);

        $this->assertSame([['2026-10-01', '2026-10-15'], ['2026-10-15', '2027-10-15']], $this->periods($customer));
        $this->assertSame('year', CustomerBillingPeriod::query()->orderByDesc('period_start')->first()->interval);

        $resolver = app(CustomerBillingPeriodResolver::class);
        $this->assertSame('2026-10-01', $resolver->at($customer, CarbonImmutable::parse('2026-10-15 11:59:59'))->start->toDateString());
        $this->assertSame('2026-10-15', $resolver->at($customer, CarbonImmutable::parse('2026-10-15 12:00:00'))->start->toDateString());
    }

    public function test_an_immediate_cancellation_ends_the_period_when_it_ended_and_a_reactivation_starts_a_new_one(): void
    {
        $customer = $this->customer('cus_cancel');

        // Seeded directly: through the `updated` webhook Cashier would also create the local
        // subscription row, and the `deleted` handler then asks Stripe to cancel it.
        app(CustomerBillingPeriodRecorder::class)->recordStripeSubscription($customer, $this->subscription('sub_c', 'cus_cancel', '2026-10-01 00:00:00', '2026-11-01 00:00:00'), 1_000);
        $this->postWebhook('customer.subscription.deleted', [
            'status' => 'canceled',
            'ended_at' => CarbonImmutable::parse('2026-10-10 09:00:00', 'UTC')->getTimestamp(),
        ] + $this->subscription('sub_c', 'cus_cancel', '2026-10-01 00:00:00', '2026-11-01 00:00:00'))->assertOk();

        $period = CustomerBillingPeriod::query()->sole();
        $this->assertSame('canceled', $period->subscription_status);
        $this->assertSame('2026-10-10 09:00:00', $period->period_end->format('Y-m-d H:i:s'));

        app(CustomerBillingPeriodRecorder::class)->recordStripeSubscription($customer, $this->subscription('sub_c2', 'cus_cancel', '2026-10-20 00:00:00', '2026-11-20 00:00:00'));

        $this->assertSame([['2026-10-01', '2026-10-10'], ['2026-10-20', '2026-11-20']], $this->periods($customer));
    }

    public function test_a_cancellation_at_period_end_keeps_the_period_whole(): void
    {
        $customer = $this->customer('cus_pe');

        app(CustomerBillingPeriodRecorder::class)->recordStripeSubscription($customer, ['cancel_at_period_end' => true] + $this->subscription('sub_pe', 'cus_pe', '2026-10-01', '2026-11-01'));

        $period = CustomerBillingPeriod::query()->sole();
        $this->assertTrue($period->cancel_at_period_end);
        $this->assertSame('2026-11-01', $period->period_end->toDateString());
    }

    // ── resolver ───────────────────────────────────────────────────────────

    public function test_the_period_boundary_is_start_inclusive_end_exclusive(): void
    {
        $customer = $this->customer('cus_bound');
        $recorder = app(CustomerBillingPeriodRecorder::class);
        $recorder->recordStripeSubscription($customer, $this->subscription('sub_b', 'cus_bound', '2026-09-05 00:00:00', '2026-10-05 00:00:00'));
        $recorder->recordStripeSubscription($customer, $this->subscription('sub_b', 'cus_bound', '2026-10-05 00:00:00', '2026-11-05 00:00:00'));
        $resolver = app(CustomerBillingPeriodResolver::class);

        $before = $resolver->at($customer, CarbonImmutable::parse('2026-10-04 23:59:59', 'UTC'));
        $at = $resolver->at($customer, CarbonImmutable::parse('2026-10-05 00:00:00', 'UTC'));

        $this->assertSame('2026-09-05', $before->start->toDateString());
        $this->assertSame('2026-10-05', $at->start->toDateString());
        $this->assertSame(BillingPeriod::SOURCE_PROVIDER, $at->source);
        $this->assertFalse($before->contains($at->start), 'The renewal instant belongs to exactly one period.');
        $this->assertTrue($at->contains($at->start));
    }

    public function test_the_resolver_reads_only_the_local_database(): void
    {
        $customer = $this->customer('cus_local');
        app(CustomerBillingPeriodRecorder::class)->recordStripeSubscription($customer, $this->subscription('sub_l', 'cus_local', '2026-10-05', '2026-11-05'));
        Http::fake();
        Config::set('cashier.secret', null);

        $period = app(CustomerBillingPeriodResolver::class)->at($customer, CarbonImmutable::parse('2026-10-20', 'UTC'));

        $this->assertSame('2026-10-05', $period->start->toDateString());
        Http::assertNothingSent();
    }

    public function test_a_manually_billed_customer_follows_its_explicit_anchor_never_a_calendar_month(): void
    {
        $customer = $this->customer(null, ['subscription_plan' => Customer::PLAN_ENTERPRISE, 'billing_interval' => Customer::BILLING_MONTHLY]);
        $customer->forceFill(['billing_anchor_at' => '2026-01-31 00:00:00'])->save();
        $resolver = app(CustomerBillingPeriodResolver::class);

        $feb = $resolver->at($customer, CarbonImmutable::parse('2026-03-01 10:00:00', 'UTC'));
        $oct = $resolver->at($customer, CarbonImmutable::parse('2026-10-31 00:00:00', 'UTC'));

        $this->assertSame(BillingPeriod::SOURCE_ANCHOR, $feb->source);
        $this->assertSame(['2026-02-28', '2026-03-31'], [$feb->start->toDateString(), $feb->end->toDateString()]);
        $this->assertSame(['2026-10-31', '2026-11-30'], [$oct->start->toDateString(), $oct->end->toDateString()]);
    }

    public function test_a_yearly_customer_without_an_anchor_follows_its_account_creation_date(): void
    {
        $customer = $this->customer(null, ['billing_interval' => Customer::BILLING_YEARLY]);
        $customer->forceFill(['created_at' => '2025-04-10 12:00:00'])->save();

        $period = app(CustomerBillingPeriodResolver::class)->at($customer->fresh(), CarbonImmutable::parse('2026-10-08', 'UTC'));

        $this->assertSame(BillingPeriod::SOURCE_ACCOUNT_CREATED, $period->source);
        $this->assertSame(BillingPeriod::INTERVAL_YEAR, $period->interval);
        $this->assertSame(['2026-04-10', '2027-04-10'], [$period->start->toDateString(), $period->end->toDateString()]);
    }

    public function test_a_derived_period_never_overlaps_a_provider_period(): void
    {
        $customer = $this->customer('cus_gap');
        $customer->forceFill(['billing_anchor_at' => '2026-01-01 00:00:00'])->save();
        app(CustomerBillingPeriodRecorder::class)->recordStripeSubscription($customer, $this->subscription('sub_g', 'cus_gap', '2026-10-15', '2026-11-15'));

        // Before the subscription started: the anchored October period, clipped at the provider period.
        $gap = app(CustomerBillingPeriodResolver::class)->at($customer->fresh(), CarbonImmutable::parse('2026-10-10', 'UTC'));

        $this->assertSame(['2026-10-01', '2026-10-15'], [$gap->start->toDateString(), $gap->end->toDateString()]);
    }

    public function test_periods_are_tenant_scoped(): void
    {
        $a = $this->customer('cus_a');
        $b = $this->customer('cus_b');
        $b->forceFill(['billing_anchor_at' => '2026-01-20 00:00:00'])->save();
        app(CustomerBillingPeriodRecorder::class)->recordStripeSubscription($a, $this->subscription('sub_a', 'cus_a', '2026-10-05', '2026-11-05'));

        $periodB = app(CustomerBillingPeriodResolver::class)->at($b->fresh(), CarbonImmutable::parse('2026-10-25', 'UTC'));

        $this->assertSame(BillingPeriod::SOURCE_ANCHOR, $periodB->source);
        $this->assertSame('2026-10-20', $periodB->start->toDateString());
    }

    // ── initial sync and ops ───────────────────────────────────────────────

    public function test_initial_sync_records_every_billing_subscription_and_runtime_check_flags_the_missing(): void
    {
        $synced = $this->customer('cus_sync');
        $this->localSubscription($synced, 'sub_sync');
        $ended = $this->customer('cus_ended');
        $this->localSubscription($ended, 'sub_ended', 'canceled');

        $this->assertSame(RuntimePreflightService::STATUS_WARN, $this->preflight()['status']);

        $now = CarbonImmutable::now('UTC');
        $this->partialMock(CustomerBillingPeriodRecorder::class, function ($mock) use ($now): void {
            $mock->shouldReceive('fetchStripeSubscription')->twice()->andReturnUsing(fn (Subscription $subscription): array => $this->subscription(
                $subscription->stripe_id,
                'cus_sync',
                $now->subDays(3)->toDateTimeString(),
                $now->addDays(27)->toDateTimeString(),
            ));
        });

        $this->artisan('billing:sync-subscriptions')->assertExitCode(0);
        $this->artisan('billing:sync-subscriptions')->assertExitCode(0);

        $this->assertSame(1, CustomerBillingPeriod::query()->where('customer_id', $synced->id)->count());
        $this->assertSame(0, CustomerBillingPeriod::query()->where('customer_id', $ended->id)->count());
        $this->assertSame(RuntimePreflightService::STATUS_PASS, $this->preflight()['status']);
    }

    // ── fixtures ───────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function subscription(string $id, string $stripeCustomer, string $start, string $end, string $interval = 'month'): array
    {
        return [
            'id' => $id,
            'object' => 'subscription',
            'customer' => $stripeCustomer,
            'status' => 'active',
            'cancel_at_period_end' => false,
            'metadata' => [],
            'items' => ['data' => [[
                'id' => 'si_'.$id,
                'quantity' => 1,
                'current_period_start' => CarbonImmutable::parse($start, 'UTC')->getTimestamp(),
                'current_period_end' => CarbonImmutable::parse($end, 'UTC')->getTimestamp(),
                'price' => ['id' => 'price_'.$interval, 'product' => 'prod_test', 'recurring' => ['interval' => $interval, 'interval_count' => 1]],
            ]]],
        ];
    }

    /** @param array<string, mixed> $object */
    private function postWebhook(string $type, array $object)
    {
        Config::set('cashier.webhook.secret', self::SECRET);
        $payload = (string) json_encode([
            'id' => 'evt_'.Str::random(10),
            'type' => $type,
            'created' => time(),
            'data' => ['object' => $object],
        ]);
        $timestamp = time();

        return $this->call('POST', '/stripe/webhook', [], [], [], $this->transformHeadersToServerVars([
            'Content-Type' => 'application/json',
            'Stripe-Signature' => sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp.'.'.$payload, self::SECRET)),
        ]), $payload);
    }

    private function localSubscription(Customer $customer, string $stripeId, string $status = 'active'): void
    {
        $customer->subscriptions()->create([
            'type' => 'default', 'stripe_id' => $stripeId, 'stripe_status' => $status,
            'stripe_price' => 'price_month', 'quantity' => 1,
        ]);
    }

    /** @return list<array{0: string, 1: string}> */
    private function periods(Customer $customer): array
    {
        return CustomerBillingPeriod::query()->where('customer_id', $customer->id)->orderBy('period_start')->get()
            ->map(fn (CustomerBillingPeriod $period): array => [$period->period_start->toDateString(), $period->period_end->toDateString()])
            ->all();
    }

    /** @return array{name: string, status: string, detail: string, critical: bool} */
    private function preflight(): array
    {
        return collect(app(RuntimePreflightService::class)->run())->firstWhere('name', 'Billing periods');
    }

    /** @param array<string, mixed> $attributes */
    private function customer(?string $stripeId, array $attributes = []): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        $customer = Customer::query()->create(array_merge([
            'name' => 'Billing '.Str::random(8),
            'slug' => 'billing-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
            'subscription_plan' => Customer::PLAN_PRO,
            'billing_interval' => Customer::BILLING_MONTHLY,
        ], $attributes));

        if ($stripeId !== null) {
            $customer->forceFill(['stripe_id' => $stripeId])->save();
        }

        return $customer->fresh();
    }
}
