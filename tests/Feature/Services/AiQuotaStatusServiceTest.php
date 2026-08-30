<?php

namespace Tests\Feature\Services;

use App\Data\Ai\AiQuotaPolicy;
use App\Data\Ai\AiQuotaStatus;
use App\Models\Customer;
use App\Models\CustomerAiCaseUsage;
use App\Models\CustomerAiQuotaPeriod;
use App\Models\CustomerAiUsageReservation;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Services\Ai\Commercial\AiQuotaStatusService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The one calculation every customer-facing surface reads. */
class AiQuotaStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-15 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_unused_finite_quota_is_normal(): void
    {
        $status = $this->statusFor($this->customer(3));

        $this->assertSame(AiQuotaPolicy::FINITE, $status->quotaType);
        $this->assertSame(3, $status->included);
        $this->assertSame(0, $status->used);
        $this->assertSame(3, $status->remaining);
        $this->assertSame(0, $status->percentageUsed);
        $this->assertSame(AiQuotaStatus::STATUS_NORMAL, $status->status);
    }

    public function test_thresholds_move_the_status_through_normal_warning_critical_and_exhausted(): void
    {
        foreach ([
            [3, 2, 67, AiQuotaStatus::STATUS_NORMAL],
            [10, 8, 80, AiQuotaStatus::STATUS_WARNING],
            [10, 9, 90, AiQuotaStatus::STATUS_CRITICAL],
            [10, 10, 100, AiQuotaStatus::STATUS_EXHAUSTED],
        ] as [$included, $used, $expectedPercentage, $expectedStatus]) {
            $customer = $this->customer($included);
            $this->consume($customer, $used);
            $status = $this->statusFor($customer);

            $this->assertSame($expectedPercentage, $status->percentageUsed, "percentage for {$used}/{$included}");
            $this->assertSame($expectedStatus, $status->status, "status for {$used}/{$included}");
            $this->assertSame($included - $used, $status->remaining, "remaining for {$used}/{$included}");
        }
    }

    public function test_usage_above_the_allowance_is_exhausted_with_a_non_negative_remainder(): void
    {
        $customer = $this->customer(20);
        $this->consume($customer, 10);
        $customer->update(['subscription_plan' => Customer::PLAN_PRO, 'included_ai_credits' => 3]);

        $status = $this->statusFor($customer->fresh());

        $this->assertSame(10, $status->used);
        $this->assertSame(3, $status->included);
        $this->assertSame(0, $status->remaining, 'A downgrade must never show a negative remainder.');
        $this->assertSame(100, $status->percentageUsed);
        $this->assertSame(AiQuotaStatus::STATUS_EXHAUSTED, $status->status);
    }

    public function test_an_active_reservation_is_counted_against_the_remainder(): void
    {
        $customer = $this->customer(3);
        $this->consume($customer, 2);
        CustomerAiUsageReservation::query()->create([
            'customer_id' => $customer->id,
            'saved_notice_id' => $this->notice($customer)->id,
            'period_start' => '2026-08-01', 'period_end' => '2026-08-31',
            'operation' => 'test', 'status' => CustomerAiUsageReservation::STATUS_RESERVED, 'reserved_at' => now(),
        ]);

        $status = $this->statusFor($customer);

        // The third credit is already spoken for by a running job; showing "1 left" would be a lie.
        $this->assertSame(2, $status->used);
        $this->assertSame(1, $status->reserved);
        $this->assertSame(0, $status->remaining);
        $this->assertSame(AiQuotaStatus::STATUS_EXHAUSTED, $status->status);
    }

    public function test_extra_credits_raise_the_allowance(): void
    {
        $customer = $this->customer(3);
        $this->consume($customer, 3);
        CustomerAiQuotaPeriod::query()->create([
            'customer_id' => $customer->id, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31',
            'extra_credits' => 2,
        ]);

        $status = $this->statusFor($customer);

        $this->assertSame(2, $status->extra);
        $this->assertSame(5, $status->allowance());
        $this->assertSame(2, $status->remaining);
        $this->assertSame(AiQuotaStatus::STATUS_NORMAL, $status->status);
    }

    public function test_an_unlimited_plan_never_reports_a_percentage_or_exhaustion(): void
    {
        $customer = $this->customer(0, Customer::PLAN_ENTERPRISE);
        $this->consume($customer, 40);

        $status = $this->statusFor($customer);

        $this->assertTrue($status->isUnlimited);
        $this->assertNull($status->percentageUsed);
        $this->assertNull($status->remaining);
        $this->assertSame(AiQuotaStatus::STATUS_NORMAL, $status->status);
        $this->assertFalse($status->hasFiniteQuota());
    }

    public function test_a_plan_without_ai_reports_the_none_quota_type_rather_than_a_spent_quota(): void
    {
        $status = $this->statusFor($this->customer(0, Customer::PLAN_FREE));

        $this->assertSame(AiQuotaPolicy::NONE, $status->quotaType);
        $this->assertNull($status->percentageUsed, 'A plan with no AI has no percentage to report.');
        $this->assertSame(AiQuotaStatus::STATUS_NORMAL, $status->status);
        $this->assertFalse($status->hasFiniteQuota());
    }

    public function test_suspension_overrides_the_quota_status_without_losing_the_numbers(): void
    {
        $customer = $this->customer(10);
        $this->consume($customer, 2);
        $customer->update(['ai_access_status' => Customer::AI_ACCESS_SUSPENDED]);

        $status = $this->statusFor($customer->fresh());

        $this->assertSame(AiQuotaStatus::STATUS_SUSPENDED, $status->status);
        $this->assertTrue($status->isSuspended);
        $this->assertSame(2, $status->used, 'Suspension must not erase the real usage figures.');
        $this->assertSame(8, $status->remaining);
    }

    public function test_a_new_calendar_month_starts_from_zero(): void
    {
        $customer = $this->customer(3);
        $this->consume($customer, 3);
        $this->assertSame(AiQuotaStatus::STATUS_EXHAUSTED, $this->statusFor($customer)->status);

        Carbon::setTestNow('2026-09-02 09:00:00');
        $september = $this->statusFor($customer);

        $this->assertSame(0, $september->used);
        $this->assertSame(3, $september->remaining);
        $this->assertSame('2026-09-01', $september->periodStart);
        $this->assertSame(AiQuotaStatus::STATUS_NORMAL, $september->status);
    }

    private function statusFor(Customer $customer): AiQuotaStatus
    {
        return app(AiQuotaStatusService::class)->forCustomer($customer);
    }

    private function consume(Customer $customer, int $cases): void
    {
        for ($index = 0; $index < $cases; $index++) {
            CustomerAiCaseUsage::query()->create([
                'customer_id' => $customer->id,
                'saved_notice_id' => $this->notice($customer)->id,
                'activated_at' => now(),
                'period_start' => '2026-08-01', 'period_end' => '2026-08-31',
                'source_operation_key' => 'test',
            ]);
        }
    }

    private function notice(Customer $customer): SavedNotice
    {
        return SavedNotice::query()->create([
            'customer_id' => $customer->id,
            'external_id' => 'QS-'.Str::random(10),
            'title' => 'Quota status notice',
            'buyer_name' => 'Procynia',
            'status' => 'ACTIVE',
        ]);
    }

    private function customer(int $credits, string $plan = Customer::PLAN_PRO): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Quota '.Str::random(8),
            'slug' => 'quota-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
            'subscription_plan' => $plan,
            'included_ai_credits' => $credits,
        ]);
    }
}
