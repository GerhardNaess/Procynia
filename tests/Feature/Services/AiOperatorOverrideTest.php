<?php

namespace Tests\Feature\Services;

use App\Data\Ai\AiCallContext;
use App\Exceptions\Ai\AiCostControlException;
use App\Models\Customer;
use App\Models\CustomerAiCaseUsage;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Models\User;
use App\Services\Ai\Commercial\AiCostControlService;
use App\Services\Ai\Commercial\AiRuntimeControlService;
use App\Services\OpenAi\OpenAiClient;
use App\Support\Ai\AiCallContextScope;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The operator escape hatch: what it may relax, what it may never relax, and what it must record.
 */
class AiOperatorOverrideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-15 10:00:00');
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.base_url', 'https://openai.test/v1');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // =========================================================================
    // What an override may relax
    // =========================================================================

    public function test_an_override_lets_a_recovery_run_for_a_suspended_customer(): void
    {
        $customer = $this->customer(3);
        $admin = $this->admin();
        app(AiRuntimeControlService::class)->setCustomerAccess($customer, Customer::AI_ACCESS_SUSPENDED, reason: 'test');

        $decision = app(AiCostControlService::class)->authorize($this->context($customer->fresh(), $admin, override: true));

        $this->assertNotNull($decision);
        $this->assertDatabaseHas('billing_events', [
            'customer_id' => $customer->id,
            'user_id' => $admin->id,
            'event_type' => 'ai_operator_override_used',
        ]);
    }

    public function test_an_override_lets_a_recovery_run_past_an_exhausted_quota(): void
    {
        $customer = $this->customer(1);
        $admin = $this->admin();
        $this->consume($customer, 1);
        $notice = $this->notice($customer);

        $decision = app(AiCostControlService::class)->authorize(
            $this->context($customer, $admin, override: true, savedNoticeId: $notice->id, commercialCredit: true),
        );

        // The credit is still reserved: an override changes what is permitted, not what is recorded.
        $this->assertNotNull($decision->reservationId);
        $this->assertDatabaseHas('billing_events', [
            'customer_id' => $customer->id,
            'event_type' => 'ai_operator_override_used',
        ]);
    }

    public function test_an_override_lets_a_recovery_run_for_a_plan_without_ai(): void
    {
        $customer = $this->customer(0, Customer::PLAN_FREE);
        $admin = $this->admin();

        $decision = app(AiCostControlService::class)->authorize($this->context($customer, $admin, override: true));

        $this->assertNotNull($decision);
        $this->assertDatabaseHas('billing_events', [
            'customer_id' => $customer->id,
            'event_type' => 'ai_operator_override_used',
        ]);
    }

    // =========================================================================
    // What an override may never relax
    // =========================================================================

    public function test_the_global_emergency_stop_cannot_be_overridden(): void
    {
        Http::fake();
        $customer = $this->customer(3);
        $admin = $this->admin();
        app(AiRuntimeControlService::class)->setGlobalStop(true, reason: 'provider incident');

        try {
            app(AiCostControlService::class)->authorize($this->context($customer, $admin, override: true));
            $this->fail('An operator override must never reach past the global emergency stop.');
        } catch (AiCostControlException $exception) {
            $this->assertSame(AiCostControlException::GLOBAL_STOP, $exception->reason);
        }

        // And no bypass was recorded, because none happened.
        $this->assertDatabaseMissing('billing_events', ['event_type' => 'ai_operator_override_used']);
    }

    public function test_a_provider_call_is_still_refused_under_global_stop_even_with_an_override(): void
    {
        Http::fake(['https://openai.test/v1/responses' => Http::response(['status' => 'completed'], 200)]);
        $customer = $this->customer(3);
        $admin = $this->admin();
        app(AiRuntimeControlService::class)->setGlobalStop(true, reason: 'provider incident');

        try {
            app(AiCallContextScope::class)->within(
                $this->context($customer, $admin, override: true),
                fn (): array => app(OpenAiClient::class)->createResponse(['model' => 'gpt-5', 'input' => []]),
            );
            $this->fail('The provider boundary must refuse this call.');
        } catch (AiCostControlException $exception) {
            $this->assertSame(AiCostControlException::GLOBAL_STOP, $exception->reason);
        }

        Http::assertNothingSent();
    }

    // =========================================================================
    // An override has to be complete to count
    // =========================================================================

    public function test_an_override_without_an_actor_or_reason_does_not_bypass_anything(): void
    {
        $customer = $this->customer(3);
        app(AiRuntimeControlService::class)->setCustomerAccess($customer, Customer::AI_ACCESS_SUSPENDED, reason: 'test');
        $service = app(AiCostControlService::class);

        foreach ([
            'no actor' => new AiCallContext(customerId: $customer->id, operation: 'op', operatorOverride: true, operatorOverrideReason: 'fordi'),
            'no reason' => new AiCallContext(customerId: $customer->id, operation: 'op', operatorOverride: true, operatorActorUserId: $this->admin()->id),
            'not requested' => new AiCallContext(customerId: $customer->id, operation: 'op'),
        ] as $label => $context) {
            try {
                $service->authorize($context);
                $this->fail("An incomplete override ({$label}) must not bypass the suspension.");
            } catch (AiCostControlException $exception) {
                $this->assertSame(AiCostControlException::CUSTOMER_SUSPENDED, $exception->reason, $label);
            }
        }

        $this->assertDatabaseMissing('billing_events', ['event_type' => 'ai_operator_override_used']);
    }

    public function test_a_normal_call_for_a_healthy_customer_records_no_override(): void
    {
        $customer = $this->customer(3);

        app(AiCostControlService::class)->authorize(new AiCallContext(customerId: $customer->id, operation: 'op'));

        $this->assertDatabaseMissing('billing_events', ['event_type' => 'ai_operator_override_used']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function context(
        Customer $customer,
        User $actor,
        bool $override = false,
        ?int $savedNoticeId = null,
        bool $commercialCredit = false,
    ): AiCallContext {
        return new AiCallContext(
            customerId: $customer->id,
            feature: 'enterprise_wiki',
            operation: 'operator.wiki.recover_document_flow',
            savedNoticeId: $savedNoticeId,
            commercialCredit: $commercialCredit,
            operatorOverride: $override,
            operatorActorUserId: $override ? $actor->id : null,
            operatorOverrideReason: $override ? 'Gjenoppretting av fastlåst kjøring' : null,
        );
    }

    private function consume(Customer $customer, int $cases): void
    {
        for ($index = 0; $index < $cases; $index++) {
            CustomerAiCaseUsage::query()->create([
                'customer_id' => $customer->id,
                'saved_notice_id' => $this->notice($customer)->id,
                'activated_at' => now(),
                'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
                'source_operation_key' => 'test',
            ]);
        }
    }

    private function notice(Customer $customer): SavedNotice
    {
        return SavedNotice::query()->create([
            'customer_id' => $customer->id,
            'external_id' => 'OVR-'.Str::random(10),
            'title' => 'Override notice',
            'buyer_name' => 'Procynia',
            'status' => 'ACTIVE',
        ]);
    }

    private function admin(): User
    {
        return User::query()->create([
            'customer_id' => null,
            'name' => 'Procynia Admin',
            'email' => 'admin-'.Str::lower(Str::random(8)).'@procynia.test',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_SUPER_ADMIN,
            'is_active' => true,
        ]);
    }

    private function customer(int $credits, string $plan = Customer::PLAN_PRO): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Override '.Str::random(8),
            'slug' => 'override-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
            'subscription_plan' => $plan,
            'included_ai_credits' => $credits,
        ]);
    }
}
