<?php

namespace Tests\Concerns;

use App\Models\AiUsageAttempt;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Customers, users, packages and trusted ledger rows for the AI experience model. Monthly billing
 * anchored on the 1st, so billing periods are calendar months and easy to reason about. No
 * provider call is ever made: usage is written straight into the ledger.
 *
 * Tests pin the capacity config themselves (base weights and tiers) so a later recalibration of
 * config/ai_customer_capacity.php never changes what they assert.
 */
trait CreatesAiExperienceScenarios
{
    protected function pinCapacityConfig(): void
    {
        config()->set('ai_customer_capacity.nok_per_unit', 0.10);
        config()->set('ai_customer_capacity.base', [
            'basis' => 500, 'per_user' => 50,
            'options' => ['risk' => 150, 'objectives' => 100, 'compliance' => 150, 'supplier' => 150, 'tender' => 400],
        ]);
        config()->set('ai_customer_capacity.tiers', [
            'level_1' => ['name' => 'Level 1', 'multiplier' => 1.00, 'active' => true, 'sort_order' => 10],
            'level_2' => ['name' => 'Level 2', 'multiplier' => 1.50, 'active' => true, 'sort_order' => 20],
            'level_3' => ['name' => 'Level 3', 'multiplier' => 2.00, 'active' => true, 'sort_order' => 30],
        ]);
        config()->set('ai_customer_capacity.default_tier', 'level_1');
        config()->set('ai_experience.finalize_after_days', 3);
    }

    /**
     * A customer anchored on $anchor holding exactly $packages, with $users active users.
     *
     * @param  list<string>  $packages
     */
    protected function experienceCustomer(string $name, array $packages = ['basis'], int $users = 1, ?string $tier = 'level_1', string $anchor = '2026-10-01 00:00:00'): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        $customer = Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
            'subscription_plan' => Customer::PLAN_PRO,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'billing_anchor_at' => $anchor,
            'ai_capacity_tier' => $tier,
        ]);
        $customer->forceFill(['created_at' => $anchor])->save();

        // TestCase hands every fixture customer Tender; hold exactly what the test names.
        $customer->packageEntitlements()->delete();
        $modules = app(ModuleEntitlementService::class);

        foreach ($packages as $package) {
            $modules->activatePackage($customer, $package);
        }

        for ($i = 0; $i < $users; $i++) {
            $this->experienceUser($customer);
        }

        return $customer->refresh();
    }

    protected function experienceUser(Customer $customer, bool $active = true): User
    {
        return User::query()->create([
            'customer_id' => $customer->id,
            'name' => 'User '.Str::random(6),
            'email' => Str::lower(Str::random(12)).'@procynia.test',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'is_active' => $active,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    protected function experienceAttempt(?Customer $customer, string $at, float $costNok, array $attributes = []): AiUsageAttempt
    {
        $operation = $attributes['operation_key'] ?? 'wiki.generate_page';

        return AiUsageAttempt::query()->create(array_merge([
            'customer_id' => $customer?->id,
            'attribution' => $customer === null ? 'system' : 'customer',
            'ledger_version' => AiUsageAttempt::LEDGER_VERSION,
            'feature' => explode('.', $operation)[0],
            'operation_key' => $operation,
            'provider' => 'openai', 'endpoint' => 'responses', 'model' => 'gpt-4.1-mini',
            'status' => AiUsageAttempt::STATUS_SUCCESS,
            'input_tokens' => 1000, 'output_tokens' => 100, 'total_tokens' => 1100,
            'cost_nok' => $costNok, 'cost_status' => 'known',
            'reserved_cost_nok' => $costNok * 2,
            'settlement_status' => AiUsageAttempt::SETTLEMENT_SETTLED,
            'capacity_verdict' => 'allow',
            'started_at' => CarbonImmutable::parse($at, 'UTC'),
            'finished_at' => CarbonImmutable::parse($at, 'UTC')->addSeconds(5),
        ], $attributes));
    }

    /** Puts the trusted-ledger boundary at $at with a system row, so later periods are fully covered. */
    protected function trustedBoundaryAt(string $at = '2026-09-01 00:00:00'): void
    {
        $this->experienceAttempt(null, $at, 0.0, ['operation_key' => 'system.health_check', 'feature' => 'system']);
    }
}
