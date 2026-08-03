<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Data\Ai\Capacity\AiTimeoutRequest;
use App\Services\EnterpriseWiki\EnterpriseWikiAiRequestTimeoutPolicy;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Wiki run-592: EnterpriseWikiAiRequestTimeoutPolicy replaces the hardcoded
 * `timeoutSeconds: 60` that caused the incident (a legitimate ~55s global-plan call left almost
 * no margin) with a deterministic, operation- and workload-aware formula. These tests pin down
 * the exact formula behaviour using overridden config so they never drift silently if the
 * shipped defaults in config/ai_request_timeout.php change.
 */
class EnterpriseWikiAiRequestTimeoutPolicyTest extends TestCase
{
    private const OP = 'enterprise_wiki_maintainer_decision';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai_request_timeout.min_seconds' => 20,
            'ai_request_timeout.max_seconds' => 120,
            'ai_request_timeout.job_budget_margin_ratio' => 0.2,
            'ai_request_timeout.operations.'.self::OP => [
                'base_seconds' => 30,
                'seconds_per_input_chars_unit' => 1.0,
                'input_chars_per_unit' => 2000,
                'seconds_per_output_token' => 0.02,
                'global_plan' => [
                    'base_seconds' => 10,
                    'seconds_per_input_chars_unit' => 0.5,
                    'input_chars_per_unit' => 2000,
                    'seconds_per_output_token' => 0.01,
                ],
                'batch' => [
                    'base_seconds' => 15,
                    'seconds_per_input_chars_unit' => 0.5,
                    'input_chars_per_unit' => 2000,
                    'seconds_per_output_token' => 0.01,
                    'seconds_per_candidate' => 2.0,
                ],
            ],
        ]);
    }

    private function policy(): EnterpriseWikiAiRequestTimeoutPolicy
    {
        return new EnterpriseWikiAiRequestTimeoutPolicy;
    }

    public function test_resolve_computes_base_plus_input_plus_output_terms(): void
    {
        // base=30 + input(4000 chars / 2000 per unit = 2 units * 1.0s)=2 + output(500*0.02)=10 => 42
        $plan = $this->policy()->resolve(new AiTimeoutRequest(self::OP, 4000, 500));

        $this->assertSame(42, $plan->timeoutSeconds);
        $this->assertFalse($plan->wasClampedToRange);
        $this->assertFalse($plan->wasClampedToJobBudget);
    }

    public function test_global_plan_and_batch_profiles_choose_different_values_for_identical_input(): void
    {
        $request = new AiTimeoutRequest(self::OP, 4000, 500);

        $globalPlan = $this->policy()->resolveForGlobalPlan($request);
        $batch = $this->policy()->resolveForBatch($request, candidatesInBatch: 3);

        // global_plan: base=10 + input(2 units*0.5)=1 + output(500*0.01)=5 => 16, clamped up to min=20
        $this->assertSame(20, $globalPlan->timeoutSeconds);
        $this->assertTrue($globalPlan->wasClampedToRange);
        // batch: base=15 + input(2*0.5)=1 + output(500*0.01)=5 + candidates(3*2.0)=6 => 27, above min
        $this->assertSame(27, $batch->timeoutSeconds);
        $this->assertFalse($batch->wasClampedToRange);
        $this->assertNotSame($globalPlan->timeoutSeconds, $batch->timeoutSeconds);
    }

    public function test_batch_timeout_grows_with_candidate_count(): void
    {
        $request = new AiTimeoutRequest(self::OP, 4000, 500);

        $fewCandidates = $this->policy()->resolveForBatch($request, candidatesInBatch: 1);
        $manyCandidates = $this->policy()->resolveForBatch($request, candidatesInBatch: 10);

        $this->assertGreaterThan($fewCandidates->timeoutSeconds, $manyCandidates->timeoutSeconds);
    }

    public function test_timeout_grows_with_input_size_and_output_budget(): void
    {
        $small = $this->policy()->resolve(new AiTimeoutRequest(self::OP, 1000, 200));
        $large = $this->policy()->resolve(new AiTimeoutRequest(self::OP, 50000, 4000));

        $this->assertGreaterThan($small->timeoutSeconds, $large->timeoutSeconds);
    }

    public function test_timeout_is_clamped_to_configured_maximum(): void
    {
        $plan = $this->policy()->resolve(new AiTimeoutRequest(self::OP, 500000, 100000));

        $this->assertSame(120, $plan->timeoutSeconds);
        $this->assertTrue($plan->wasClampedToRange);
    }

    public function test_timeout_is_clamped_to_configured_minimum(): void
    {
        // base=30 alone already exceeds min=20, so lower it below min to prove the clamp.
        config(['ai_request_timeout.operations.'.self::OP.'.base_seconds' => 5]);
        $plan = $this->policy()->resolve(new AiTimeoutRequest(self::OP, 0, 0));

        $this->assertSame(20, $plan->timeoutSeconds);
        $this->assertTrue($plan->wasClampedToRange);
    }

    public function test_timeout_never_exceeds_remaining_job_budget_margin(): void
    {
        // Unclamped would be 42s (see first test); a tight remaining budget of 30s with a 20%
        // margin yields a safe ceiling of floor(30 * 0.8) = 24s, well below 42s.
        $plan = $this->policy()->resolve(new AiTimeoutRequest(self::OP, 4000, 500, remainingJobBudgetSeconds: 30));

        $this->assertSame(24, $plan->timeoutSeconds);
        $this->assertTrue($plan->wasClampedToJobBudget);
    }

    public function test_job_budget_clamp_is_never_forced_back_up_to_the_configured_minimum(): void
    {
        // Deliberate design choice: if the job's own remaining budget is genuinely tighter than
        // min_seconds, the resolved timeout must reflect that truthfully rather than silently
        // exceeding the job's real remaining time.
        $plan = $this->policy()->resolve(new AiTimeoutRequest(self::OP, 4000, 500, remainingJobBudgetSeconds: 5));

        $this->assertSame(4, $plan->timeoutSeconds);
        $this->assertLessThan($plan->minSeconds, $plan->timeoutSeconds);
        $this->assertTrue($plan->wasClampedToJobBudget);
    }

    public function test_ample_job_budget_does_not_clamp(): void
    {
        $plan = $this->policy()->resolve(new AiTimeoutRequest(self::OP, 4000, 500, remainingJobBudgetSeconds: 1000));

        $this->assertSame(42, $plan->timeoutSeconds);
        $this->assertFalse($plan->wasClampedToJobBudget);
    }

    public function test_unknown_operation_type_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->policy()->resolve(new AiTimeoutRequest('does_not_exist', 100, 100));
    }

    public function test_resolve_for_global_plan_throws_when_sub_profile_missing(): void
    {
        config(['ai_request_timeout.operations.'.self::OP.'.global_plan' => null]);

        $this->expectException(InvalidArgumentException::class);

        $this->policy()->resolveForGlobalPlan(new AiTimeoutRequest(self::OP, 100, 100));
    }
}
