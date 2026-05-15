<?php

namespace Tests\Unit\Services\Ai;

use App\Models\AiUsageEvent;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\Ai\AiUsageGuard;
use App\Services\Ai\AiUsageReportingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiUsageReportingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_report_aggregates_ai_usage_by_customer_user_and_operation_key(): void
    {
        Carbon::setTestNow('2026-05-15 12:00:00');

        $customerA = $this->createCustomer('Kunde A', Customer::PLAN_PRO, 10);
        $customerB = $this->createCustomer('Kunde B', Customer::PLAN_ENTERPRISE, 0);

        $userA1 = $this->createUser($customerA, 'Bruker A1', 'bruker.a1@example.test');
        $userA2 = $this->createUser($customerA, 'Bruker A2', 'bruker.a2@example.test');
        $userB1 = $this->createUser($customerB, 'Bruker B1', 'bruker.b1@example.test');

        $this->createUsageEvent(
            $customerA,
            $userA1,
            AiUsageGuard::OPERATION_SAVED_NOTICE_REQUIREMENT_ANSWER_DRAFT,
            AiUsageEvent::STATUS_ALLOWED,
            null,
            6,
            '2026-05-15 11:00:00',
        );
        $this->createUsageEvent(
            $customerA,
            $userA2,
            AiUsageGuard::OPERATION_SAVED_NOTICE_ASSESSMENT_REFRESH,
            AiUsageEvent::STATUS_ALLOWED,
            null,
            3,
            '2026-05-15 10:00:00',
        );
        $this->createUsageEvent(
            $customerA,
            $userA2,
            AiUsageGuard::OPERATION_SAVED_NOTICE_ASSESSMENT_REFRESH,
            AiUsageEvent::STATUS_ALLOWED,
            null,
            1,
            '2026-05-07 12:00:00',
        );
        $this->createUsageEvent(
            $customerB,
            $userB1,
            AiUsageGuard::OPERATION_KNOWLEDGE_DOCUMENT_UPLOAD,
            AiUsageEvent::STATUS_ALLOWED,
            null,
            2,
            '2026-05-15 09:00:00',
        );
        $this->createUsageEvent(
            $customerB,
            $userB1,
            AiUsageGuard::OPERATION_KNOWLEDGE_DOCUMENT_UPLOAD,
            AiUsageEvent::STATUS_BLOCKED,
            AiUsageEvent::LIMIT_TYPE_CUSTOMER,
            1,
            '2026-05-15 09:30:00',
        );
        $this->createUsageEvent(
            $customerB,
            $userB1,
            AiUsageGuard::OPERATION_KNOWLEDGE_DOCUMENT_UPLOAD,
            AiUsageEvent::STATUS_BLOCKED,
            AiUsageEvent::LIMIT_TYPE_USER,
            1,
            '2026-05-15 09:45:00',
        );

        $report = app(AiUsageReportingService::class)->report();

        $this->assertSame('15.05.2026 12:00', $report['generated_at']);
        $this->assertSame(13, $this->summaryValue($report, __('procynia.ai_usage_capacity.summary.usage_24h')));
        $this->assertSame(13, $this->summaryValue($report, __('procynia.ai_usage_capacity.summary.usage_7d')));
        $this->assertSame(2, $this->summaryValue($report, __('procynia.ai_usage_capacity.summary.blocked_7d')));
        $this->assertSame(1, $this->summaryValue($report, __('procynia.ai_usage_capacity.summary.blocked_customers_30d')));
        $this->assertSame(1, $this->summaryValue($report, __('procynia.ai_usage_capacity.summary.blocked_users_30d')));

        $customerRowA = $this->customerRow($report, 'Kunde A');
        $this->assertTrue($customerRowA['capacity']['defined']);
        $this->assertSame('10', $customerRowA['capacity']['label']);
        $this->assertSame(__('procynia.ai_usage_capacity.capacity.customer_source'), $customerRowA['capacity']['source_label']);
        $this->assertSame('near', $customerRowA['capacity']['status']);
        $this->assertSame(10, $customerRowA['periods']['30d']);
        $this->assertSame(10, $customerRowA['counts']['allowed']);
        $this->assertSame(0, $customerRowA['counts']['blocked']);
        $this->assertSame(AiUsageGuard::OPERATION_SAVED_NOTICE_REQUIREMENT_ANSWER_DRAFT, $customerRowA['top_operation']['key']);
        $this->assertSame(6, $customerRowA['top_operation']['count']);

        $customerRowB = $this->customerRow($report, 'Kunde B');
        $this->assertFalse($customerRowB['capacity']['defined']);
        $this->assertSame(__('procynia.ai_usage_capacity.capacity.not_defined'), $customerRowB['capacity']['label']);
        $this->assertSame('undefined', $customerRowB['capacity']['status']);
        $this->assertSame(2, $customerRowB['counts']['blocked']);
        $this->assertSame(1, $customerRowB['counts']['blocked_customer']);
        $this->assertSame(1, $customerRowB['counts']['blocked_user']);

        $userRowB1 = $this->userRow($report, 'Bruker B1');
        $this->assertSame('Kunde B', $userRowB1['customer_name']);
        $this->assertSame(4, $userRowB1['periods']['30d']);
        $this->assertSame(2, $userRowB1['counts']['blocked']);
        $this->assertSame('15.05.2026 09:45', $userRowB1['last_blocked_at']);
        $this->assertSame(AiUsageGuard::OPERATION_KNOWLEDGE_DOCUMENT_UPLOAD, $userRowB1['last_operation']['key']);

        $operationRow = $this->operationRow($report, AiUsageGuard::OPERATION_KNOWLEDGE_DOCUMENT_UPLOAD);
        $this->assertSame(4, $operationRow['periods']['30d']);
        $this->assertSame(2, $operationRow['counts']['blocked']);

        $this->assertArrayNotHasKey('prompt', $report['customers'][0]);
        $this->assertArrayNotHasKey('document_text', $report['customers'][0]);
        $this->assertArrayNotHasKey('answer_text', $report['customers'][0]);
        $this->assertArrayNotHasKey('chunk_content', $report['customers'][0]);
    }

    /**
     * Purpose: Create a deterministic customer fixture for the AI usage report tests.
     * Inputs: Name, plan and optional included AI credits override.
     * Returns: The persisted customer model.
     * Side effects: Writes customer, language and nationality rows when needed.
     */
    private function createCustomer(string $name, string $plan, ?int $includedAiCredits): Customer
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'nationality_id' => $nationality->id,
            'language_id' => $language->id,
            'subscription_plan' => $plan,
            'included_ai_credits' => $includedAiCredits,
            'is_active' => true,
        ]);
    }

    /**
     * Purpose: Create a customer-scoped test user.
     * Inputs: Customer, display name and email.
     * Returns: The persisted user model.
     * Side effects: Writes a user row.
     */
    private function createUser(Customer $customer, string $name, string $email): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    /**
     * Purpose: Create a safe AI usage event row at a fixed point in time.
     * Inputs: Customer, user, operation key, status, limit type, operation count and timestamp.
     * Returns: None.
     * Side effects: Inserts one ai_usage_events row.
     */
    private function createUsageEvent(
        Customer $customer,
        User $user,
        string $operationKey,
        string $status,
        ?string $limitType,
        int $operationCount,
        string $timestamp,
    ): void {
        $originalNow = Carbon::getTestNow();
        Carbon::setTestNow($timestamp);

        try {
            AiUsageEvent::query()->create([
                'customer_id' => $customer->id,
                'user_id' => $user->id,
                'operation_key' => $operationKey,
                'status' => $status,
                'limit_type' => $limitType,
                'operation_count' => $operationCount,
            ]);
        } finally {
            Carbon::setTestNow($originalNow);
        }
    }

    /**
     * Purpose: Read a summary card value from the report payload.
     * Inputs: Report data and the translated summary label.
     * Returns: The integer value for the matching summary card.
     * Side effects: None.
     */
    private function summaryValue(array $report, string $label): int
    {
        foreach ($report['summary_cards'] as $card) {
            if ($card['label'] === $label) {
                return (int) $card['value'];
            }
        }

        $this->fail(sprintf('Summary card [%s] was not found.', $label));
    }

    /**
     * Purpose: Fetch a customer row from the report by customer name.
     * Inputs: Report data and the customer name.
     * Returns: The matching row.
     * Side effects: None.
     */
    private function customerRow(array $report, string $name): array
    {
        foreach ($report['customers'] as $row) {
            if ($row['name'] === $name) {
                return $row;
            }
        }

        $this->fail(sprintf('Customer row [%s] was not found.', $name));
    }

    /**
     * Purpose: Fetch a user row from the report by user name.
     * Inputs: Report data and the user name.
     * Returns: The matching row.
     * Side effects: None.
     */
    private function userRow(array $report, string $name): array
    {
        foreach ($report['users'] as $row) {
            if ($row['name'] === $name) {
                return $row;
            }
        }

        $this->fail(sprintf('User row [%s] was not found.', $name));
    }

    /**
     * Purpose: Fetch an operation row from the report by operation key.
     * Inputs: Report data and the canonical operation key.
     * Returns: The matching row.
     * Side effects: None.
     */
    private function operationRow(array $report, string $operationKey): array
    {
        foreach ($report['operations'] as $row) {
            if ($row['operation_key'] === $operationKey) {
                return $row;
            }
        }

        $this->fail(sprintf('Operation row [%s] was not found.', $operationKey));
    }
}
