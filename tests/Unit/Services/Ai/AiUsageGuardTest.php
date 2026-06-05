<?php

namespace Tests\Unit\Services\Ai;

use App\Models\AiUsageEvent;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\Ai\AiUsageGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiUsageGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        DB::beginTransaction();
        app()->setLocale('no');
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_allowed_operation_records_safe_usage_event_without_sensitive_fields(): void
    {
        config([
            'procynia.ai.usage_guard.user_per_minute' => 5,
            'procynia.ai.usage_guard.user_decay_seconds' => 60,
        ]);

        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $guard = app(AiUsageGuard::class);
        $operationKey = AiUsageGuard::OPERATION_SAVED_NOTICE_REQUIREMENT_ANSWER_DRAFT;

        $this->clearRateLimits($user, $operationKey);

        $guard->assertCanStartAiOperation($customer, $user, $operationKey);

        $event = AiUsageEvent::query()->firstOrFail();

        $this->assertSame($customer->id, $event->customer_id);
        $this->assertSame($user->id, $event->user_id);
        $this->assertSame($operationKey, $event->operation_key);
        $this->assertSame(AiUsageEvent::STATUS_ALLOWED, $event->status);
        $this->assertNull($event->limit_type);
        $this->assertSame(1, $event->operation_count);

        $this->assertFalse(Schema::hasColumn('ai_usage_events', 'prompt'));
        $this->assertFalse(Schema::hasColumn('ai_usage_events', 'document_text'));
        $this->assertFalse(Schema::hasColumn('ai_usage_events', 'answer_text'));
        $this->assertFalse(Schema::hasColumn('ai_usage_events', 'chunk_content'));
    }

    public function test_user_high_tempo_returns_warning_and_allows_the_same_user_and_another_user_on_the_same_customer(): void
    {
        config([
            'procynia.ai.usage_guard.user_per_minute' => 1,
            'procynia.ai.usage_guard.user_decay_seconds' => 60,
        ]);

        $customer = $this->createCustomer('User Limit AS');
        $firstUser = $this->createUser($customer, 'First User');
        $secondUser = $this->createUser($customer, 'Second User');
        $guard = app(AiUsageGuard::class);
        $operationKey = AiUsageGuard::OPERATION_SAVED_NOTICE_REQUIREMENT_ANSWER_DRAFT;

        $this->clearRateLimits($firstUser, $operationKey);
        $this->clearRateLimits($secondUser, $operationKey);

        $this->assertNull($guard->assertCanStartAiOperation($customer, $firstUser, $operationKey));
        $warning = $guard->assertCanStartAiOperation($customer, $firstUser, $operationKey);
        $this->assertSame(
            __('procynia.ai.usage_guard.user_high_tempo_warning', ['limit' => 1]),
            $warning,
        );
        $this->assertNull($guard->assertCanStartAiOperation($customer, $secondUser, $operationKey));

        $this->assertSame(3, AiUsageEvent::query()->count());
        $this->assertSame(3, AiUsageEvent::query()->where('status', AiUsageEvent::STATUS_ALLOWED)->count());
        $this->assertSame(0, AiUsageEvent::query()->where('status', AiUsageEvent::STATUS_BLOCKED)->count());
    }

    public function test_customer_limit_no_longer_blocks_the_second_user_on_the_same_customer(): void
    {
        config([
            'procynia.ai.usage_guard.user_per_minute' => 50,
            'procynia.ai.usage_guard.user_decay_seconds' => 60,
        ]);

        $customer = $this->createCustomer('Customer Limit AS');
        $firstUser = $this->createUser($customer, 'First User');
        $secondUser = $this->createUser($customer, 'Second User');
        $guard = app(AiUsageGuard::class);
        $operationKey = AiUsageGuard::OPERATION_SAVED_NOTICE_REQUIREMENT_ANSWER_DRAFT;

        $this->clearRateLimits($firstUser, $operationKey);
        $this->clearRateLimits($secondUser, $operationKey);

        $this->assertNull($guard->assertCanStartAiOperation($customer, $firstUser, $operationKey));
        $this->assertNull($guard->assertCanStartAiOperation($customer, $secondUser, $operationKey));

        $this->assertSame(2, AiUsageEvent::query()->count());
        $this->assertSame(2, AiUsageEvent::query()->where('status', AiUsageEvent::STATUS_ALLOWED)->count());
        $this->assertSame(0, AiUsageEvent::query()->where('status', AiUsageEvent::STATUS_BLOCKED)->count());
    }

    public function test_monthly_budget_allows_operation_when_under_quota(): void
    {
        config([
            'procynia.ai.usage_guard.user_per_minute' => 50,
        ]);

        $customer = $this->createCustomer('Pro Customer');
        $customer->forceFill(['included_ai_credits' => 3])->save();
        $user = $this->createUser($customer);
        $guard = app(AiUsageGuard::class);
        $operationKey = AiUsageGuard::OPERATION_SAVED_NOTICE_REQUIREMENT_ANSWER_DRAFT;

        $this->clearRateLimits($user, $operationKey);
        $this->seedMonthlyAllowedEvents($customer, $user, $operationKey, 2);

        $guard->assertCanStartAiOperation($customer, $user, $operationKey);

        $this->assertSame(AiUsageEvent::STATUS_ALLOWED, AiUsageEvent::query()->latest('id')->firstOrFail()->status);
    }

    public function test_monthly_budget_no_longer_blocks_operation_when_quota_is_exhausted(): void
    {
        config([
            'procynia.ai.usage_guard.user_per_minute' => 50,
        ]);

        $customer = $this->createCustomer('Pro Customer');
        $customer->forceFill(['included_ai_credits' => 3])->save();
        $user = $this->createUser($customer);
        $guard = app(AiUsageGuard::class);
        $operationKey = AiUsageGuard::OPERATION_SAVED_NOTICE_REQUIREMENT_ANSWER_DRAFT;

        $this->clearRateLimits($user, $operationKey);
        $this->seedMonthlyAllowedEvents($customer, $user, $operationKey, 3);

        $this->assertNull($guard->assertCanStartAiOperation($customer, $user, $operationKey));
        $this->assertSame(4, AiUsageEvent::query()->count());
        $this->assertSame(4, AiUsageEvent::query()->where('status', AiUsageEvent::STATUS_ALLOWED)->count());
        $this->assertSame(0, AiUsageEvent::query()->where('status', AiUsageEvent::STATUS_BLOCKED)->count());
    }

    public function test_monthly_budget_resets_at_new_calendar_month(): void
    {
        config([
            'procynia.ai.usage_guard.user_per_minute' => 50,
        ]);

        $customer = $this->createCustomer('Pro Customer Monthly Reset');
        $customer->forceFill(['included_ai_credits' => 3])->save();
        $user = $this->createUser($customer);
        $guard = app(AiUsageGuard::class);
        $operationKey = AiUsageGuard::OPERATION_SAVED_NOTICE_REQUIREMENT_ANSWER_DRAFT;

        $this->clearRateLimits($user, $operationKey);

        $lastMonthAt = now()->subMonthNoOverflow()->startOfMonth();
        $event = new AiUsageEvent([
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'operation_key' => $operationKey,
            'status' => AiUsageEvent::STATUS_ALLOWED,
            'limit_type' => null,
            'operation_count' => 3,
        ]);
        $event->created_at = $lastMonthAt;
        $event->updated_at = $lastMonthAt;
        $event->save();

        $guard->assertCanStartAiOperation($customer, $user, $operationKey);

        $this->assertSame(AiUsageEvent::STATUS_ALLOWED, AiUsageEvent::query()->latest('id')->firstOrFail()->status);
    }

    public function test_monthly_budget_is_isolated_per_customer(): void
    {
        config([
            'procynia.ai.usage_guard.user_per_minute' => 50,
        ]);

        $customerA = $this->createCustomer('Customer A');
        $customerA->forceFill(['included_ai_credits' => 3])->save();
        $userA = $this->createUser($customerA, 'User A');

        $customerB = $this->createCustomer('Customer B');
        $customerB->forceFill(['included_ai_credits' => 3])->save();
        $userB = $this->createUser($customerB, 'User B');

        $guard = app(AiUsageGuard::class);
        $operationKey = AiUsageGuard::OPERATION_SAVED_NOTICE_REQUIREMENT_ANSWER_DRAFT;

        $this->clearRateLimits($userA, $operationKey);
        $this->clearRateLimits($userB, $operationKey);

        $this->seedMonthlyAllowedEvents($customerA, $userA, $operationKey, 3);

        $this->assertNull($guard->assertCanStartAiOperation($customerA, $userA, $operationKey));

        $this->assertNull($guard->assertCanStartAiOperation($customerB, $userB, $operationKey));

        $this->assertSame(
            AiUsageEvent::STATUS_ALLOWED,
            AiUsageEvent::query()->where('customer_id', $customerB->id)->latest('id')->firstOrFail()->status,
        );
    }

    public function test_monthly_budget_does_not_apply_to_enterprise_customer_with_zero_credits(): void
    {
        config([
            'procynia.ai.usage_guard.user_per_minute' => 50,
        ]);

        $customer = $this->createCustomer('Enterprise Customer');
        $customer->forceFill([
            'subscription_plan' => Customer::PLAN_ENTERPRISE,
        ])->save();

        $this->assertSame(0, (int) $customer->fresh()->included_ai_credits,
            'Enterprise customers have included_ai_credits = 0 (DB default), meaning no monthly cap via the quota mechanism.');

        $user = $this->createUser($customer);
        $guard = app(AiUsageGuard::class);
        $operationKey = AiUsageGuard::OPERATION_SAVED_NOTICE_REQUIREMENT_ANSWER_DRAFT;

        $this->clearRateLimits($user, $operationKey);
        $this->seedMonthlyAllowedEvents($customer, $user, $operationKey, 1000);

        $guard->assertCanStartAiOperation($customer, $user, $operationKey);

        $this->assertSame(AiUsageEvent::STATUS_ALLOWED, AiUsageEvent::query()->latest('id')->firstOrFail()->status);
    }

    public function test_monthly_budget_does_not_apply_to_customer_with_zero_credits_unlimited_override(): void
    {
        config([
            'procynia.ai.usage_guard.user_per_minute' => 50,
        ]);

        $customer = $this->createCustomer('Custom Unlimited Customer');
        $customer->forceFill([
            'subscription_plan' => Customer::PLAN_PRO,
            'included_ai_credits' => 0,
        ])->save();

        $user = $this->createUser($customer);
        $guard = app(AiUsageGuard::class);
        $operationKey = AiUsageGuard::OPERATION_SAVED_NOTICE_REQUIREMENT_ANSWER_DRAFT;

        $this->clearRateLimits($user, $operationKey);
        $this->seedMonthlyAllowedEvents($customer, $user, $operationKey, 100);

        $guard->assertCanStartAiOperation($customer, $user, $operationKey);

        $this->assertSame(AiUsageEvent::STATUS_ALLOWED, AiUsageEvent::query()->latest('id')->firstOrFail()->status);
    }

    /**
     * Purpose: Create a deterministic customer fixture for AI usage guard tests.
     * Inputs: An optional customer name.
     * Returns: The persisted customer model.
     * Side effects: Writes a customer row and its prerequisite language/nationality rows when needed.
     */
    private function createCustomer(string $name = 'Procynia AS'): Customer
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
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
    }

    /**
     * Purpose: Create a deterministic customer-scoped user fixture for AI usage guard tests.
     * Inputs: The owning customer and an optional display name.
     * Returns: The persisted user model.
     * Side effects: Writes a user row to the test database.
     */
    private function createUser(Customer $customer, string $name = 'AI Tester'): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => Str::slug($name).'.'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    /**
     * Purpose: Seed a fixed count of allowed AI usage events for the current calendar month.
     * Inputs: The customer, user, operation key and event count to seed.
     * Returns: None.
     * Side effects: Writes ai_usage_events rows with created_at inside the current month.
     */
    private function seedMonthlyAllowedEvents(Customer $customer, User $user, string $operationKey, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            AiUsageEvent::query()->create([
                'customer_id' => $customer->id,
                'user_id' => $user->id,
                'operation_key' => $operationKey,
                'status' => AiUsageEvent::STATUS_ALLOWED,
                'limit_type' => null,
                'operation_count' => 1,
            ]);
        }
    }

    /**
     * Purpose: Clear the per-user rate limit bucket for one operation key.
     * Inputs: The user and canonical operation key.
     * Returns: None.
     * Side effects: Clears the Laravel RateLimiter buckets for deterministic test setup.
     */
    private function clearRateLimits(User $user, string $operationKey): void
    {
        RateLimiter::clear(sprintf('ai:user:%d:%s', $user->id, $operationKey));
    }

    /**
     * Purpose: Switch the test case to the project's PostgreSQL connection.
     * Inputs: None.
     * Returns: None.
     * Side effects: Reconfigures the default database connection for the test process.
     */
    private function useProjectPostgresConnection(): void
    {
        $connectionName = 'feature_pgsql';

        config([
            "database.connections.{$connectionName}" => [
                'driver' => 'pgsql',
                'host' => 'postgres',
                'port' => '5432',
                'database' => 'procynia_test',
                'username' => 'gehard',
                'password' => 'Opaque01',
                'charset' => 'utf8',
                'prefix' => '',
                'prefix_indexes' => true,
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ],
            'database.default' => $connectionName,
        ]);

        DB::purge($connectionName);
        DB::setDefaultConnection($connectionName);
        DB::reconnect($connectionName);
    }

    /**
     * Purpose: Read a value from the project's root .env file for the test database connection.
     * Inputs: The env key and a default fallback value.
     * Returns: The configured value or the fallback when the key is missing.
     * Side effects: Caches the parsed .env file for the current test process.
     */
}
