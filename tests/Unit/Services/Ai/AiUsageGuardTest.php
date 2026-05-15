<?php

namespace Tests\Unit\Services\Ai;

use App\Exceptions\AiUsageLimitExceededException;
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
            'procynia.ai.usage_guard.customer_per_hour' => 50,
            'procynia.ai.usage_guard.user_decay_seconds' => 60,
            'procynia.ai.usage_guard.customer_decay_seconds' => 3600,
        ]);

        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $guard = app(AiUsageGuard::class);
        $operationKey = AiUsageGuard::OPERATION_SAVED_NOTICE_REQUIREMENT_ANSWER_DRAFT;

        $this->clearRateLimits($customer, $user, $operationKey);

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

    public function test_user_limit_blocks_the_same_user_but_allows_another_user_on_the_same_customer(): void
    {
        config([
            'procynia.ai.usage_guard.user_per_minute' => 1,
            'procynia.ai.usage_guard.customer_per_hour' => 50,
            'procynia.ai.usage_guard.user_decay_seconds' => 60,
            'procynia.ai.usage_guard.customer_decay_seconds' => 3600,
        ]);

        $customer = $this->createCustomer('User Limit AS');
        $firstUser = $this->createUser($customer, 'First User');
        $secondUser = $this->createUser($customer, 'Second User');
        $guard = app(AiUsageGuard::class);
        $operationKey = AiUsageGuard::OPERATION_SAVED_NOTICE_REQUIREMENT_ANSWER_DRAFT;

        $this->clearRateLimits($customer, $firstUser, $operationKey);
        $this->clearRateLimits($customer, $secondUser, $operationKey);

        $guard->assertCanStartAiOperation($customer, $firstUser, $operationKey);

        try {
            $guard->assertCanStartAiOperation($customer, $firstUser, $operationKey);
            $this->fail('The second request for the same user should have been blocked.');
        } catch (AiUsageLimitExceededException $exception) {
            $this->assertInstanceOf(AiUsageLimitExceededException::class, $exception);
            $this->assertSame(AiUsageGuard::LIMIT_TYPE_USER, $exception->limitType());
            $this->assertStringContainsString(__('procynia.ai.usage_guard.user_blocked_base'), $exception->userMessage());
        }

        $guard->assertCanStartAiOperation($customer, $secondUser, $operationKey);

        $this->assertSame(3, AiUsageEvent::query()->count());
        $blockedEvent = AiUsageEvent::query()
            ->where('status', AiUsageEvent::STATUS_BLOCKED)
            ->firstOrFail();
        $this->assertSame(AiUsageEvent::LIMIT_TYPE_USER, $blockedEvent->limit_type);
    }

    public function test_customer_limit_blocks_the_second_user_on_the_same_customer(): void
    {
        config([
            'procynia.ai.usage_guard.user_per_minute' => 50,
            'procynia.ai.usage_guard.customer_per_hour' => 1,
            'procynia.ai.usage_guard.user_decay_seconds' => 60,
            'procynia.ai.usage_guard.customer_decay_seconds' => 3600,
        ]);

        $customer = $this->createCustomer('Customer Limit AS');
        $firstUser = $this->createUser($customer, 'First User');
        $secondUser = $this->createUser($customer, 'Second User');
        $guard = app(AiUsageGuard::class);
        $operationKey = AiUsageGuard::OPERATION_SAVED_NOTICE_REQUIREMENT_ANSWER_DRAFT;

        $this->clearRateLimits($customer, $firstUser, $operationKey);
        $this->clearRateLimits($customer, $secondUser, $operationKey);

        $guard->assertCanStartAiOperation($customer, $firstUser, $operationKey);

        try {
            $guard->assertCanStartAiOperation($customer, $secondUser, $operationKey);
            $this->fail('The second request for the same customer should have been blocked.');
        } catch (AiUsageLimitExceededException $exception) {
            $this->assertInstanceOf(AiUsageLimitExceededException::class, $exception);
            $this->assertSame(AiUsageGuard::LIMIT_TYPE_CUSTOMER, $exception->limitType());
            $this->assertStringContainsString(__('procynia.ai.usage_guard.customer_blocked_base'), $exception->userMessage());
            $this->assertStringContainsString(__('procynia.ai.usage_guard.customer_blocked_active_bid_hint'), $exception->userMessage());
        }

        $this->assertSame(2, AiUsageEvent::query()->count());
        $this->assertSame(1, AiUsageEvent::query()->where('status', AiUsageEvent::STATUS_BLOCKED)->count());
        $this->assertSame(AiUsageEvent::LIMIT_TYPE_CUSTOMER, AiUsageEvent::query()->where('status', AiUsageEvent::STATUS_BLOCKED)->firstOrFail()->limit_type);
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
     * Purpose: Clear the per-user and per-customer rate limit buckets for one operation key.
     * Inputs: The customer, user and canonical operation key.
     * Returns: None.
     * Side effects: Clears the Laravel RateLimiter buckets for deterministic test setup.
     */
    private function clearRateLimits(Customer $customer, User $user, string $operationKey): void
    {
        RateLimiter::clear(sprintf('ai:user:%d:%s', $user->id, $operationKey));
        RateLimiter::clear(sprintf('ai:customer:%d:%s', $customer->id, $operationKey));
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
