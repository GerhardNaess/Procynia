<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\AiUsageCapacity;
use App\Models\AiUsageEvent;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\Ai\AiUsageGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiUsageCapacityPageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_internal_admin_can_open_ai_usage_capacity_page(): void
    {
        Carbon::setTestNow('2026-05-15 12:00:00');

        $admin = $this->internalAdmin();
        $this->seedAiUsageData();

        $response = $this->actingAs($admin)->get(AiUsageCapacity::getUrl());

        $response->assertOk();
        $response->assertSee('AI-bruk og kapasitet');
        $response->assertSee('Kunde A');
        $response->assertSee('Kunde B');
        $response->assertSee('Bruker A1');
        $response->assertSee('Bruker B1');
        $response->assertSee('Nær kapasitetsgrense');
        $response->assertSee('Ikke definert');
        $response->assertSee('saved_notice_requirement_answer_draft');
        $response->assertSee('knowledge_document_upload');
        $response->assertDontSee('document_text');
        $response->assertDontSee('answer_text');
        $response->assertDontSee('chunk_content');
    }

    public function test_customer_admin_cannot_access_ai_usage_capacity_page(): void
    {
        $customer = $this->createCustomer('Kunde for admin');
        $user = User::factory()->create([
            'name' => 'Customer Admin',
            'email' => 'customer.admin@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        $this->actingAs($user);

        $this->assertFalse(AiUsageCapacity::canAccess());
    }

    public function test_regular_user_cannot_access_ai_usage_capacity_page(): void
    {
        $customer = $this->createCustomer('Kunde for bruker');
        $user = User::factory()->create([
            'name' => 'Regular User',
            'email' => 'user@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_USER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        $this->actingAs($user);

        $this->assertFalse(AiUsageCapacity::canAccess());
    }

    /**
     * Purpose: Seed a compact AI usage fixture for the Filament page.
     * Inputs: None.
     * Returns: None.
     * Side effects: Writes customers, users and usage events.
     */
    private function seedAiUsageData(): void
    {
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
            5,
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
    }

    /**
     * Purpose: Create a deterministic customer fixture for the page test.
     * Inputs: Name, plan and optional included AI credits override.
     * Returns: The persisted customer model.
     * Side effects: Writes customer, language and nationality rows when needed.
     */
    private function createCustomer(
        string $name,
        string $plan = Customer::PLAN_PRO,
        ?int $includedAiCredits = 3,
    ): Customer
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
     * Purpose: Create a deterministic user fixture for the page test.
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
     * Purpose: Create a safe AI usage event for the page fixture.
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
     * Purpose: Create an internal super admin for the page test.
     * Inputs: None.
     * Returns: The persisted user model.
     * Side effects: Writes a super admin user row.
     */
    private function internalAdmin(): User
    {
        return User::factory()->create([
            'name' => 'Internal Admin',
            'email' => 'internal.admin@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_SUPER_ADMIN,
            'customer_id' => null,
            'is_active' => true,
        ]);
    }
}
