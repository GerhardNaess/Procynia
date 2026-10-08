<?php

namespace Tests\Support;

use App\Models\AiUsageAttempt;
use App\Models\Customer;
use App\Models\CustomerBillingPeriod;
use App\Models\User;
use App\Services\Ai\Commercial\CustomerAiCapacityService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Data for tests/e2e/ai-capacity.spec.js — the shared AI capacity on Abonnement.
 *
 * NO PROVIDER CALL. Usage is written straight into the ledger as settled attempts, tagged with the
 * spec's correlation id; a billing period is added only when none covers today. The E2E customer's
 * own capacity override is remembered outside the spec (so an interrupted run is restored by the
 * next sweep) and put back by cleanup().
 */
final class AiCapacityE2EFixture
{
    private const SYSTEM_OWNER_EMAIL = 'e2e.systemowner@procynia.test';

    private const TAG = 'e2e-ai-capacity-';

    private const OVERRIDE_CACHE_KEY = 'e2e:ai-capacity:previous-included-ai-units';

    /** @return array<string, mixed> The capacity the page must show, as the service computes it. */
    public static function seed(string $suffix): array
    {
        $customer = self::customer();

        if (! Cache::has(self::OVERRIDE_CACHE_KEY)) {
            Cache::forever(self::OVERRIDE_CACHE_KEY, ['value' => $customer->included_ai_units]);
        }

        DB::transaction(function () use ($customer, $suffix): void {
            $customer->forceFill(['included_ai_units' => 5000])->save();
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

            // 320 NOK settled = 3 200 units at the v1 rate, spread over three modules.
            foreach ([['wiki', 'wiki.generate_page', 200.0], ['tender', 'tender.requirement_answer', 80.0], ['quality', 'quality.interpret_process', 40.0]] as [$feature, $operation, $nok]) {
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

        return app(CustomerAiCapacityService::class)->forCustomer($customer->fresh())->toArray();
    }

    /** Removes this run's data, or every run's leftovers when no suffix is given. */
    public static function cleanup(?string $suffix = null): void
    {
        $tag = self::TAG.($suffix ?? '');

        AiUsageAttempt::query()->where('request_correlation_id', 'like', $tag.'%')->delete();
        CustomerBillingPeriod::query()->where('provider_subscription_id', 'like', $tag.'%')->delete();

        if (Cache::has(self::OVERRIDE_CACHE_KEY)) {
            self::customer()->forceFill(['included_ai_units' => Cache::get(self::OVERRIDE_CACHE_KEY)['value'] ?? null])->save();
            Cache::forget(self::OVERRIDE_CACHE_KEY);
        }
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
