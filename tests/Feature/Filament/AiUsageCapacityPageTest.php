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
use Livewire\Livewire;
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
        $response->assertSee(__('procynia.ai_usage_capacity.page_title'));
        $response->assertSeeText(__('procynia.ai_usage_capacity.page_subtitle'));
        $response->assertSee(__('procynia.ai_usage_capacity.controls.customer_search'));
        $response->assertSee(__('procynia.ai_usage_capacity.controls.user_search'));
        $response->assertSee(__('procynia.ai_usage_capacity.sections.customers'));
        $response->assertSee(__('procynia.ai_usage_capacity.sections.users'));
        $response->assertSee(__('procynia.ai_usage_capacity.sections.operations'));
    }

    public function test_ai_usage_capacity_page_is_grouped_under_drift(): void
    {
        $this->assertSame('Drift', AiUsageCapacity::getNavigationGroup());
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

    public function test_customer_search_filters_customer_table(): void
    {
        Carbon::setTestNow('2026-05-15 12:00:00');

        $admin = $this->internalAdmin();
        $this->seedAiUsageData();

        Livewire::actingAs($admin);
        Livewire::test(AiUsageCapacity::class)
            ->set('customerSearch', 'Kunde B')
            ->assertCount('customerRows', 1)
            ->assertSet('customerRows.0.name', 'Kunde B');
    }

    public function test_user_search_filters_user_table_by_name(): void
    {
        Carbon::setTestNow('2026-05-15 12:00:00');

        $admin = $this->internalAdmin();
        $this->seedAiUsageData();

        Livewire::actingAs($admin);
        Livewire::test(AiUsageCapacity::class)
            ->set('userSearch', 'Bruker B1')
            ->assertCount('userRows', 1)
            ->assertSet('userRows.0.name', 'Bruker B1');
    }

    public function test_user_search_filters_user_table_by_email(): void
    {
        Carbon::setTestNow('2026-05-15 12:00:00');

        $admin = $this->internalAdmin();
        $this->seedAiUsageData();

        Livewire::actingAs($admin);
        Livewire::test(AiUsageCapacity::class)
            ->set('userSearch', 'bruker.c1@example.test')
            ->assertCount('userRows', 1)
            ->assertSet('userRows.0.email', 'bruker.c1@example.test');
    }

    public function test_customer_sorting_supports_name_and_30d_usage(): void
    {
        Carbon::setTestNow('2026-05-15 12:00:00');

        $admin = $this->internalAdmin();
        $this->seedAiUsageData();

        Livewire::actingAs($admin);
        Livewire::test(AiUsageCapacity::class)
            ->set('customerSearch', 'Kunde')
            ->set('customerSortField', 'name')
            ->set('customerSortDirection', 'asc')
            ->assertSet('customerRows', function (array $rows): bool {
                $this->assertSame('Kunde A', $rows[0]['name']);
                $this->assertSame('Kunde B', $rows[1]['name']);
                $this->assertSame('Kunde C', $rows[2]['name']);

                return true;
            });

        Livewire::actingAs($admin);
        Livewire::test(AiUsageCapacity::class)
            ->set('customerSearch', 'Kunde')
            ->set('customerSortField', 'usage_30d')
            ->set('customerSortDirection', 'desc')
            ->assertSet('customerRows', function (array $rows): bool {
                $this->assertSame('Kunde C', $rows[0]['name']);
                $this->assertSame('Kunde A', $rows[1]['name']);
                $this->assertSame('Kunde B', $rows[2]['name']);

                return true;
            });
    }

    public function test_user_sorting_supports_name_and_30d_usage(): void
    {
        Carbon::setTestNow('2026-05-15 12:00:00');

        $admin = $this->internalAdmin();
        $this->seedAiUsageData();

        Livewire::actingAs($admin);
        Livewire::test(AiUsageCapacity::class)
            ->set('userSearch', 'Bruker')
            ->set('userSortField', 'name')
            ->set('userSortDirection', 'asc')
            ->assertSet('userRows', function (array $rows): bool {
                $this->assertSame('Bruker A1', $rows[0]['name']);
                $this->assertSame('Bruker B1', $rows[1]['name']);
                $this->assertSame('Bruker C1', $rows[2]['name']);

                return true;
            });

        Livewire::actingAs($admin);
        Livewire::test(AiUsageCapacity::class)
            ->set('userSearch', 'Bruker')
            ->set('userSortField', 'usage_30d')
            ->set('userSortDirection', 'desc')
            ->assertSet('userRows', function (array $rows): bool {
                $this->assertSame('Bruker C1', $rows[0]['name']);
                $this->assertSame('Bruker A1', $rows[1]['name']);
                $this->assertSame('Bruker B1', $rows[2]['name']);

                return true;
            });
    }

    public function test_customer_pagination_limits_visible_rows(): void
    {
        Carbon::setTestNow('2026-05-15 12:00:00');

        $admin = $this->internalAdmin();
        $this->seedAiUsageData();

        Livewire::actingAs($admin);
        Livewire::test(AiUsageCapacity::class)
            ->set('customerSearch', 'Kunde')
            ->set('customerSortField', 'name')
            ->set('customerSortDirection', 'asc')
            ->set('customerPerPage', 25)
            ->assertCount('customerRows', 25)
            ->assertSet('customerRows.0.name', 'Kunde A')
            ->call('nextCustomerPage')
            ->assertCount('customerRows', 5)
            ->assertSet('customerRows.0.name', 'Kunde Z23')
            ->call('nextCustomerPage')
            ->assertCount('customerRows', 5)
            ->assertSet('customerRows.0.name', 'Kunde Z23');
    }

    public function test_user_pagination_limits_visible_rows(): void
    {
        Carbon::setTestNow('2026-05-15 12:00:00');

        $admin = $this->internalAdmin();
        $this->seedAiUsageData();

        Livewire::actingAs($admin);
        Livewire::test(AiUsageCapacity::class)
            ->set('userSearch', 'Bruker')
            ->set('userSortField', 'name')
            ->set('userSortDirection', 'asc')
            ->set('userPerPage', 25)
            ->assertCount('userRows', 25)
            ->assertSet('userRows.0.name', 'Bruker A1')
            ->call('nextUserPage')
            ->assertCount('userRows', 5)
            ->assertSet('userRows.0.name', 'Bruker Z23')
            ->call('nextUserPage')
            ->assertCount('userRows', 5)
            ->assertSet('userRows.0.name', 'Bruker Z23');
    }

    public function test_status_filters_show_near_and_blocked_rows(): void
    {
        Carbon::setTestNow('2026-05-15 12:00:00');

        $admin = $this->internalAdmin();
        $this->seedAiUsageData();

        Livewire::actingAs($admin);
        Livewire::test(AiUsageCapacity::class)
            ->set('customerSearch', 'Kunde')
            ->set('customerStatusFilter', 'near')
            ->set('userStatusFilter', 'near')
            ->assertCount('customerRows', 1)
            ->assertSet('customerRows.0.name', 'Kunde C')
            ->assertCount('userRows', 1)
            ->assertSet('userRows.0.name', 'Bruker C1');

        Livewire::actingAs($admin);
        Livewire::test(AiUsageCapacity::class)
            ->set('customerSearch', 'Kunde')
            ->set('customerStatusFilter', 'blocked')
            ->set('userStatusFilter', 'blocked')
            ->assertCount('customerRows', 1)
            ->assertSet('customerRows.0.name', 'Kunde A')
            ->assertCount('userRows', 1)
            ->assertSet('userRows.0.name', 'Bruker A1');
    }

    public function test_empty_states_and_reset_filters_restore_rows(): void
    {
        Carbon::setTestNow('2026-05-15 12:00:00');

        $admin = $this->internalAdmin();
        $this->seedAiUsageData();

        Livewire::actingAs($admin);
        $component = Livewire::test(AiUsageCapacity::class)
            ->set('customerSearch', 'Ingen treff')
            ->set('userSearch', 'Ingen treff')
            ->assertCount('customerRows', 0)
            ->assertCount('userRows', 0);

        $component
            ->call('resetCustomerFilters')
            ->call('resetUserFilters')
            ->assertCount('customerRows', 25)
            ->assertCount('userRows', 25)
            ->assertSet('customerRows', function (array $rows): bool {
                $this->assertSame('Kunde A', $rows[0]['name']);

                return true;
            })
            ->assertSet('userRows', function (array $rows): bool {
                $this->assertSame('Bruker A1', $rows[0]['name']);

                return true;
            });
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
        $customerB = $this->createCustomer('Kunde B', Customer::PLAN_PRO, 10);
        $customerC = $this->createCustomer('Kunde C', Customer::PLAN_PRO, 10);

        $userA1 = $this->createUser($customerA, 'Bruker A1', 'bruker.a1@example.test');
        $userB1 = $this->createUser($customerB, 'Bruker B1', 'bruker.b1@example.test');
        $userC1 = $this->createUser($customerC, 'Bruker C1', 'bruker.c1@example.test');

        $this->createUsageEvent(
            $customerA,
            $userA1,
            AiUsageGuard::OPERATION_SAVED_NOTICE_REQUIREMENT_ANSWER_DRAFT,
            AiUsageEvent::STATUS_ALLOWED,
            null,
            1,
            '2026-05-15 11:00:00',
        );
        $this->createUsageEvent(
            $customerA,
            $userA1,
            AiUsageGuard::OPERATION_SAVED_NOTICE_ASSESSMENT_REFRESH,
            AiUsageEvent::STATUS_ALLOWED,
            null,
            1,
            '2026-05-15 10:30:00',
        );
        $this->createUsageEvent(
            $customerA,
            $userA1,
            AiUsageGuard::OPERATION_KNOWLEDGE_DOCUMENT_UPLOAD,
            AiUsageEvent::STATUS_BLOCKED,
            AiUsageEvent::LIMIT_TYPE_CUSTOMER,
            1,
            '2026-05-15 10:15:00',
        );

        $this->createUsageEvent(
            $customerB,
            $userB1,
            AiUsageGuard::OPERATION_SAVED_NOTICE_REQUIREMENT_ANSWER_DRAFT,
            AiUsageEvent::STATUS_ALLOWED,
            null,
            1,
            '2026-05-15 11:10:00',
        );
        $this->createUsageEvent(
            $customerB,
            $userB1,
            AiUsageGuard::OPERATION_SAVED_NOTICE_ASSESSMENT_REFRESH,
            AiUsageEvent::STATUS_ALLOWED,
            null,
            1,
            '2026-05-15 10:40:00',
        );
        $this->createUsageEvent(
            $customerB,
            $userB1,
            AiUsageGuard::OPERATION_KNOWLEDGE_DOCUMENT_UPLOAD,
            AiUsageEvent::STATUS_ALLOWED,
            null,
            1,
            '2026-05-15 10:20:00',
        );

        $this->createUsageEvent(
            $customerC,
            $userC1,
            AiUsageGuard::OPERATION_SAVED_NOTICE_REQUIREMENT_ANSWER_DRAFT,
            AiUsageEvent::STATUS_ALLOWED,
            null,
            1,
            '2026-05-15 11:20:00',
        );
        $this->createUsageEvent(
            $customerC,
            $userC1,
            AiUsageGuard::OPERATION_SAVED_NOTICE_ASSESSMENT_REFRESH,
            AiUsageEvent::STATUS_ALLOWED,
            null,
            1,
            '2026-05-15 11:00:00',
        );
        $this->createUsageEvent(
            $customerC,
            $userC1,
            AiUsageGuard::OPERATION_SAVED_NOTICE_DOCUMENTS_UPLOAD,
            AiUsageEvent::STATUS_ALLOWED,
            null,
            1,
            '2026-05-15 10:45:00',
        );
        $this->createUsageEvent(
            $customerC,
            $userC1,
            AiUsageGuard::OPERATION_KNOWLEDGE_DOCUMENT_UPLOAD,
            AiUsageEvent::STATUS_ALLOWED,
            null,
            1,
            '2026-05-15 10:30:00',
        );
        $this->createUsageEvent(
            $customerC,
            $userC1,
            AiUsageGuard::OPERATION_KNOWLEDGE_CHUNK_METADATA_UPDATE,
            AiUsageEvent::STATUS_ALLOWED,
            null,
            1,
            '2026-05-15 10:15:00',
        );
        $this->createUsageEvent(
            $customerC,
            $userC1,
            AiUsageGuard::OPERATION_KNOWLEDGE_VOCABULARY_ANALYSIS_BATCH,
            AiUsageEvent::STATUS_ALLOWED,
            null,
            1,
            '2026-05-15 10:00:00',
        );
        $this->createUsageEvent(
            $customerC,
            $userC1,
            AiUsageGuard::OPERATION_SAVED_NOTICE_REQUIREMENT_ANSWER_DRAFT,
            AiUsageEvent::STATUS_ALLOWED,
            null,
            1,
            '2026-05-15 09:45:00',
        );
        $this->createUsageEvent(
            $customerC,
            $userC1,
            AiUsageGuard::OPERATION_SAVED_NOTICE_ASSESSMENT_REFRESH,
            AiUsageEvent::STATUS_ALLOWED,
            null,
            1,
            '2026-05-15 09:30:00',
        );

        for ($i = 1; $i <= 27; $i++) {
            $suffix = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $customer = $this->createCustomer('Kunde Z'.$suffix, Customer::PLAN_PRO, 10);
            $user = $this->createUser($customer, 'Bruker Z'.$suffix, 'bruker.z'.$suffix.'@example.test');

            $this->createUsageEvent(
                $customer,
                $user,
                AiUsageGuard::OPERATION_SAVED_NOTICE_REQUIREMENT_ANSWER_DRAFT,
                AiUsageEvent::STATUS_ALLOWED,
                null,
                1,
                sprintf('2026-05-15 09:%02d:00', $i),
            );
        }
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
        ?int $includedAiCredits = 10,
    ): Customer {
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
