<?php

namespace Tests\Feature\Operational;

use App\Data\Ai\AiCallContext;
use App\Exceptions\Ai\AiCostControlException;
use App\Models\AiModelPrice;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\Ai\Commercial\AiCostControlService;
use App\Services\Ai\Operational\AiPaymentPolicyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** What a subscription state means for AI access, read fresh at every provider call. */
class AiPaymentPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-15 10:00:00');
        $this->seedPricing();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_payment_matrix_matches_the_agreed_policy(): void
    {
        foreach ([
            'active' => AiPaymentPolicyService::ALLOW,
            'trialing' => AiPaymentPolicyService::ALLOW,
            'unpaid' => AiPaymentPolicyService::BLOCK,
            'incomplete' => AiPaymentPolicyService::BLOCK,
            'incomplete_expired' => AiPaymentPolicyService::BLOCK,
            'canceled' => AiPaymentPolicyService::ALLOW,
        ] as $state => $expected) {
            $customer = $this->customer();
            $this->subscription($customer, $state);

            $evaluation = app(AiPaymentPolicyService::class)->evaluate($customer);

            $this->assertSame($expected, $evaluation['decision'], "state {$state}");
            $this->assertSame($state, $evaluation['state']);
        }
    }

    public function test_a_customer_without_a_stripe_link_falls_back_to_the_local_plan(): void
    {
        $evaluation = app(AiPaymentPolicyService::class)->evaluate($this->customer());

        $this->assertSame(AiPaymentPolicyService::ALLOW, $evaluation['decision']);
        $this->assertSame('none', $evaluation['state']);
    }

    public function test_past_due_is_allowed_inside_the_grace_window(): void
    {
        $customer = $this->customer();
        $this->subscription($customer, 'past_due', updatedAt: '2026-09-13 10:00:00');

        $evaluation = app(AiPaymentPolicyService::class)->evaluate($customer);

        $this->assertSame(AiPaymentPolicyService::GRACE, $evaluation['decision']);
        $this->assertSame('2026-09-20 10:00:00', $evaluation['grace_ends_at']);
    }

    public function test_past_due_blocks_once_the_grace_window_has_expired(): void
    {
        $customer = $this->customer();
        // The clock runs from when the subscription entered past_due, not from "now minus grace".
        $this->subscription($customer, 'past_due', updatedAt: '2026-09-01 10:00:00');

        $evaluation = app(AiPaymentPolicyService::class)->evaluate($customer);

        $this->assertSame(AiPaymentPolicyService::BLOCK, $evaluation['decision']);
        $this->assertSame(AiCostControlException::PAYMENT_UNPAID, $evaluation['reason']);
    }

    public function test_the_grace_period_is_configurable(): void
    {
        config()->set('procynia.ai.payment.past_due_grace_days', 1);
        $customer = $this->customer();
        $this->subscription($customer, 'past_due', updatedAt: '2026-09-13 10:00:00');

        $this->assertSame(
            AiPaymentPolicyService::BLOCK,
            app(AiPaymentPolicyService::class)->evaluate($customer)['decision'],
        );
    }

    // =========================================================================
    // Enforcement at the guard
    // =========================================================================

    public function test_an_unpaid_subscription_blocks_the_provider_call(): void
    {
        $customer = $this->customer();
        $this->subscription($customer, 'unpaid');

        try {
            app(AiCostControlService::class)->authorize($this->context($customer));
            $this->fail('An unpaid subscription must stop new AI work.');
        } catch (AiCostControlException $exception) {
            $this->assertSame(AiCostControlException::PAYMENT_UNPAID, $exception->reason);
        }
    }

    public function test_an_incomplete_subscription_blocks_the_provider_call(): void
    {
        $customer = $this->customer();
        $this->subscription($customer, 'incomplete');

        $this->expectExceptionObject(new AiCostControlException(AiCostControlException::PAYMENT_INCOMPLETE));
        app(AiCostControlService::class)->authorize($this->context($customer));
    }

    public function test_an_active_subscription_is_not_blocked_by_payment_state(): void
    {
        $customer = $this->customer();
        $this->subscription($customer, 'active');

        $this->assertNotNull(app(AiCostControlService::class)->authorize($this->context($customer)));
    }

    public function test_a_payment_block_raises_one_internal_alert_not_one_per_call(): void
    {
        $customer = $this->customer();
        $this->subscription($customer, 'unpaid');
        $service = app(AiCostControlService::class);

        foreach (range(1, 4) as $ignored) {
            try {
                $service->authorize($this->context($customer));
            } catch (AiCostControlException) {
                // expected
            }
        }

        $this->assertSame(
            1,
            DB::table('admin_notifications')->where('type', 'ai_payment_blocked')->count(),
            'A customer retrying in a loop must not generate an alert per attempt.',
        );
    }

    public function test_a_recovery_override_may_finish_work_for_a_non_paying_customer(): void
    {
        $customer = $this->customer();
        $admin = $this->admin();
        $this->subscription($customer, 'unpaid');

        $decision = app(AiCostControlService::class)->authorize(new AiCallContext(
            customerId: $customer->id,
            feature: 'enterprise_wiki',
            operation: 'operator.wiki.recover_document_flow',
            model: 'gpt-5',
            operatorOverride: true,
            operatorActorUserId: $admin->id,
            operatorOverrideReason: 'Fullfører fastlåst kjøring',
        ));

        $this->assertNotNull($decision);
        $this->assertDatabaseHas('billing_events', [
            'customer_id' => $customer->id,
            'user_id' => $admin->id,
            'event_type' => 'ai_operator_override_used',
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function context(Customer $customer): AiCallContext
    {
        return new AiCallContext(
            customerId: $customer->id, feature: 'wiki', operation: 'wiki.ask.answer', model: 'gpt-5',
        );
    }

    private function subscription(Customer $customer, string $status, ?string $updatedAt = null): void
    {
        DB::table('subscriptions')->insert([
            'customer_id' => $customer->id,
            'type' => 'default',
            'stripe_id' => 'sub_'.Str::lower(Str::random(14)),
            'stripe_status' => $status,
            'stripe_price' => 'price_test',
            'quantity' => 1,
            'created_at' => $updatedAt ?? now(),
            'updated_at' => $updatedAt ?? now(),
        ]);
    }

    private function seedPricing(): void
    {
        AiModelPrice::query()->create([
            'provider' => 'openai', 'model' => 'gpt-5', 'currency' => 'USD',
            'input_price_per_1m_tokens' => 10.0, 'output_price_per_1m_tokens' => 30.0,
            'valid_from' => '2026-01-01', 'is_active' => true, 'last_verified_at' => '2026-09-14 00:00:00',
        ]);
        ExchangeRate::query()->create([
            'base_currency' => 'USD', 'quote_currency' => 'NOK', 'rate' => 10.0,
            'rate_date' => '2026-09-15', 'source' => ExchangeRate::SOURCE_NORGES_BANK, 'fetched_at' => now(),
        ]);
    }

    private function admin(): User
    {
        return User::query()->create([
            'customer_id' => null, 'name' => 'Procynia Admin',
            'email' => 'admin-'.Str::lower(Str::random(8)).'@procynia.test',
            'password' => bcrypt('secret-password'), 'role' => User::ROLE_SUPER_ADMIN, 'is_active' => true,
        ]);
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Payment '.Str::random(8),
            'slug' => 'payment-'.Str::lower(Str::random(10)),
            'language_id' => $language->id, 'nationality_id' => $nationality->id, 'is_active' => true,
            'subscription_plan' => Customer::PLAN_PRO, 'included_ai_credits' => 10,
        ]);
    }
}
