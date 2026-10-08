<?php

namespace Tests\Feature\Services;

use App\Data\Ai\AiCallContext;
use App\Exceptions\Ai\AiCostControlException;
use App\Models\AiModelPrice;
use App\Models\AiUsageAttempt;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Commercial\AiCostControlService;
use App\Services\Ai\Operational\AiOperationalPricingService;
use App\Support\Ai\AiCallContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

/**
 * Real parallel processes against the shared AI capacity: N forked workers admit a call for the
 * same customer at the same instant, with room for only K. Exactly K may be admitted, and each
 * admitted call must leave exactly one pending attempt holding its reservation.
 *
 * This is the case a transaction-wrapped test cannot show — the children only see committed rows —
 * so the fixture is committed and removed again in finally. It uses no RefreshDatabase.
 */
class CustomerAiCapacityConcurrencyTest extends TestCase
{
    private const WORKERS = 8;

    private const MARKER = 'capacity-concurrency-';

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the parallel capacity test.');
        }

        $this->cleanup();
    }

    public function test_parallel_calls_near_the_limit_admit_only_what_fits(): void
    {
        config()->set('ai_customer_capacity.enforcement', 'enforce');
        config()->set('services.openai.api_key', 'test-key');

        try {
            $customer = $this->fixture();
            $estimateNok = (float) app(AiOperationalPricingService::class)->estimateMaxCostNok('openai', 'gpt-4.1-mini', null, null, 'tender.requirement_answer');
            $this->assertGreaterThan(0.0, $estimateNok);

            // Each call is exactly 3 units; 10 included units hold three of them, never four.
            config()->set('ai_customer_capacity.nok_per_unit', $estimateNok / 3);

            $results = $this->runInParallel($customer->id);

            $admitted = count(array_filter($results, fn (string $result): bool => $result === 'admitted'));
            $refused = count(array_filter($results, fn (string $result): bool => $result === AiCostControlException::CAPACITY_INSUFFICIENT));

            $this->assertCount(self::WORKERS, $results, 'Every worker must report: '.json_encode($results));
            $this->assertSame(3, $admitted, 'Only the calls that fit may be admitted: '.json_encode($results));
            $this->assertSame(self::WORKERS - 3, $refused, json_encode($results));

            // No lost and no duplicate reservation: one pending attempt per admitted call.
            $attempts = AiUsageAttempt::query()->where('customer_id', $customer->id)->get();
            $this->assertCount(3, $attempts);
            $this->assertTrue($attempts->every(fn (AiUsageAttempt $attempt): bool => $attempt->settlement_status === AiUsageAttempt::SETTLEMENT_PENDING
                && abs((float) $attempt->reserved_cost_nok - $estimateNok) < 0.0001));
        } finally {
            $this->cleanup();
        }
    }

    /** @return list<string> One result per worker: 'admitted', a refusal reason, or an error. */
    private function runInParallel(int $customerId): array
    {
        $directory = sys_get_temp_dir().'/'.self::MARKER.Str::random(8);
        mkdir($directory);
        $startAt = microtime(true) + 1.5;

        // The parent's connection must not be shared with the children: each forks with none and
        // opens its own, and the parent reconnects after they are done.
        DB::purge();
        $pids = [];

        for ($worker = 0; $worker < self::WORKERS; $worker++) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                $result = 'error';

                try {
                    usleep((int) max(0, ($startAt - microtime(true)) * 1_000_000));
                    app(AiCallContextScope::class)->within(
                        new AiCallContext(customerId: $customerId, operation: 'tender.requirement_answer', feature: 'tender'),
                        fn () => app(AiCostControlService::class)->admit(
                            (new AiCallContext(customerId: $customerId, operation: 'tender.requirement_answer', feature: 'tender'))->forProviderCall('gpt-4.1-mini', 'responses'),
                            'responses',
                        ),
                    );
                    $result = 'admitted';
                } catch (AiCostControlException $exception) {
                    $result = $exception->reason;
                } catch (Throwable $exception) {
                    $result = 'error: '.$exception->getMessage();
                }

                file_put_contents("{$directory}/{$worker}", $result);
                DB::disconnect();
                // Leave without running PHPUnit's shutdown handlers in the child.
                posix_kill(getmypid(), SIGKILL);
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        DB::reconnect();
        $results = [];

        foreach (glob("{$directory}/*") as $file) {
            $results[] = (string) file_get_contents($file);
            unlink($file);
        }

        rmdir($directory);

        return $results;
    }

    private function fixture(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        AiModelPrice::query()->create([
            'provider' => 'openai', 'model' => 'gpt-4.1-mini', 'currency' => 'usd',
            'input_price_per_1m_tokens' => 0.40, 'cached_input_price_per_1m_tokens' => 0.10,
            'output_price_per_1m_tokens' => 1.60, 'valid_from' => now()->subDay()->toDateString(),
            'is_active' => true, 'last_verified_at' => now(), 'source_url' => 'https://'.self::MARKER.'test',
        ]);
        ExchangeRate::query()->create([
            'base_currency' => 'USD', 'quote_currency' => 'NOK', 'rate' => 10.0,
            'rate_date' => now()->toDateString(), 'source' => self::MARKER, 'fetched_at' => now(),
        ]);

        return Customer::query()->create([
            'name' => 'Concurrency '.Str::random(6),
            'slug' => self::MARKER.Str::lower(Str::random(8)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
            'subscription_plan' => Customer::PLAN_PRO,
            'included_ai_credits' => 5,
            'included_ai_units' => 10,
        ]);
    }

    private function cleanup(): void
    {
        $customerIds = Customer::query()->where('slug', 'like', self::MARKER.'%')->pluck('id');

        foreach (['ai_usage_attempts', 'ai_operational_budget_periods', 'customer_package_entitlements'] as $table) {
            DB::table($table)->whereIn('customer_id', $customerIds)->delete();
        }

        Customer::query()->whereIn('id', $customerIds)->delete();
        AiModelPrice::query()->where('source_url', 'https://'.self::MARKER.'test')->delete();
        ExchangeRate::query()->where('source', self::MARKER)->delete();
    }
}
