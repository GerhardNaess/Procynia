<?php

namespace Tests\Feature\Services;

use App\Data\Ai\AiCallContext;
use App\Data\Ai\AiQuotaPolicy;
use App\Exceptions\Ai\AiCostControlException;
use App\Models\Customer;
use App\Models\CustomerAiCaseUsage;
use App\Models\CustomerAiUsageReservation;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Services\Ai\Commercial\AiCostControlService;
use App\Services\Ai\Commercial\AiRuntimeControlService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiCostControlServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_finite_new_case_is_reserved_then_committed_once(): void
    {
        Carbon::setTestNow('2026-08-15 10:00:00');
        $customer = $this->customer(3);
        $notice = $this->notice($customer, 'one');
        $service = app(AiCostControlService::class);

        $decision = $service->authorize($this->caseContext($customer, $notice));
        $this->assertNotNull($decision->reservationId);
        $service->finalize($decision);

        $this->assertDatabaseHas('customer_ai_usage_reservations', ['id' => $decision->reservationId, 'status' => CustomerAiUsageReservation::STATUS_COMMITTED]);
        $this->assertDatabaseHas('customer_ai_case_usages', ['customer_id' => $customer->id, 'saved_notice_id' => $notice->id, 'period_start' => '2026-08-01']);
    }

    public function test_exhaustion_blocks_new_cases_but_not_an_already_activated_case(): void
    {
        Carbon::setTestNow('2026-08-15 10:00:00');
        $customer = $this->customer(1);
        $existing = $this->notice($customer, 'existing');
        $new = $this->notice($customer, 'new');
        CustomerAiCaseUsage::query()->create([
            'customer_id' => $customer->id, 'saved_notice_id' => $existing->id, 'activated_at' => now(),
            'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'source_operation_key' => 'test',
        ]);
        $service = app(AiCostControlService::class);

        $existingDecision = $service->authorize($this->caseContext($customer, $existing));
        $this->assertNull($existingDecision->reservationId);
        $this->expectExceptionObject(new AiCostControlException(AiCostControlException::QUOTA_EXHAUSTED));
        $service->authorize($this->caseContext($customer, $new));
    }

    public function test_a_reserved_credit_prevents_a_second_parallel_candidate_from_overshooting(): void
    {
        $customer = $this->customer(1);
        $first = $this->notice($customer, 'first');
        $second = $this->notice($customer, 'second');
        $service = app(AiCostControlService::class);

        $reservation = $service->authorize($this->caseContext($customer, $first));
        $this->assertNotNull($reservation->reservationId);
        try {
            $service->authorize($this->caseContext($customer, $second));
            $this->fail('The active reservation must consume the final available slot.');
        } catch (AiCostControlException $exception) {
            $this->assertSame(AiCostControlException::QUOTA_EXHAUSTED, $exception->reason);
        }
    }

    public function test_none_unlimited_and_runtime_stops_are_explicit(): void
    {
        $none = $this->customer(0, Customer::PLAN_FREE);
        $unlimited = $this->customer(0, Customer::PLAN_ENTERPRISE);
        $notice = $this->notice($unlimited, 'unlimited');
        $service = app(AiCostControlService::class);

        try {
            $service->authorize($this->caseContext($none, $this->notice($none, 'none')));
            $this->fail('No entitlement must be blocked.');
        } catch (AiCostControlException $exception) {
            $this->assertSame(AiCostControlException::NOT_INCLUDED, $exception->reason);
        }
        $this->assertSame(AiQuotaPolicy::UNLIMITED, $service->authorize($this->caseContext($unlimited, $notice))->policy);

        app(AiRuntimeControlService::class)->setCustomerAccess($unlimited, Customer::AI_ACCESS_SUSPENDED, reason: 'test');
        try {
            $service->authorize($this->caseContext($unlimited, $notice));
            $this->fail('Suspended customer must be blocked.');
        } catch (AiCostControlException $exception) {
            $this->assertSame(AiCostControlException::CUSTOMER_SUSPENDED, $exception->reason);
        }
        app(AiRuntimeControlService::class)->setCustomerAccess($unlimited, Customer::AI_ACCESS_ENABLED, reason: 'test');
        app(AiRuntimeControlService::class)->setGlobalStop(true, reason: 'test');
        try {
            $service->authorize($this->caseContext($unlimited, $notice));
            $this->fail('Global stop must be blocked.');
        } catch (AiCostControlException $exception) {
            $this->assertSame(AiCostControlException::GLOBAL_STOP, $exception->reason);
        }
    }

    private function caseContext(Customer $customer, SavedNotice $notice): AiCallContext
    {
        return new AiCallContext(customerId: $customer->id, feature: 'saved_notice', operation: 'saved_notice.test', savedNoticeId: $notice->id, commercialCredit: true);
    }

    private function customer(int $credits, string $plan = Customer::PLAN_PRO): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create(['name' => 'Cost control '.Str::random(8), 'slug' => 'cost-'.Str::lower(Str::random(10)), 'language_id' => $language->id, 'nationality_id' => $nationality->id, 'is_active' => true, 'subscription_plan' => $plan, 'included_ai_credits' => $credits]);
    }

    private function notice(Customer $customer, string $key): SavedNotice
    {
        return SavedNotice::query()->create(['customer_id' => $customer->id, 'external_id' => 'COST-'.$key.'-'.Str::random(6), 'title' => 'Cost control notice', 'buyer_name' => 'Procynia', 'status' => 'ACTIVE']);
    }
}
