<?php

namespace Tests\Support;

use App\Models\AiCustomerExperiencePeriod;
use App\Models\AiUsageAttempt;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\Ai\Experience\AiExperienceSnapshotService;
use App\Services\Modules\ModuleEntitlementService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Data for tests/e2e/ai-experience.spec.js — Admin → AI-erfaring.
 *
 * NO PROVIDER CALL. Two customers of the spec's own, anchored three months back so two whole billing
 * periods are already final; trusted usage is written straight into the ledger (tagged with the
 * spec's correlation id) and the snapshots are built for those two customers only. Real customers'
 * snapshots are never touched. cleanup() removes the customers (their snapshots cascade), their
 * users and the tagged attempts.
 */
final class AiExperienceE2EFixture
{
    private const TAG = 'e2e-ai-experience-';

    private const NAME = 'E2E AI-erfaring ';

    /** @return array<string, mixed> */
    public static function seed(string $suffix): array
    {
        $anchor = CarbonImmutable::now('UTC')->subMonthsNoOverflow(3)->startOfMonth();
        $usage = $anchor->addMonthNoOverflow();

        $customers = DB::transaction(function () use ($suffix, $anchor, $usage): array {
            $alfa = self::customer("Alfa {$suffix}", ['basis'], 2, 'level_1', $anchor);
            $beta = self::customer("Beta {$suffix}", ['basis', 'tender'], 4, 'level_2', $anchor);

            // The first row at the anchor: these periods lie wholly inside trusted data.
            self::attempt($alfa, $suffix, $anchor, 'wiki.generate_page', 0.0);
            self::attempt($alfa, $suffix, $usage->addDays(3), 'wiki.generate_page', 10.0);
            self::attempt($beta, $suffix, $usage->addDays(3), 'wiki.generate_page', 12.5);
            self::attempt($beta, $suffix, $usage->addDays(4), 'tender.requirement_answer', 37.5);

            return [$alfa, $beta];
        });

        $snapshots = app()->make(AiExperienceSnapshotService::class);

        foreach ($customers as $customer) {
            $snapshots->refreshCustomer($customer);
        }

        [$alfa, $beta] = $customers;

        return [
            'alfa' => ['id' => $alfa->id, 'name' => $alfa->name],
            'beta' => ['id' => $beta->id, 'name' => $beta->name],
            'usage_period_start' => $usage->toDateString(),
            'beta_period' => AiCustomerExperiencePeriod::query()->where('customer_id', $beta->id)->where('period_start', $usage)->value('included_units'),
        ];
    }

    /** Removes this run's data, or every run's leftovers when no suffix is given. */
    public static function cleanup(?string $suffix = null): void
    {
        $customers = Customer::query()->where('name', 'like', self::NAME.'%'.($suffix ?? ''))->pluck('id');

        AiUsageAttempt::query()->where('request_correlation_id', 'like', self::TAG.($suffix ?? '').'%')->delete();
        AiCustomerExperiencePeriod::query()->whereIn('customer_id', $customers)->delete();
        User::query()->whereIn('customer_id', $customers)->delete();
        Customer::query()->whereIn('id', $customers)->get()->each->delete();
    }

    /** @return array{customers: int, attempts: int, snapshots: int} */
    public static function remaining(string $suffix): array
    {
        $customers = Customer::query()->where('name', 'like', self::NAME.'%'.$suffix)->pluck('id');

        return [
            'customers' => $customers->count(),
            'attempts' => AiUsageAttempt::query()->where('request_correlation_id', self::TAG.$suffix)->count(),
            'snapshots' => AiCustomerExperiencePeriod::query()->whereIn('customer_id', $customers)->count(),
        ];
    }

    /** @param list<string> $packages */
    private static function customer(string $name, array $packages, int $users, string $tier, CarbonImmutable $anchor): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        $customer = Customer::query()->create([
            'name' => self::NAME.$name,
            'slug' => 'e2e-ai-erfaring-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
            'subscription_plan' => Customer::PLAN_PRO,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'billing_anchor_at' => $anchor,
            'ai_capacity_tier' => $tier,
        ]);
        $customer->forceFill(['created_at' => $anchor])->save();

        $customer->packageEntitlements()->delete();

        foreach ($packages as $package) {
            app(ModuleEntitlementService::class)->activatePackage($customer, $package);
        }

        for ($i = 0; $i < $users; $i++) {
            User::query()->create([
                'customer_id' => $customer->id,
                'name' => 'E2E erfaring '.Str::random(5),
                'email' => 'e2e-ai-erfaring-'.Str::lower(Str::random(10)).'@procynia.test',
                'password' => bcrypt(Str::random(20)),
                'role' => User::ROLE_USER,
                'bid_role' => User::BID_ROLE_CONTRIBUTOR,
                'is_active' => true,
            ]);
        }

        return $customer->refresh();
    }

    private static function attempt(Customer $customer, string $suffix, CarbonImmutable $at, string $operation, float $costNok): void
    {
        AiUsageAttempt::query()->create([
            'customer_id' => $customer->id, 'attribution' => 'customer',
            'ledger_version' => AiUsageAttempt::LEDGER_VERSION,
            'feature' => explode('.', $operation)[0], 'operation_key' => $operation,
            'request_correlation_id' => self::TAG.$suffix,
            'provider' => 'openai', 'endpoint' => 'responses', 'model' => 'gpt-4.1-mini',
            'status' => AiUsageAttempt::STATUS_SUCCESS,
            'input_tokens' => 1000, 'output_tokens' => 100, 'total_tokens' => 1100,
            'cost_nok' => $costNok, 'cost_status' => 'known', 'reserved_cost_nok' => $costNok * 2,
            'settlement_status' => AiUsageAttempt::SETTLEMENT_SETTLED,
            'capacity_verdict' => 'allow',
            'started_at' => $at, 'finished_at' => $at->addSeconds(5),
        ]);
    }
}
