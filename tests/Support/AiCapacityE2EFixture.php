<?php

namespace Tests\Support;

use App\Models\AiUsageAttempt;
use App\Models\Customer;
use App\Models\CustomerBillingPeriod;
use App\Models\User;
use App\Services\Ai\Commercial\CustomerAiCapacityService;
use App\Services\Modules\ModuleEntitlementService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Data for tests/e2e/ai-capacity.spec.js — the shared AI capacity on Abonnement.
 *
 * NO PROVIDER CALL. Usage is written straight into the ledger as settled attempts, tagged with the
 * spec's correlation id; a billing period is added only when none covers today. The E2E customer
 * holds Basis and several options. For the spec its override is cleared and it is given its own AI
 * capacity tier, so the capacity comes from the tier alone — never from Basis or an option. The
 * previous override and tier, and any option the spec cancels, are remembered outside the spec (so
 * an interrupted run is restored by the next sweep) and put back by cleanup().
 */
final class AiCapacityE2EFixture
{
    private const SYSTEM_OWNER_EMAIL = 'e2e.systemowner@procynia.test';

    private const TAG = 'e2e-ai-capacity-';

    private const OVERRIDE_CACHE_KEY = 'e2e:ai-capacity:previous-included-ai-units';

    private const CANCELLED_CACHE_KEY = 'e2e:ai-capacity:cancelled-options';

    public const TIER = 'level_2';

    /** @return array<string, mixed> The capacity the page must show, as the service computes it, plus the customer's packages. */
    public static function seed(string $suffix): array
    {
        $customer = self::customer();

        if (! Cache::has(self::OVERRIDE_CACHE_KEY)) {
            Cache::forever(self::OVERRIDE_CACHE_KEY, ['value' => $customer->included_ai_units, 'tier' => $customer->ai_capacity_tier]);
        }

        DB::transaction(function () use ($customer, $suffix): void {
            $customer->forceFill(['included_ai_units' => null, 'ai_capacity_tier' => self::TIER])->save();
            $now = CarbonImmutable::now('UTC');

            $covered = CustomerBillingPeriod::query()->where('customer_id', $customer->id)
                ->where('period_start', '<=', $now)->where('period_end', '>', $now)->exists();

            if (! $covered) {
                CustomerBillingPeriod::query()->create([
                    'customer_id' => $customer->id, 'provider' => 'stripe',
                    'provider_subscription_id' => self::TAG.$suffix,
                    'period_start' => $now->startOfMonth(), 'period_end' => $now->startOfMonth()->addMonth(),
                    'interval' => 'month', 'subscription_status' => 'active', 'synced_at' => $now,
                ]);
            }

            // Settled usage spread over Basis and option modules — all from the same pool.
            foreach ([['wiki', 'wiki.generate_page', 60.0], ['tender', 'tender.requirement_answer', 40.0], ['quality', 'quality.interpret_process', 20.0]] as [$feature, $operation, $nok]) {
                AiUsageAttempt::query()->create([
                    'customer_id' => $customer->id, 'attribution' => 'customer',
                    'ledger_version' => AiUsageAttempt::LEDGER_VERSION,
                    'feature' => $feature, 'operation_key' => $operation,
                    'request_correlation_id' => self::TAG.$suffix,
                    'provider' => 'openai', 'endpoint' => 'responses', 'model' => 'gpt-4.1-mini',
                    'status' => AiUsageAttempt::STATUS_SUCCESS,
                    'input_tokens' => 1000, 'output_tokens' => 100, 'total_tokens' => 1100,
                    'cost_nok' => $nok, 'cost_status' => 'known',
                    'settlement_status' => AiUsageAttempt::SETTLEMENT_SETTLED,
                    'started_at' => $now, 'finished_at' => $now,
                ]);
            }
        });

        return self::capacity();
    }

    /** Cancels one option the customer holds — remembered so cleanup() orders it again. */
    public static function cancelOption(string $package): array
    {
        $customer = self::customer();
        $cancelled = Cache::get(self::CANCELLED_CACHE_KEY, []);

        if (app(ModuleEntitlementService::class)->cancelOption($customer, $package)) {
            Cache::forever(self::CANCELLED_CACHE_KEY, array_values(array_unique([...$cancelled, $package])));
        }

        return self::capacity();
    }

    /** @return array<string, mixed> */
    private static function capacity(): array
    {
        $customer = self::customer();

        return app(CustomerAiCapacityService::class)->forCustomer($customer)->toArray()
            + ['packages' => app(ModuleEntitlementService::class)->activePackageKeys($customer)];
    }

    /** Removes this run's data, or every run's leftovers when no suffix is given. */
    public static function cleanup(?string $suffix = null): void
    {
        $tag = self::TAG.($suffix ?? '');

        AiUsageAttempt::query()->where('request_correlation_id', 'like', $tag.'%')->delete();
        CustomerBillingPeriod::query()->where('provider_subscription_id', 'like', $tag.'%')->delete();

        if (Cache::has(self::OVERRIDE_CACHE_KEY)) {
            $previous = Cache::get(self::OVERRIDE_CACHE_KEY);
            self::customer()->forceFill([
                'included_ai_units' => $previous['value'] ?? null,
                'ai_capacity_tier' => $previous['tier'] ?? null,
            ])->save();
            Cache::forget(self::OVERRIDE_CACHE_KEY);
        }

        foreach (Cache::get(self::CANCELLED_CACHE_KEY, []) as $package) {
            app(ModuleEntitlementService::class)->activatePackage(self::customer(), $package);
        }
        Cache::forget(self::CANCELLED_CACHE_KEY);
    }

    /** @return array{attempts: int, periods: int} */
    public static function remaining(string $suffix): array
    {
        return [
            'attempts' => AiUsageAttempt::query()->where('request_correlation_id', self::TAG.$suffix)->count(),
            'periods' => CustomerBillingPeriod::query()->where('provider_subscription_id', self::TAG.$suffix)->count(),
        ];
    }

    private static function customer(): Customer
    {
        return User::query()->where('email', self::SYSTEM_OWNER_EMAIL)->firstOrFail()->customer;
    }
}
