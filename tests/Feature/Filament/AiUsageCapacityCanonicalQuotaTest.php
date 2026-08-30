<?php

namespace Tests\Feature\Filament;

use App\Models\AiUsageEvent;
use App\Models\Customer;
use App\Models\CustomerAiCaseUsage;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Models\User;
use App\Services\Ai\AiUsageReportingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The internal capacity column used to compare a 30-day count of AI *operations* against the number
 * of AI *cases* the plan includes — different units, different windows — so a customer could read
 * as "over capacity" purely from ordinary activity. It now reports the canonical commercial quota.
 */
class AiUsageCapacityCanonicalQuotaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-15 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_operation_volume_alone_never_reports_a_customer_as_over_capacity(): void
    {
        $customer = $this->customer(3);
        $user = $this->user($customer);

        // Far more AI operations than the plan has AI cases, but not one case activated.
        $this->recordOperations($customer, $user, 40);

        $row = $this->rowFor($customer);

        $this->assertSame('within', $row['capacity']['status'], 'Activity volume is not commercial usage.');
        $this->assertSame(0, $row['capacity']['value'] === null ? 0 : (int) $this->usedFromLabel($row));
        $this->assertSame(40, (int) $row['periods']['30d'], 'Operation counts remain visible as activity.');
    }

    public function test_the_capacity_column_reports_the_commercial_quota(): void
    {
        $customer = $this->customer(10);
        $this->consume($customer, 9);

        $row = $this->rowFor($customer);

        $this->assertSame('near', $row['capacity']['status']);
        $this->assertSame(10, $row['capacity']['value']);
        $this->assertStringContainsString('9', $row['capacity']['label']);
        $this->assertStringContainsString('2026-09-01', $row['capacity']['source_label']);
    }

    public function test_an_exhausted_customer_reports_over_capacity(): void
    {
        $customer = $this->customer(3);
        $this->consume($customer, 3);

        $this->assertSame('over', $this->rowFor($customer)['capacity']['status']);
    }

    public function test_a_suspended_customer_reports_over_capacity_regardless_of_usage(): void
    {
        $customer = $this->customer(10);
        $this->consume($customer, 1);
        $customer->update(['ai_access_status' => Customer::AI_ACCESS_SUSPENDED]);

        $this->assertSame('over', $this->rowFor($customer->fresh())['capacity']['status']);
    }

    public function test_an_unlimited_plan_is_labelled_unlimited_rather_than_a_number(): void
    {
        $customer = $this->customer(0, Customer::PLAN_ENTERPRISE);
        $this->consume($customer, 25);

        $row = $this->rowFor($customer);

        $this->assertTrue($row['capacity']['defined']);
        $this->assertNull($row['capacity']['value']);
        $this->assertSame(__('procynia.ai_quota.unlimited'), $row['capacity']['label']);
        $this->assertSame('within', $row['capacity']['status']);
    }

    public function test_a_plan_without_ai_is_reported_as_not_included(): void
    {
        $row = $this->rowFor($this->customer(0, Customer::PLAN_FREE));

        $this->assertFalse($row['capacity']['defined']);
        $this->assertSame('undefined', $row['capacity']['status']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** @return array<string, mixed> */
    private function rowFor(Customer $customer): array
    {
        $report = app(AiUsageReportingService::class)->report();
        $row = collect($report['customers'])->firstWhere('id', $customer->id);

        $this->assertNotNull($row, 'The customer is missing from the capacity report.');

        return $row;
    }

    private function usedFromLabel(array $row): string
    {
        preg_match('/\d+/', (string) $row['capacity']['label'], $matches);

        return $matches[0] ?? '0';
    }

    private function recordOperations(Customer $customer, User $user, int $count): void
    {
        for ($index = 0; $index < $count; $index++) {
            AiUsageEvent::query()->create([
                'customer_id' => $customer->id,
                'user_id' => $user->id,
                'operation_key' => 'saved_notice_requirement_wiki_answer',
                'operation_count' => 1,
                'status' => AiUsageEvent::STATUS_ALLOWED,
                'limit_type' => null,
                'occurred_at' => now()->subDays(2),
            ]);
        }
    }

    private function consume(Customer $customer, int $cases): void
    {
        for ($index = 0; $index < $cases; $index++) {
            CustomerAiCaseUsage::query()->create([
                'customer_id' => $customer->id,
                'saved_notice_id' => SavedNotice::query()->create([
                    'customer_id' => $customer->id,
                    'external_id' => 'CAP-'.Str::random(10),
                    'title' => 'Capacity notice',
                    'buyer_name' => 'Procynia',
                    'status' => 'ACTIVE',
                ])->id,
                'activated_at' => now(),
                'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
                'source_operation_key' => 'test',
            ]);
        }
    }

    private function user(Customer $customer): User
    {
        return User::query()->create([
            'customer_id' => $customer->id,
            'name' => 'User '.Str::random(6),
            'email' => Str::lower(Str::random(10)).'@customer.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);
    }

    private function customer(int $credits, string $plan = Customer::PLAN_PRO): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Capacity '.Str::random(8),
            'slug' => 'capacity-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
            'subscription_plan' => $plan,
            'included_ai_credits' => $credits,
        ]);
    }
}
