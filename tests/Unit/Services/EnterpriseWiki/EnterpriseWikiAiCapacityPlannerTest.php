<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Data\Ai\Capacity\AiCapacityPlan;
use App\Data\Ai\Capacity\AiCapacityRequest;
use App\Services\EnterpriseWiki\EnterpriseWikiAiCapacityPlanner;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionPrompt;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Generic, config-driven tests for EnterpriseWikiAiCapacityPlanner — deliberately independent of
 * any specific Wiki decision content (see the class docblock: it knows nothing about concept
 * pages or Incident Management). Uses a dedicated 'test_operation' profile set via config()
 * overrides so assertions are not coupled to the real
 * 'enterprise_wiki_maintainer_decision' profile's tuned defaults.
 */
class EnterpriseWikiAiCapacityPlannerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Fully-qualified per-key paths only — never replace the whole 'models'/'operations'
        // array, or the real 'enterprise_wiki_maintainer_decision' profile used by the
        // fixture-based tests below would be wiped out.
        config([
            'ai_capacity.models.test-model' => ['max_output_tokens' => 5000],
            'ai_capacity.default_model_max_output_tokens' => 4000,
            'ai_capacity.operations.test_operation' => [
                'base_overhead_tokens' => 100,
                'tokens_per_result_object' => 50,
                'tokens_per_input_chars_unit' => 10,
                'input_chars_per_unit' => 100,
                'reasoning_token_buffer' => 0,
                'minimum_output_tokens' => 300,
                'safety_margin_ratio' => 0.2,
                'retry_multiplier' => 2.0,
                'max_output_tokens' => 4000,
                'max_capacity_retries' => 1,
                'global_plan' => [
                    'base_overhead_tokens' => 80,
                    'tokens_per_result_object' => 50,
                    'tokens_per_input_chars_unit' => 2,
                    'input_chars_per_unit' => 100,
                    'reasoning_token_buffer' => 0,
                    'minimum_output_tokens' => 150,
                    'safety_margin_ratio' => 0.2,
                    'retry_multiplier' => 2.0,
                ],
                'batch' => [
                    'batch_overhead_tokens' => 50,
                    'tokens_per_candidate' => 100,
                    'safety_margin_ratio' => 0.2,
                    'minimum_output_tokens' => 200,
                    'max_candidates_per_batch' => 5,
                    'min_candidates_per_batch' => 1,
                ],
            ],
        ]);
    }

    private function planner(): EnterpriseWikiAiCapacityPlanner
    {
        return app(EnterpriseWikiAiCapacityPlanner::class);
    }

    private function request(array $overrides = []): AiCapacityRequest
    {
        return new AiCapacityRequest(
            operationType: $overrides['operationType'] ?? 'test_operation',
            model: $overrides['model'] ?? 'test-model',
            inputSizeChars: $overrides['inputSizeChars'] ?? 100,
            expectedResultObjects: $overrides['expectedResultObjects'] ?? 2,
            retryAttempt: $overrides['retryAttempt'] ?? 0,
        );
    }

    // 1. Small task gets the normal minimum/base budget.
    public function test_small_task_gets_the_minimum_output_tokens_floor(): void
    {
        $plan = $this->planner()->plan($this->request([
            'inputSizeChars' => 0,
            'expectedResultObjects' => 0,
        ]));

        // base(100) + objects(0) + input(0) = 100; margin 20% => 120; floored at minimum 300.
        $this->assertSame(300, $plan->chosenMaxOutputTokens);
        $this->assertSame(300, $plan->estimatedMinimumTokens);
    }

    // 2. Larger task gets a higher budget.
    public function test_larger_task_gets_a_higher_budget_than_a_smaller_one(): void
    {
        $small = $this->planner()->plan($this->request(['inputSizeChars' => 100, 'expectedResultObjects' => 1]));
        $large = $this->planner()->plan($this->request(['inputSizeChars' => 5000, 'expectedResultObjects' => 10]));

        $this->assertGreaterThan($small->chosenMaxOutputTokens, $large->chosenMaxOutputTokens);
    }

    // 3. Chosen budget never exceeds the model's maximum.
    public function test_chosen_budget_never_exceeds_the_model_maximum(): void
    {
        $plan = $this->planner()->plan($this->request(['inputSizeChars' => 1_000_000, 'expectedResultObjects' => 1000]));

        $this->assertLessThanOrEqual(5000, $plan->chosenMaxOutputTokens);
        $this->assertTrue($plan->wasClamped);
    }

    // 4. Safety margin is included.
    public function test_safety_margin_increases_the_preferred_budget_over_the_raw_estimate(): void
    {
        $plan = $this->planner()->plan($this->request(['inputSizeChars' => 1000, 'expectedResultObjects' => 5]));

        // need = 100 + 50*5 + 10*10 = 450; with 20% margin => 540, above the raw need.
        $this->assertSame(450, $plan->estimatedNeedTokens);
        $this->assertGreaterThan($plan->estimatedNeedTokens, $plan->chosenMaxOutputTokens);
    }

    // 5. Same input yields the same budget (deterministic).
    public function test_same_request_yields_the_same_plan_every_time(): void
    {
        $request = $this->request(['inputSizeChars' => 2345, 'expectedResultObjects' => 4]);

        $first = $this->planner()->plan($request);
        $second = $this->planner()->plan($request);

        $this->assertEquals($first, $second);
    }

    // 6. Unknown operation / model is handled clearly.
    public function test_unknown_operation_throws_a_clear_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no capacity profile configured for operation \[does_not_exist\]/');

        $this->planner()->plan($this->request(['operationType' => 'does_not_exist']));
    }

    public function test_unknown_model_falls_back_to_the_conservative_default_ceiling_not_unbounded(): void
    {
        $plan = $this->planner()->plan($this->request([
            'model' => 'some-unlisted-model',
            'inputSizeChars' => 1_000_000,
            'expectedResultObjects' => 1000,
        ]));

        // default_model_max_output_tokens(4000) is lower than the operation's own max(4000) tie,
        // so the resolved ceiling must still be a small, explicit number, never unbounded.
        $this->assertLessThanOrEqual(4000, $plan->maxAllowedTokens);
    }

    // 7. Invalid configuration is rejected.
    public function test_invalid_configuration_with_minimum_above_maximum_is_rejected(): void
    {
        config(['ai_capacity.operations.test_operation.minimum_output_tokens' => 10_000]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/exceeds its resolved maximum/');

        $this->planner()->plan($this->request());
    }

    public function test_invalid_configuration_with_non_positive_resolved_maximum_is_rejected(): void
    {
        config([
            'ai_capacity.operations.test_operation.max_output_tokens' => 0,
            'ai_capacity.models.test-model.max_output_tokens' => 0,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must be a positive integer/');

        $this->planner()->plan($this->request());
    }

    // 8. Retry budget is higher than the first budget.
    public function test_retry_budget_is_higher_than_the_first_attempt_budget(): void
    {
        $first = $this->planner()->plan($this->request(['retryAttempt' => 0]));
        $retry = $this->planner()->plan($this->request(['retryAttempt' => 1]));

        $this->assertGreaterThan($first->chosenMaxOutputTokens, $retry->chosenMaxOutputTokens);
        $this->assertSame(1, $retry->retryLevel);
    }

    // 9. Retry budget stops at the absolute maximum.
    public function test_retry_budget_is_still_capped_at_the_absolute_maximum(): void
    {
        config(['ai_capacity.operations.test_operation.retry_multiplier' => 100.0]);

        $retry = $this->planner()->plan($this->request(['retryAttempt' => 1, 'inputSizeChars' => 3000, 'expectedResultObjects' => 20]));

        // Bound by the operation's own max_output_tokens (4000), which is lower than the
        // model's ceiling (5000) — the lesser of the two always wins.
        $this->assertSame(4000, $retry->chosenMaxOutputTokens);
        $this->assertTrue($retry->wasClamped);
    }

    // 10. The result carries an explainable decision basis.
    public function test_plan_carries_an_explainable_basis_string(): void
    {
        $plan = $this->planner()->plan($this->request(['inputSizeChars' => 1000, 'expectedResultObjects' => 3]));

        $this->assertStringContainsString('base=100', $plan->basis);
        $this->assertStringContainsString('need=', $plan->basis);
        $this->assertStringContainsString('preferred=', $plan->basis);
        $this->assertStringContainsString('capped at', $plan->basis);
    }

    public function test_plan_reserves_a_split_required_strategy_slot_without_using_it(): void
    {
        $plan = $this->planner()->plan($this->request());

        $this->assertSame('single_call', $plan->strategy);
    }

    // =========================================================================
    // Split-strategy determination (single_call / capacity_retry / split_required)
    // =========================================================================

    // 1. Small task chooses single_call.
    public function test_small_task_chooses_single_call_strategy(): void
    {
        $plan = $this->planner()->plan($this->request(['inputSizeChars' => 100, 'expectedResultObjects' => 2]));

        $this->assertSame(AiCapacityPlan::STRATEGY_SINGLE_CALL, $plan->strategy);
    }

    // 2. Large task chooses split_required.
    public function test_large_task_chooses_split_required_strategy(): void
    {
        $plan = $this->planner()->plan($this->request(['inputSizeChars' => 50_000, 'expectedResultObjects' => 100]));

        $this->assertSame(AiCapacityPlan::STRATEGY_SPLIT_REQUIRED, $plan->strategy);
    }

    /**
     * 3. Retry with no real extra headroom chooses split over a pointless retry: once the first
     * attempt's own (unclamped) preferred estimate already exceeds the ceiling, a retry
     * mathematically clamps to the EXACT SAME chosen budget as the first attempt — proving retry
     * cannot help, which is precisely why split_required is chosen instead.
     */
    public function test_split_required_means_a_retry_would_be_pointless(): void
    {
        $request0 = $this->request(['inputSizeChars' => 50_000, 'expectedResultObjects' => 100, 'retryAttempt' => 0]);
        $request1 = $this->request(['inputSizeChars' => 50_000, 'expectedResultObjects' => 100, 'retryAttempt' => 1]);

        $plan0 = $this->planner()->plan($request0);
        $plan1 = $this->planner()->plan($request1);

        $this->assertSame(AiCapacityPlan::STRATEGY_SPLIT_REQUIRED, $plan0->strategy);
        $this->assertSame($plan0->chosenMaxOutputTokens, $plan1->chosenMaxOutputTokens);
    }

    public function test_moderate_task_chooses_capacity_retry_strategy_when_a_retry_would_still_help(): void
    {
        // need = 200 + 10*200 = 2200; preferred0 = ceil(2200*1.2) = 2640 (fits under 4000);
        // preferred1 = ceil(2640*2) = 5280 (would not fit) => capacity_retry.
        $plan = $this->planner()->plan($this->request(['inputSizeChars' => 20_000, 'expectedResultObjects' => 2]));

        $this->assertSame(AiCapacityPlan::STRATEGY_CAPACITY_RETRY, $plan->strategy);
    }

    // 4. Same input yields the same strategy every time (covered generally by determinism test
    // above; this asserts it specifically for the split_required boundary case).
    public function test_same_input_yields_the_same_strategy_every_time(): void
    {
        $request = $this->request(['inputSizeChars' => 50_000, 'expectedResultObjects' => 100]);

        $this->assertSame(
            $this->planner()->plan($request)->strategy,
            $this->planner()->plan($request)->strategy,
        );
    }

    // =========================================================================
    // Batch planning (planBatchCount / planBatchCall)
    // =========================================================================

    // 5. Batch size stays within configured bounds.
    public function test_batch_count_splits_candidates_within_configured_bounds(): void
    {
        $batches = $this->planner()->planBatchCount('test_operation', 'test-model', 12);

        // capacityDrivenMax = floor((4000/1.2 - 50) / 100) = 32; capped at configured max=5;
        // batchCount = ceil(12/5) = 3; distributed evenly.
        $this->assertSame([4, 4, 4], $batches);
        $this->assertSame(12, array_sum($batches));

        foreach ($batches as $size) {
            $this->assertLessThanOrEqual(5, $size);
            $this->assertGreaterThanOrEqual(1, $size);
        }
    }

    public function test_batch_count_returns_empty_for_zero_candidates(): void
    {
        $this->assertSame([], $this->planner()->planBatchCount('test_operation', 'test-model', 0));
    }

    public function test_batch_count_returns_a_single_batch_when_all_candidates_fit(): void
    {
        $this->assertSame([3], $this->planner()->planBatchCount('test_operation', 'test-model', 3));
    }

    public function test_batch_count_is_deterministic(): void
    {
        $first = $this->planner()->planBatchCount('test_operation', 'test-model', 17);
        $second = $this->planner()->planBatchCount('test_operation', 'test-model', 17);

        $this->assertSame($first, $second);
    }

    // 6. Model/operation maximum is respected for batch calls too.
    public function test_batch_call_never_exceeds_the_model_or_operation_maximum(): void
    {
        $plan = $this->planner()->planBatchCall('test_operation', 'test-model', candidatesInBatch: 5, inputSizeChars: 1_000_000);

        $this->assertLessThanOrEqual(4000, $plan->chosenMaxOutputTokens);
    }

    public function test_batch_call_retry_budget_is_higher_than_first_attempt(): void
    {
        $first = $this->planner()->planBatchCall('test_operation', 'test-model', 3, 500, retryAttempt: 0);
        $retry = $this->planner()->planBatchCall('test_operation', 'test-model', 3, 500, retryAttempt: 1);

        $this->assertGreaterThan($first->chosenMaxOutputTokens, $retry->chosenMaxOutputTokens);
    }

    public function test_global_plan_call_uses_a_much_smaller_input_cost_than_the_whole_decision_estimate(): void
    {
        // Using the SAME large input that pushes plan() to split_required, planGlobalPlanCall()
        // must still resolve to a comfortable single-call-sized budget — otherwise Phase A would
        // itself require splitting, which would be self-defeating.
        $wholeDecisionPlan = $this->planner()->plan($this->request(['inputSizeChars' => 50_000, 'expectedResultObjects' => 100]));
        $globalPlanPlan = $this->planner()->planGlobalPlanCall('test_operation', 'test-model', 2, 50_000);

        $this->assertSame(AiCapacityPlan::STRATEGY_SPLIT_REQUIRED, $wholeDecisionPlan->strategy);
        $this->assertLessThan($wholeDecisionPlan->chosenMaxOutputTokens, $globalPlanPlan->chosenMaxOutputTokens);
        $this->assertLessThanOrEqual(4000, $globalPlanPlan->chosenMaxOutputTokens);
    }

    // =========================================================================
    // Capacity / boundary tests against realistic maintainer-decision-shaped fixtures
    // (tests 26-29 from the task) — uses the REAL 'enterprise_wiki_maintainer_decision'
    // profile from config/ai_capacity.php, not the synthetic 'test_operation' profile above.
    // =========================================================================

    public function test_many_concept_candidates_fit_within_the_planned_budget_for_a_rich_source_document(): void
    {
        // A large, ITIL-style source document — the run-583 growth driver.
        $sourceText = str_repeat(
            'ITIL Incident Management, Problem Management, Change Management og Configuration '.
            'Management er sentrale prosesser i tjenestestyring. ',
            250,
        );

        $plan = app(EnterpriseWikiAiCapacityPlanner::class)->plan(new AiCapacityRequest(
            operationType: 'enterprise_wiki_maintainer_decision',
            model: 'gpt-5',
            inputSizeChars: mb_strlen($sourceText),
            expectedResultObjects: 2,
        ));

        $decision = $this->fixtureDecisionWithCandidates(15);
        $estimatedTokens = (int) ceil(mb_strlen((string) json_encode($decision)) / 4);

        $this->assertLessThanOrEqual($plan->chosenMaxOutputTokens, $estimatedTokens);
    }

    public function test_maximum_expected_candidate_count_produces_a_complete_valid_decision_fixture(): void
    {
        $decision = $this->fixtureDecisionWithCandidates(15);

        $this->assertSame([], EnterpriseWikiMaintainerDecisionPrompt::validate($decision));
    }

    public function test_free_text_fields_in_the_fixture_stay_within_agreed_brevity_bounds(): void
    {
        $decision = $this->fixtureDecisionWithCandidates(15);

        foreach ($decision['concept_candidates'] as $candidate) {
            $this->assertLessThanOrEqual(120, mb_strlen($candidate['independent_reason']));
            $this->assertLessThanOrEqual(80, mb_strlen($candidate['mentioned_context']));
            $this->assertLessThanOrEqual(120, mb_strlen($candidate['justification']));
        }
    }

    /**
     * Tripwire: if concept_candidates gains a field, this count changes and forces a deliberate
     * look at whether config('ai_capacity.operations.enterprise_wiki_maintainer_decision')'s
     * tokens_per_result_object/tokens_per_input_chars_unit still reflect reality, instead of
     * silently under-budgeting like the run-583 incident.
     */
    /**
     * Confirms the REAL 'enterprise_wiki_maintainer_decision' profile (not the synthetic
     * 'test_operation' profile used above) genuinely resolves to split_required for a document
     * large enough to exceed its configured operation maximum (9000) — the actual condition this
     * whole work item exists to handle, not just an artefact of the synthetic test config.
     */
    public function test_real_operation_profile_chooses_split_required_for_a_genuinely_large_document(): void
    {
        $plan = app(EnterpriseWikiAiCapacityPlanner::class)->plan(new AiCapacityRequest(
            operationType: 'enterprise_wiki_maintainer_decision',
            model: 'gpt-5',
            inputSizeChars: 40_000,
            expectedResultObjects: 2,
        ));

        $this->assertSame(AiCapacityPlan::STRATEGY_SPLIT_REQUIRED, $plan->strategy);
    }

    /** The run-584 document pattern (~13k input chars) must still choose single_call — no regression. */
    public function test_real_operation_profile_still_chooses_single_call_for_the_run_584_document_pattern(): void
    {
        $plan = app(EnterpriseWikiAiCapacityPlanner::class)->plan(new AiCapacityRequest(
            operationType: 'enterprise_wiki_maintainer_decision',
            model: 'gpt-5',
            inputSizeChars: 13_197,
            expectedResultObjects: 2,
        ));

        $this->assertNotSame(AiCapacityPlan::STRATEGY_SPLIT_REQUIRED, $plan->strategy);
    }

    /**
     * Count updated for Fase 8K-2 to what the schema actually has: 13. Two of those are 8K-2's
     * granularity fields (relationship, existing_owner_page_id); the assertion had additionally been
     * stale at 9 since has_separate_source_evidence/has_reuse_value were added, so it could not pass
     * at any value but 13.
     *
     * The review this tripwire demands WAS done, and its conclusion is that the capacity profile is
     * NOT yet correct — deliberately left unchanged here rather than adjusted on a guess:
     *
     *  - `patch_targets` is entirely new output that scales with how many existing pages a document
     *    affects, which no existing knob models directly.
     *  - Measured on run 27 (1858-char document, 6 patch targets): the first maintainer attempt was
     *    rejected on max_output_tokens at 4442 tokens, and only the capacity retry at 7774 succeeded.
     *    Raising tokens_per_input_chars_unit 120 -> 150 was tried and did NOT prevent that first-attempt
     *    truncation, so it was reverted — it is not a validated value.
     *  - The bounded repair prompt also grew to 29 676 input chars on that run and routed
     *    split_required, which is a separate cost this profile does not account for.
     *
     * Capacity therefore needs its own measurement pass against logged token usage, not a tuning
     * guess bundled into a contract change. Until then the retry path is what absorbs it.
     *
     * 13 -> 15 for the create-consideration contract (considered_existing_page_ids,
     * considered_rejection_reason). The review this tripwire demands was done and its conclusion is
     * that NO profile change is warranted:
     *
     *  - Both fields are substantive only on a `create` candidate. On reuse/reference_only/exclude
     *    they serialise as `[]` and `null` — under 10 tokens.
     *  - On a create they are a short id list (1-3 integers) and ONE short sentence the prompt caps
     *    explicitly: roughly 100 characters, ~25-30 tokens against a `tokens_per_candidate` of 260.
     *  - That is ~11 % of the per-candidate estimate, well inside the profile's existing 35 %
     *    safety margin, and the create share of candidates is a minority by construction — the whole
     *    point of the create-gate.
     *
     * Deliberately NOT raised: a budget increase is the thing this change set was required not to
     * make, and inflating tokens_per_candidate would also shrink how many candidates fit one batch.
     */
    public function test_concept_candidate_field_count_is_a_deliberate_capacity_tripwire(): void
    {
        $schema = EnterpriseWikiMaintainerDecisionPrompt::jsonSchema();
        $candidateProps = $schema['json_schema']['schema']['properties']['concept_candidates']['items']['properties'];

        $this->assertCount(
            15,
            $candidateProps,
            'concept_candidates field count changed — review the enterprise_wiki_maintainer_decision '.
            'capacity profile in config/ai_capacity.php before assuming existing budgets still hold.',
        );
    }

    /**
     * Fase 8K-2 companion tripwire: patch_targets is output the profile has to pay for too, and its
     * field count is the per-target cost driver. Same contract as the candidate tripwire above —
     * changing it means re-reviewing the capacity profile, not just updating a number. It matters more
     * than the candidate one, because per-target output is the cost the profile currently does not
     * model at all (see the note above).
     */
    public function test_patch_target_field_count_is_a_deliberate_capacity_tripwire(): void
    {
        $schema = EnterpriseWikiMaintainerDecisionPrompt::jsonSchema();
        $targetProps = $schema['json_schema']['schema']['properties']['patch_targets']['items']['properties'];

        $this->assertCount(
            12,
            $targetProps,
            'patch_targets field count changed — review the enterprise_wiki_maintainer_decision '.
            'capacity profile in config/ai_capacity.php before assuming existing budgets still hold.',
        );
    }

    /** @return array<string, mixed> */
    private function fixtureDecisionWithCandidates(int $count): array
    {
        return [
            'source_article' => [
                'action' => 'create',
                'title' => 'Masterdata ITIL',
                'proposed_slug' => 'masterdata-itil-ab12cd',
                'reason' => 'New source document.',
            ],
            'source_summary' => [
                'action' => 'create',
                'title' => 'Sammendrag: Masterdata ITIL',
                'proposed_slug' => 'sammendrag-masterdata-itil-ab12cd',
                'reason' => 'Companion summary.',
            ],
            'concept_candidates' => array_map(
                fn (int $i): array => [
                    'name' => "ITIL Process {$i}",
                    'concept_type' => 'process',
                    'independent_reason' => 'Named ITIL process distinct from the others.',
                    'mentioned_context' => "section {$i}",
                    'existing_page_title' => null,
                    'decision' => 'create',
                    'justification' => 'Central to the article; no existing page covers it.',
                    'owning_page_title' => null,
                    'necessary_for_article' => true,
                ],
                range(1, $count),
            ),
            'concept_pages' => array_map(
                fn (int $i): array => [
                    'action' => 'create',
                    'page_id' => null,
                    'title' => "ITIL Process {$i}",
                    'proposed_slug' => 'itil-process-'.$i,
                    'reason' => 'Concept page for this process.',
                ],
                range(1, $count),
            ),
            'entity_pages' => [],
            'no_action_reason' => null,
            'warnings' => [],
        ];
    }
}
