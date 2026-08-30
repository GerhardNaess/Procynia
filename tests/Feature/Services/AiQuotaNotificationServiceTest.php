<?php

namespace Tests\Feature\Services;

use App\Models\Customer;
use App\Models\CustomerAiCaseUsage;
use App\Models\CustomerAiNotificationState;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\AiQuotaNotification;
use App\Services\Ai\Commercial\AiQuotaNotificationService;
use App\Services\Ai\Commercial\AiRuntimeControlService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Threshold notifications: once per customer, event and period — to the right people only. */
class AiQuotaNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-15 10:00:00');
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // =========================================================================
    // Threshold crossing and dedupe
    // =========================================================================

    public function test_crossing_eighty_percent_notifies_once_and_never_again_at_the_same_level(): void
    {
        $customer = $this->customer(10);
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $this->consume($customer, 8);

        $this->evaluate($customer);
        $this->evaluate($customer);
        $this->evaluate($customer);

        Notification::assertSentToTimes($owner, AiQuotaNotification::class, 1);
        $this->assertSame(1, UserNotification::query()->where('customer_id', $customer->id)->count());
        $this->assertDatabaseHas('customer_ai_notification_states', [
            'customer_id' => $customer->id,
            'event_key' => CustomerAiNotificationState::EVENT_QUOTA_WARNING,
            'period_start' => '2026-08-01',
        ]);
    }

    public function test_crossing_ninety_percent_sends_a_second_distinct_notification(): void
    {
        $customer = $this->customer(10);
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->consume($customer, 8);
        $this->evaluate($customer);
        $this->consume($customer, 1);
        $this->evaluate($customer);

        Notification::assertSentToTimes($owner, AiQuotaNotification::class, 2);
        $this->assertDatabaseHas('customer_ai_notification_states', [
            'customer_id' => $customer->id,
            'event_key' => CustomerAiNotificationState::EVENT_QUOTA_CRITICAL,
        ]);
    }

    public function test_reaching_one_hundred_percent_sends_the_exhausted_notification(): void
    {
        $customer = $this->customer(10);
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $this->consume($customer, 9);
        $this->evaluate($customer);
        Notification::fake();

        $this->consume($customer, 1);
        $this->evaluate($customer);

        Notification::assertSentToTimes($owner, AiQuotaNotification::class, 1);
        $this->assertDatabaseHas('customer_ai_notification_states', [
            'customer_id' => $customer->id,
            'event_key' => CustomerAiNotificationState::EVENT_QUOTA_EXHAUSTED,
        ]);
        $this->assertSame(
            UserNotification::SEVERITY_CRITICAL,
            UserNotification::query()->where('event_type', 'ai_quota.quota_exhausted')->value('severity'),
        );
    }

    public function test_a_jump_past_every_threshold_sends_one_email_and_closes_the_lower_ones(): void
    {
        $customer = $this->customer(3);
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $this->consume($customer, 3);

        $this->evaluate($customer);

        // One message, not three near-identical ones.
        Notification::assertSentToTimes($owner, AiQuotaNotification::class, 1);
        $this->assertSame(1, UserNotification::query()->where('customer_id', $customer->id)->count());

        // The weaker thresholds are recorded as passed so they can never fire later this period.
        foreach ([
            CustomerAiNotificationState::EVENT_QUOTA_WARNING,
            CustomerAiNotificationState::EVENT_QUOTA_CRITICAL,
            CustomerAiNotificationState::EVENT_QUOTA_EXHAUSTED,
        ] as $event) {
            $this->assertDatabaseHas('customer_ai_notification_states', [
                'customer_id' => $customer->id,
                'event_key' => $event,
                'period_start' => '2026-08-01',
            ]);
        }

        Notification::fake();
        $this->evaluate($customer);
        Notification::assertNothingSent();
    }

    public function test_a_new_period_allows_the_thresholds_to_fire_again(): void
    {
        $customer = $this->customer(3);
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $this->consume($customer, 3);
        $this->evaluate($customer);

        Carbon::setTestNow('2026-09-10 09:00:00');
        $this->consume($customer, 3, '2026-09-01', '2026-09-30');
        Notification::fake();
        $this->evaluate($customer);

        Notification::assertSentToTimes($owner, AiQuotaNotification::class, 1);
        $this->assertSame(
            3,
            CustomerAiNotificationState::query()->where('customer_id', $customer->id)->whereDate('period_start', '2026-08-01')->count(),
            'August history must be preserved, not overwritten.',
        );
        $this->assertDatabaseHas('customer_ai_notification_states', [
            'customer_id' => $customer->id,
            'event_key' => CustomerAiNotificationState::EVENT_QUOTA_EXHAUSTED,
            'period_start' => '2026-09-01',
        ]);
    }

    public function test_an_unlimited_plan_never_receives_a_quota_threshold_notification(): void
    {
        $customer = $this->customer(0, Customer::PLAN_ENTERPRISE);
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $this->consume($customer, 50);

        $this->evaluate($customer);

        Notification::assertNothingSentTo($owner);
        $this->assertDatabaseCount('customer_ai_notification_states', 0);
    }

    public function test_a_plan_without_ai_receives_no_quota_threshold_notification(): void
    {
        $customer = $this->customer(0, Customer::PLAN_FREE);
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->evaluate($customer);

        Notification::assertNothingSentTo($owner);
    }

    // =========================================================================
    // Recipients
    // =========================================================================

    public function test_only_active_system_owners_of_the_same_customer_are_notified(): void
    {
        $customer = $this->customer(10);
        $firstOwner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $secondOwner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $contributor = $this->user($customer, User::BID_ROLE_CONTRIBUTOR);
        $bidManager = $this->user($customer, User::BID_ROLE_BID_MANAGER);
        $inactiveOwner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER, active: false);

        $otherCustomer = $this->customer(10);
        $otherOwner = $this->user($otherCustomer, User::BID_ROLE_SYSTEM_OWNER);

        $this->consume($customer, 8);
        $this->evaluate($customer);

        Notification::assertSentTo([$firstOwner, $secondOwner], AiQuotaNotification::class);
        Notification::assertNothingSentTo($contributor);
        Notification::assertNothingSentTo($bidManager);
        Notification::assertNothingSentTo($inactiveOwner);
        Notification::assertNothingSentTo($otherOwner);

        $this->assertSame(
            0,
            UserNotification::query()->where('customer_id', $otherCustomer->id)->count(),
            'Another tenant must never receive an in-app notification about this customer.',
        );
        $this->assertEqualsCanonicalizing(
            [$firstOwner->id, $secondOwner->id],
            UserNotification::query()->where('customer_id', $customer->id)->pluck('user_id')->all(),
        );
    }

    public function test_a_customer_without_a_system_owner_is_reported_instead_of_silently_dropped(): void
    {
        Log::spy();
        $customer = $this->customer(10);
        $this->user($customer, User::BID_ROLE_CONTRIBUTOR);
        $this->consume($customer, 8);

        $this->evaluate($customer);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'No active System Owner'))
            ->atLeast()->once();
        $this->assertDatabaseHas('admin_notifications', ['type' => 'ai_quota_no_recipient']);

        // Not recorded as notified: appointing a System Owner later must still deliver it once.
        $this->assertDatabaseCount('customer_ai_notification_states', 0);
    }

    public function test_the_threshold_is_delivered_once_a_system_owner_finally_exists(): void
    {
        $customer = $this->customer(10);
        $this->consume($customer, 8);
        $this->evaluate($customer);

        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $this->evaluate($customer);

        Notification::assertSentToTimes($owner, AiQuotaNotification::class, 1);
    }

    // =========================================================================
    // Suspend / resume
    // =========================================================================

    public function test_suspending_and_resuming_notifies_the_system_owner_each_time(): void
    {
        $customer = $this->customer(10);
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $controls = app(AiRuntimeControlService::class);

        $controls->setCustomerAccess($customer, Customer::AI_ACCESS_SUSPENDED, reason: 'test');
        $controls->setCustomerAccess($customer->fresh(), Customer::AI_ACCESS_ENABLED, reason: 'test');

        Notification::assertSentToTimes($owner, AiQuotaNotification::class, 2);
        $this->assertEqualsCanonicalizing(
            ['ai_quota.ai_suspended', 'ai_quota.ai_resumed'],
            UserNotification::query()->where('customer_id', $customer->id)->pluck('event_type')->all(),
        );
    }

    public function test_setting_the_same_access_status_twice_does_not_notify_again(): void
    {
        $customer = $this->customer(10);
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $controls = app(AiRuntimeControlService::class);

        $controls->setCustomerAccess($customer, Customer::AI_ACCESS_SUSPENDED, reason: 'test');
        $controls->setCustomerAccess($customer->fresh(), Customer::AI_ACCESS_SUSPENDED, reason: 'test again');

        Notification::assertSentToTimes($owner, AiQuotaNotification::class, 1);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function evaluate(Customer $customer): void
    {
        app(AiQuotaNotificationService::class)->evaluate($customer->fresh());
    }

    private function consume(Customer $customer, int $cases, string $start = '2026-08-01', string $end = '2026-08-31'): void
    {
        for ($index = 0; $index < $cases; $index++) {
            CustomerAiCaseUsage::query()->create([
                'customer_id' => $customer->id,
                'saved_notice_id' => SavedNotice::query()->create([
                    'customer_id' => $customer->id,
                    'external_id' => 'QN-'.Str::random(10),
                    'title' => 'Quota notification notice',
                    'buyer_name' => 'Procynia',
                    'status' => 'ACTIVE',
                ])->id,
                'activated_at' => now(),
                'period_start' => $start, 'period_end' => $end,
                'source_operation_key' => 'test',
            ]);
        }
    }

    private function user(Customer $customer, string $bidRole, bool $active = true): User
    {
        return User::query()->create([
            'customer_id' => $customer->id,
            'name' => 'User '.Str::random(6),
            'email' => Str::lower(Str::random(10)).'@procynia.test',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_USER,
            'bid_role' => $bidRole,
            'is_active' => $active,
        ]);
    }

    private function customer(int $credits, string $plan = Customer::PLAN_PRO): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Notify '.Str::random(8),
            'slug' => 'notify-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
            'subscription_plan' => $plan,
            'included_ai_credits' => $credits,
        ]);
    }
}
