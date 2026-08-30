<?php

namespace Tests\Feature\App;

use App\Data\Ai\AiQuotaStatus;
use App\Exceptions\Ai\AiCostControlException;
use App\Models\Customer;
use App\Models\CustomerAiCaseUsage;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Models\User;
use App\Support\Ai\AiCostControlPresenter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** What the customer sees: the subscription page, and each of the four hard-stop reasons. */
class AiQuotaCustomerUxTest extends TestCase
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

    // =========================================================================
    // /app/billing
    // =========================================================================

    public function test_the_billing_page_shows_used_included_remaining_and_the_period(): void
    {
        $customer = $this->customer(10);
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $this->consume($customer, 2);

        $this->actingAs($owner)->get('/app/billing')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('ai_quota.used', 2)
                ->where('ai_quota.included', 10)
                ->where('ai_quota.allowance', 10)
                ->where('ai_quota.remaining', 8)
                ->where('ai_quota.percentage_used', 20)
                ->where('ai_quota.period_start', '2026-08-01')
                ->where('ai_quota.period_end', '2026-08-31')
                ->where('ai_quota.status', AiQuotaStatus::STATUS_NORMAL)
                ->where('ai_quota.is_unlimited', false)
                ->where('ai_quota.is_suspended', false));
    }

    public function test_the_billing_page_reports_each_quota_status(): void
    {
        foreach ([
            [10, 8, AiQuotaStatus::STATUS_WARNING],
            [10, 9, AiQuotaStatus::STATUS_CRITICAL],
            [10, 10, AiQuotaStatus::STATUS_EXHAUSTED],
        ] as [$included, $used, $expected]) {
            $customer = $this->customer($included);
            $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
            $this->consume($customer, $used);

            $this->actingAs($owner)->get('/app/billing')
                ->assertOk()
                ->assertInertia(fn ($page) => $page->where('ai_quota.status', $expected));
        }
    }

    public function test_an_unlimited_plan_is_shown_as_unlimited_rather_than_zero_of_zero(): void
    {
        $customer = $this->customer(0, Customer::PLAN_ENTERPRISE);
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $this->consume($customer, 5);

        $this->actingAs($owner)->get('/app/billing')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('ai_quota.is_unlimited', true)
                ->where('ai_quota.percentage_used', null)
                ->where('ai_quota.remaining', null)
                ->where('ai_quota.status', AiQuotaStatus::STATUS_NORMAL));
    }

    public function test_a_suspended_customer_sees_suspension_rather_than_a_quota_problem(): void
    {
        $customer = $this->customer(10);
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $this->consume($customer, 1);
        $customer->update(['ai_access_status' => Customer::AI_ACCESS_SUSPENDED]);

        $this->actingAs($owner)->get('/app/billing')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('ai_quota.status', AiQuotaStatus::STATUS_SUSPENDED)
                ->where('ai_quota.is_suspended', true)
                ->where('ai_quota.remaining', 9));
    }

    public function test_the_billing_page_never_shows_another_customers_usage(): void
    {
        $customer = $this->customer(10);
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $this->consume($customer, 1);

        $otherCustomer = $this->customer(10);
        $this->consume($otherCustomer, 7);

        $this->actingAs($owner)->get('/app/billing')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('ai_quota.customer_id', $customer->id)
                ->where('ai_quota.used', 1));
    }

    public function test_the_existing_subscription_and_invoice_sections_are_still_delivered(): void
    {
        $customer = $this->customer(10);
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($owner)->get('/app/billing')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('customer_plan')
                ->has('available_plans')
                ->has('invoices')
                ->has('billing_lines')
                ->has('ai_quota'));
    }

    public function test_billing_authorisation_is_unchanged_by_the_new_quota_section(): void
    {
        $customer = $this->customer(10);
        $contributor = $this->user($customer, User::BID_ROLE_CONTRIBUTOR);

        $this->actingAs($contributor)->get('/app/billing')->assertForbidden();
    }

    // =========================================================================
    // Hard-stop messaging
    // =========================================================================

    public function test_each_hard_stop_reason_gets_its_own_presentation_safe_message(): void
    {
        $customer = $this->customer(3);
        $this->consume($customer, 3);
        $presenter = app(AiCostControlPresenter::class);

        $messages = [];

        foreach ([
            AiCostControlException::QUOTA_EXHAUSTED,
            AiCostControlException::NOT_INCLUDED,
            AiCostControlException::CUSTOMER_SUSPENDED,
            AiCostControlException::GLOBAL_STOP,
        ] as $reason) {
            $message = $presenter->message(new AiCostControlException($reason), $customer);
            $messages[$reason] = $message;

            $this->assertNotSame('', trim($message));
            $this->assertStringNotContainsString('procynia.', $message, "{$reason} has no translation");

            foreach (['OpenAI', 'token', 'kill switch', 'Exception', 'gpt-', 'budget'] as $leak) {
                $this->assertStringNotContainsStringIgnoringCase($leak, $message, "{$reason} leaks internal detail: {$leak}");
            }
        }

        $this->assertSame(
            count($messages),
            count(array_unique($messages)),
            'Four different reasons must not collapse into one generic message.',
        );
    }

    public function test_the_quota_message_says_new_cases_are_blocked_not_that_all_ai_is_off(): void
    {
        $customer = $this->customer(3);
        $this->consume($customer, 3);

        // SetCustomerLocale resolves the language per customer, so both locales must carry the
        // same promise: an activated case keeps working, only a new one is refused.
        foreach (['no' => 'allerede har AI aktivert', 'en' => 'already have AI activated'] as $locale => $keepsWorking) {
            app()->setLocale($locale);
            $message = app(AiCostControlPresenter::class)
                ->message(new AiCostControlException(AiCostControlException::QUOTA_EXHAUSTED), $customer);

            $this->assertStringContainsString('3', $message, "{$locale}: the real figures are missing");
            $this->assertStringContainsString($keepsWorking, $message, "{$locale}: the copy contradicts Phase 2 policy");
            $this->assertStringNotContainsStringIgnoringCase('all AI', $message);
        }
    }

    public function test_the_quota_payload_carries_the_reason_code_and_the_current_position(): void
    {
        $customer = $this->customer(3);
        $this->consume($customer, 3);

        $payload = app(AiCostControlPresenter::class)
            ->payload(new AiCostControlException(AiCostControlException::QUOTA_EXHAUSTED), $customer);

        $this->assertSame(AiCostControlException::QUOTA_EXHAUSTED, $payload['reason']);
        $this->assertSame(3, $payload['ai_quota']['used']);
        $this->assertSame(0, $payload['ai_quota']['remaining']);
    }

    public function test_a_global_stop_message_blames_nobody_at_the_customer(): void
    {
        $customer = $this->customer(3);

        foreach (['no', 'en'] as $locale) {
            app()->setLocale($locale);
            $message = app(AiCostControlPresenter::class)
                ->message(new AiCostControlException(AiCostControlException::GLOBAL_STOP), $customer);

            $this->assertSame(__('procynia.ai_quota.hard_stop.global_stop'), $message);
            // A platform incident must not read as something the customer used up or did wrong.
            $this->assertStringNotContainsStringIgnoringCase('kapasitet', $message);
            $this->assertStringNotContainsStringIgnoringCase('capacity', $message);
        }
    }

    // =========================================================================
    // Inertia flash
    // =========================================================================

    public function test_a_warning_flash_actually_reaches_the_page(): void
    {
        $customer = $this->customer(10);
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);

        // Before this phase the backend flashed 'warning' and the frontend never received it, so a
        // quota warning was written and then silently dropped between session and page props.
        $this->actingAs($owner)
            ->withSession(['warning' => 'Du nærmer deg grensen for perioden.'])
            ->get('/app/billing')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('flash.warning', 'Du nærmer deg grensen for perioden.'));
    }

    public function test_success_and_error_flashes_still_reach_the_page(): void
    {
        $customer = $this->customer(10);
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->actingAs($owner)
            ->withSession(['success' => 'Lagret.', 'error' => 'Noe gikk galt.'])
            ->get('/app/billing')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('flash.success', 'Lagret.')
                ->where('flash.error', 'Noe gikk galt.'));
    }

    // =========================================================================
    // AI workspace
    // =========================================================================

    public function test_the_ai_workspace_carries_the_same_quota_state_as_billing(): void
    {
        $customer = $this->customer(3);
        $owner = $this->user($customer, User::BID_ROLE_SYSTEM_OWNER);
        $this->consume($customer, 2);
        $notice = $this->notice($customer);

        $this->actingAs($owner)->get("/app/ai/{$notice->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('ai_quota.used', 2)
                ->where('ai_quota.allowance', 3)
                ->where('ai_quota.remaining', 1)
                ->where('ai_quota.status', AiQuotaStatus::STATUS_NORMAL));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

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
            'external_id' => 'UX-'.Str::random(10),
            'title' => 'Quota UX notice',
            'buyer_name' => 'Procynia',
            'status' => 'ACTIVE',
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

    private function customer(int $credits, string $plan = Customer::PLAN_PRO): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'UX '.Str::random(8),
            'slug' => 'ux-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
            'subscription_plan' => $plan,
            'included_ai_credits' => $credits,
        ]);
    }
}
