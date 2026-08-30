<?php

namespace Tests\Feature\Services;

use App\Data\Ai\AiQuotaStatus;
use App\Models\Customer;
use App\Models\CustomerAiCaseUsage;
use App\Models\CustomerAiCreditAdjustment;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\AiQuotaNotification;
use App\Services\Ai\Commercial\AiCreditAdjustmentService;
use App\Services\Ai\Commercial\AiQuotaStatusService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/** Administrative capacity changes: append-only, audited, and never rewriting history. */
class AiCreditAdjustmentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-15 10:00:00');
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_granting_credits_raises_the_allowance_for_the_current_period(): void
    {
        $customer = $this->customer(3);
        $this->consume($customer, 3);

        $status = app(AiCreditAdjustmentService::class)->adjust($customer, 5, 'Kompensasjon for feilet kjøring', $this->admin());

        $this->assertSame(5, $status->extra);
        $this->assertSame(8, $status->allowance());
        $this->assertSame(5, $status->remaining);
        $this->assertSame(AiQuotaStatus::STATUS_NORMAL, $status->status);
        $this->assertDatabaseHas('customer_ai_credit_adjustments', [
            'customer_id' => $customer->id,
            'amount' => 5,
            'period_start' => '2026-09-01',
        ]);
    }

    public function test_a_negative_adjustment_reduces_the_allowance_without_touching_usage(): void
    {
        $customer = $this->customer(10);
        $this->consume($customer, 8);
        $service = app(AiCreditAdjustmentService::class);

        $service->adjust($customer, -5, 'Feilaktig tildelt kapasitet trekkes tilbake', $this->admin());
        $status = app(AiQuotaStatusService::class)->forCustomer($customer->fresh());

        // 8 used against an allowance of 5: honest about both figures, never a negative remainder.
        $this->assertSame(8, $status->used);
        $this->assertSame(5, $status->allowance());
        $this->assertSame(0, $status->remaining);
        $this->assertSame(AiQuotaStatus::STATUS_EXHAUSTED, $status->status);
        $this->assertSame(8, CustomerAiCaseUsage::query()->where('customer_id', $customer->id)->count());
    }

    public function test_an_over_large_withdrawal_floors_the_allowance_at_zero(): void
    {
        $customer = $this->customer(10);
        $service = app(AiCreditAdjustmentService::class);

        $service->adjust($customer, 2, 'Ekstra kapasitet', $this->admin());
        $status = $service->adjust($customer, -50, 'Overdreven korreksjon', $this->admin());

        // The ledger keeps the real numbers; only what the customer is allowed to spend is clamped.
        $this->assertSame(-48, $status->extra);
        $this->assertSame(0, $status->allowance());
        $this->assertSame(0, $status->remaining);
        $this->assertSame(AiQuotaStatus::STATUS_EXHAUSTED, $status->status);
        $this->assertDatabaseHas('customer_ai_credit_adjustments', ['customer_id' => $customer->id, 'amount' => -50]);
    }

    public function test_every_adjustment_is_kept_as_its_own_row(): void
    {
        $customer = $this->customer(3);
        $service = app(AiCreditAdjustmentService::class);

        $service->adjust($customer, 5, 'Første tildeling', $this->admin());
        $service->adjust($customer, 2, 'Andre tildeling', $this->admin());
        $service->adjust($customer, -1, 'Korreksjon', $this->admin());

        $this->assertSame(3, CustomerAiCreditAdjustment::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(6, app(AiQuotaStatusService::class)->forCustomer($customer->fresh())->extra);
    }

    public function test_an_adjustment_writes_an_audit_event_with_actor_and_reason(): void
    {
        $customer = $this->customer(3);
        $admin = $this->admin();

        app(AiCreditAdjustmentService::class)->adjust($customer, 4, 'Avtalt utvidelse i september', $admin);

        $this->assertDatabaseHas('billing_events', [
            'customer_id' => $customer->id,
            'user_id' => $admin->id,
            'event_type' => 'ai_credits_adjusted',
            'source' => 'ai_cost_control',
            'description' => 'Avtalt utvidelse i september',
        ]);
    }

    public function test_an_adjustment_requires_a_reason_and_a_non_zero_amount(): void
    {
        $customer = $this->customer(3);
        $service = app(AiCreditAdjustmentService::class);

        try {
            $service->adjust($customer, 5, '   ', $this->admin());
            $this->fail('An adjustment without a reason must be refused.');
        } catch (InvalidArgumentException) {
            // expected
        }

        try {
            $service->adjust($customer, 0, 'Ingen endring', $this->admin());
            $this->fail('A zero adjustment must be refused.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertDatabaseCount('customer_ai_credit_adjustments', 0);
    }

    public function test_the_system_owner_is_notified_about_a_capacity_change(): void
    {
        $customer = $this->customer(3);
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $contributor = $this->user($customer, User::BID_ROLE_CONTRIBUTOR);

        app(AiCreditAdjustmentService::class)->adjust($customer, 5, 'Ekstra kapasitet', $this->admin());

        Notification::assertSentToTimes($owner, AiQuotaNotification::class, 1);
        Notification::assertNothingSentTo($contributor);
        $this->assertSame(
            'ai_quota.credits_adjusted',
            UserNotification::query()->where('customer_id', $customer->id)->value('event_type'),
        );
    }

    public function test_a_new_period_is_unaffected_by_an_earlier_adjustment(): void
    {
        $customer = $this->customer(3);
        app(AiCreditAdjustmentService::class)->adjust($customer, 5, 'September-kapasitet', $this->admin());

        $this->assertSame(8, app(AiQuotaStatusService::class)->forCustomer($customer->fresh())->allowance());

        Carbon::setTestNow('2026-10-02 09:00:00');
        $october = app(AiQuotaStatusService::class)->forCustomer($customer->fresh());

        $this->assertSame(0, $october->extra, 'A period-scoped grant must not leak into the next month.');
        $this->assertSame(3, $october->allowance());
        $this->assertDatabaseHas('customer_ai_credit_adjustments', ['period_start' => '2026-09-01', 'amount' => 5]);
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

    private function user(Customer $customer, string $bidRole): User
    {
        return User::query()->create([
            'customer_id' => $customer->id,
            'name' => 'User '.Str::random(6),
            'email' => Str::lower(Str::random(10)).'@procynia.test',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_USER,
            'bid_role' => $bidRole,
            'is_active' => true,
        ]);
    }

    private function consume(Customer $customer, int $cases): void
    {
        for ($index = 0; $index < $cases; $index++) {
            CustomerAiCaseUsage::query()->create([
                'customer_id' => $customer->id,
                'saved_notice_id' => SavedNotice::query()->create([
                    'customer_id' => $customer->id,
                    'external_id' => 'ADJ-'.Str::random(10),
                    'title' => 'Adjustment notice',
                    'buyer_name' => 'Procynia',
                    'status' => 'ACTIVE',
                ])->id,
                'activated_at' => now(),
                'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
                'source_operation_key' => 'test',
            ]);
        }
    }

    private function customer(int $credits, string $plan = Customer::PLAN_PRO): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Adjust '.Str::random(8),
            'slug' => 'adjust-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
            'subscription_plan' => $plan,
            'included_ai_credits' => $credits,
        ]);
    }
}
