<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Exceptions\EnterpriseWikiMaintainerDecisionBatchFailedException;
use App\Services\EnterpriseWiki\EnterpriseWikiCanonicalOwnershipValidator;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionConsistencyValidator;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionHierarchyValidator;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionPrompt;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionSplitCoordinator;
use App\Services\OpenAi\OpenAiClient;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Integration-style tests for the split flow's orchestration (Phase A -> batch plan -> Phase B ->
 * Phase C merge) — real EnterpriseWikiAiCapacityPlanner/EnterpriseWikiAiCapacityRetryExecutor/
 * EnterpriseWikiMaintainerDecisionMerger, only OpenAiClient is mocked. No live API calls.
 */
class EnterpriseWikiMaintainerDecisionSplitCoordinatorTest extends TestCase
{
    private function coordinator(): EnterpriseWikiMaintainerDecisionSplitCoordinator
    {
        return app(EnterpriseWikiMaintainerDecisionSplitCoordinator::class);
    }

    // 7/8/9. Global plan + batches -> complete decision, every candidate exactly once.
    public function test_global_plan_plus_two_batches_produces_a_complete_merged_decision(): void
    {
        // 3 candidates, max 2 per batch => distributeEvenly(3, ceil(3/2)=2) = [2, 1].
        config(['ai_capacity.operations.enterprise_wiki_maintainer_decision.batch.max_candidates_per_batch' => 2]);

        $this->mockSequentialResponses([
            $this->completedResponse($this->globalPlan([
                $this->mention('Incident Management'),
                $this->mention('Problem Management'),
                $this->mention('Change Management'),
            ])),
            $this->completedResponse($this->batch([
                $this->candidate('Incident Management', 'create'),
                $this->candidate('Problem Management', 'create'),
            ], [
                $this->page('Incident Management'),
                $this->page('Problem Management'),
            ])),
            $this->completedResponse($this->batch([
                $this->candidate('Change Management', 'reference_only'),
            ], [])),
        ]);

        $decision = $this->coordinator()->decide(
            ['title' => 'Masterdata ITIL', 'filename' => 'Masterdata ITIL.docx'],
            str_repeat('ITIL prosessbeskrivelse. ', 50),
            [],
            'no',
        );

        $parsed = EnterpriseWikiMaintainerDecisionPrompt::parse($decision);

        $this->assertSame('Test Artikkel', $parsed['source_article']['title']);
        $this->assertCount(3, $parsed['concept_candidates']);
        $this->assertCount(2, $parsed['concept_pages']);

        $names = array_column($parsed['concept_candidates'], 'name');
        $this->assertSame(['Incident Management', 'Problem Management', 'Change Management'], $names);
    }

    public function test_global_plan_with_no_candidate_mentions_produces_an_empty_but_valid_decision(): void
    {
        $this->mockSequentialResponses([
            $this->completedResponse($this->globalPlan([])),
        ]);

        $decision = $this->coordinator()->decide(
            ['title' => 'T', 'filename' => 'T.docx'],
            'text',
            [],
            'no',
        );

        $parsed = EnterpriseWikiMaintainerDecisionPrompt::parse($decision);

        $this->assertSame([], $parsed['concept_candidates']);
        $this->assertSame([], $parsed['concept_pages']);
    }

    // 15. A single failing batch aborts the whole flow — no partial result.
    public function test_a_failing_batch_aborts_the_whole_decision(): void
    {
        $this->forceSingleCandidatePerBatch();

        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')
            ->once()
            ->andReturn($this->completedResponse($this->globalPlan([
                $this->mention('Incident Management'),
                $this->mention('Problem Management'),
            ])));
        // Batch 1 fails outright (malformed envelope); batch 2 must never be attempted.
        $mock->shouldReceive('createResponse')
            ->once()
            ->andReturn(['status' => 'failed', 'error' => ['type' => 'server_error', 'code' => 'boom']]);

        $this->expectException(EnterpriseWikiMaintainerDecisionBatchFailedException::class);

        $this->coordinator()->decide(['title' => 'T', 'filename' => 'T.docx'], 'text', [], 'no');
    }

    public function test_batch_failed_exception_carries_batch_metadata(): void
    {
        $this->forceSingleCandidatePerBatch();

        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')
            ->once()
            ->andReturn($this->completedResponse($this->globalPlan([$this->mention('Incident Management')])));
        $mock->shouldReceive('createResponse')
            ->once()
            ->andReturn(['status' => 'failed', 'error' => ['type' => 'server_error', 'code' => 'boom']]);

        try {
            $this->coordinator()->decide(['title' => 'T', 'filename' => 'T.docx'], 'text', [], 'no');
            $this->fail('Expected EnterpriseWikiMaintainerDecisionBatchFailedException.');
        } catch (EnterpriseWikiMaintainerDecisionBatchFailedException $e) {
            $this->assertSame(1, $e->batchNumber);
            $this->assertSame(1, $e->totalBatches);
            $this->assertSame(1, $e->candidateCount);
        }
    }

    // 16. Capacity retry works per batch (a batch that hits incomplete/max_output_tokens once
    // still succeeds via its own bounded retry).
    public function test_capacity_retry_works_within_a_single_batch(): void
    {
        $this->forceSingleCandidatePerBatch();

        $calls = 0;

        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')
            ->times(2)
            ->andReturnUsing(function () use (&$calls): array {
                $calls++;

                if ($calls === 1) {
                    return $this->completedResponse($this->globalPlan([$this->mention('Incident Management')]));
                }

                if ($calls === 2) {
                    return ['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens'], 'output_text' => '', 'output' => []];
                }

                return $this->completedResponse($this->batch([$this->candidate('Incident Management', 'create')], [$this->page('Incident Management')]));
            });

        // Third call needed for the batch's own retry — extend expectation count.
        $mock->shouldReceive('createResponse')->once()->andReturn(
            $this->completedResponse($this->batch([$this->candidate('Incident Management', 'create')], [$this->page('Incident Management')])),
        );

        $decision = $this->coordinator()->decide(['title' => 'T', 'filename' => 'T.docx'], 'text', [], 'no');
        $parsed = EnterpriseWikiMaintainerDecisionPrompt::parse($decision);

        $this->assertCount(1, $parsed['concept_candidates']);
    }

    // 17. Capacity retry exhaustion in a batch surfaces as a clear, batch-scoped exception.
    public function test_capacity_exhaustion_within_a_batch_surfaces_as_batch_failed_exception(): void
    {
        $this->forceSingleCandidatePerBatch();

        $incomplete = ['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens'], 'output_text' => '', 'output' => [], 'usage' => ['output_tokens' => 20]];

        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')
            ->once()
            ->andReturn($this->completedResponse($this->globalPlan([$this->mention('Incident Management')])));
        $mock->shouldReceive('createResponse')->twice()->andReturn($incomplete);

        $this->expectException(EnterpriseWikiMaintainerDecisionBatchFailedException::class);

        $this->coordinator()->decide(['title' => 'T', 'filename' => 'T.docx'], 'text', [], 'no');
    }

    // 22/23. A larger synthetic ITIL-like document with many candidates splits into several
    // batches and completes without any call exceeding the operation maximum.
    public function test_many_candidates_split_into_several_batches_and_complete(): void
    {
        config(['ai_capacity.operations.enterprise_wiki_maintainer_decision.batch.max_candidates_per_batch' => 3]);

        // Realistic, textually-distinct names — a numeric-suffix naming scheme (e.g. "Process 1",
        // "Process 2") would collide under EnterpriseWikiConceptIdentityMatcher, since a lone
        // trailing digit is filtered out as too short a token to distinguish identity.
        $processNames = [
            'Incident Management', 'Problem Management', 'Change Management', 'Configuration Management',
            'Release Management', 'Service Level Management', 'Availability Management', 'Capacity Management',
        ];
        $mentions = array_map(fn (string $name): array => $this->mention($name), $processNames);

        $capturedPayloads = [];

        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')
            ->andReturnUsing(function (array $payload) use (&$capturedPayloads, $mentions): array {
                $capturedPayloads[] = $payload;

                if (count($capturedPayloads) === 1) {
                    return $this->completedResponse($this->globalPlan($mentions));
                }

                // Decode which candidates this batch was asked to decide, from the captured
                // payload's user message, and answer for exactly those.
                $userText = (string) data_get($payload, 'input.1.content.0.text', '');
                preg_match_all('/"name":\s*"([^"]+)"/', explode('CANDIDATES TO DECIDE IN THIS BATCH', $userText)[1] ?? '', $matches);
                $names = $matches[1];

                $candidates = array_map(fn (string $name): array => $this->candidate($name, 'create'), $names);
                $pages = array_map(fn (string $name): array => $this->page($name), $names);

                return $this->completedResponse($this->batch($candidates, $pages));
            });

        $decision = $this->coordinator()->decide(
            ['title' => 'Masterdata ITIL', 'filename' => 'Masterdata ITIL.docx'],
            str_repeat('ITIL prosessbeskrivelse med mange rammeverk. ', 300),
            [],
            'no',
        );

        $parsed = EnterpriseWikiMaintainerDecisionPrompt::parse($decision);

        $this->assertCount(8, $parsed['concept_candidates']);
        $this->assertCount(8, $parsed['concept_pages']);

        // Global plan call + at least 2 batch calls (8 candidates, max 3 per batch => >=3 batches).
        $this->assertGreaterThanOrEqual(4, count($capturedPayloads));

        foreach ($capturedPayloads as $payload) {
            $this->assertLessThanOrEqual(9000, $payload['max_output_tokens']);
        }
    }

    // 20. Same split input run twice produces the same structural result (determinism).
    public function test_same_input_produces_the_same_structural_result_when_run_twice(): void
    {
        $buildResponses = fn () => [
            $this->completedResponse($this->globalPlan([$this->mention('Incident Management')])),
            $this->completedResponse($this->batch([$this->candidate('Incident Management', 'create')], [$this->page('Incident Management')])),
        ];

        $this->forceSingleCandidatePerBatch();
        $this->mockSequentialResponses($buildResponses());
        $first = $this->coordinator()->decide(['title' => 'T', 'filename' => 'T.docx'], 'text', [], 'no');

        $this->mockSequentialResponses($buildResponses());
        $second = $this->coordinator()->decide(['title' => 'T', 'filename' => 'T.docx'], 'text', [], 'no');

        $this->assertSame($first, $second);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function forceSingleCandidatePerBatch(): void
    {
        config(['ai_capacity.operations.enterprise_wiki_maintainer_decision.batch.max_candidates_per_batch' => 1]);
    }

    // =========================================================================
    // Run 51 — a split run against an EMPTY Wiki must produce a decision the
    // deterministic validators accept, without any repair pass at all.
    //
    // It did not: 15 of its 21 issues were candidates naming the source article as their owning
    // page (the batch prompt called it a valid owning page while the consistency validator
    // rejects it by design), and 4 were sub-topics of a page the same run was creating, which the
    // canonical-ownership rule had no legal way to express.
    // =========================================================================

    public function test_a_split_run_on_an_empty_wiki_needs_no_repair_for_same_run_ownership(): void
    {
        config(['ai_capacity.operations.enterprise_wiki_maintainer_decision.batch.max_candidates_per_batch' => 2]);

        $this->mockSequentialResponses([
            $this->completedResponse($this->globalPlan([
                $this->mention('Migreringsstrategi'),
                $this->mention('Cutover (Big Bang)'),
                $this->mention('Prosjektleder'),
            ])),
            // Batch 1 creates the owner page and specialises one candidate under it — the owner
            // exists only in THIS decision, so existing_owner_page_id is legitimately null.
            $this->completedResponse($this->batch([
                $this->candidate('Migreringsstrategi', 'create', ['relationship' => 'independent_new_topic']),
                $this->candidate('Cutover (Big Bang)', 'reference_only', [
                    'relationship' => 'topic_specialized',
                    'owning_page_title' => 'Migreringsstrategi',
                ]),
            ], [
                $this->page('Migreringsstrategi'),
            ])),
            // Batch 2: a local role no concept page owns. The correct answer is "exclude", never
            // pointing the reader at the source article.
            $this->completedResponse($this->batch([
                $this->candidate('Prosjektleder', 'exclude'),
            ], [])),
        ]);

        $decision = EnterpriseWikiMaintainerDecisionPrompt::parse($this->coordinator()->decide(
            ['title' => 'Masterdata Prosjekt', 'filename' => 'Masterdata Prosjekt.docx'],
            str_repeat('Prosjektdokumentasjon med migrering og testing. ', 60),
            [], // EMPTY Wiki — no existing page can ever be named as an owner.
            'no',
        ));

        $issues = array_merge(
            app(EnterpriseWikiMaintainerDecisionConsistencyValidator::class)->findIssues($decision, []),
            app(EnterpriseWikiMaintainerDecisionHierarchyValidator::class)->findIssues($decision),
            app(EnterpriseWikiCanonicalOwnershipValidator::class)->findIssues($decision, []),
        );

        $this->assertSame([], $issues, 'a sound split decision on an empty Wiki must not need a repair pass');
        $this->assertCount(3, $decision['concept_candidates']);
        $this->assertCount(1, $decision['concept_pages']);
    }

    /**
     * The prompt and the validator must state ONE policy about who may own a topic. Run 51's batch
     * prompt advertised the source article as a valid owning page; the validator has always
     * rejected it. Every candidate that believed the prompt became a validation issue.
     */
    public function test_batch_prompt_and_validator_agree_that_source_pages_are_not_owning_pages(): void
    {
        $capturedPrompts = [];

        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $mock->shouldReceive('createResponse')
            ->twice()
            ->andReturnUsing(function (array $payload) use (&$capturedPrompts): array {
                $capturedPrompts[] = implode("\n", [
                    $payload['input'][0]['content'][0]['text'],
                    $payload['input'][1]['content'][0]['text'],
                ]);

                return count($capturedPrompts) === 1
                    ? $this->completedResponse($this->globalPlan([$this->mention('Prosjektleder')]))
                    : $this->completedResponse($this->batch([$this->candidate('Prosjektleder', 'exclude')], []));
            });

        $this->coordinator()->decide(['title' => 'T', 'filename' => 'T.docx'], 'tekst', [], 'no');

        $batchPrompt = $capturedPrompts[1];

        $this->assertStringContainsString('NEVER valid as owning_page_title', $batchPrompt);
        $this->assertStringNotContainsString('they are valid owning pages', $batchPrompt);

        // The other half of the same policy: naming the source article as an owning page is an
        // issue, so a prompt that suggested it would be sending the model into a repair.
        $decision = EnterpriseWikiMaintainerDecisionPrompt::parse($this->globalPlan([]) + [
            'concept_candidates' => [$this->candidate('Prosjektleder', 'reference_only', ['owning_page_title' => 'Test Artikkel'])],
            'concept_pages' => [],
            'patch_targets' => [],
        ]);

        $this->assertNotEmpty(
            app(EnterpriseWikiMaintainerDecisionConsistencyValidator::class)->findIssues($decision, []),
            'the validator must still refuse the source article as an owning page',
        );
    }

    private function mockSequentialResponses(array $responses): void
    {
        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $expectation = $mock->shouldReceive('createResponse')->times(count($responses));

        $index = 0;
        $expectation->andReturnUsing(function () use ($responses, &$index): array {
            return $responses[$index++];
        });
    }

    private function completedResponse(array $decodedBody): array
    {
        return ['status' => 'completed', 'output_text' => json_encode($decodedBody)];
    }

    private function globalPlan(array $mentions): array
    {
        return [
            'source_article' => [
                'action' => 'create',
                'title' => 'Test Artikkel',
                'proposed_slug' => 'test-artikkel-ab1c2d',
                'reason' => 'New.',
            ],
            'source_summary' => [
                'action' => 'create',
                'title' => 'Sammendrag: Test Artikkel',
                'proposed_slug' => 'sammendrag-test-artikkel-ab1c2d',
                'reason' => 'Companion.',
            ],
            'entity_pages' => [],
            'concept_candidate_mentions' => $mentions,
            'no_action_reason' => null,
            'warnings' => [],
        ];
    }

    private function mention(string $name): array
    {
        return ['name' => $name, 'concept_type' => 'process', 'mentioned_context' => 'section 2'];
    }

    private function batch(array $candidates, array $pages): array
    {
        return ['concept_candidates' => $candidates, 'concept_pages' => $pages];
    }

    /** @param array<string, mixed> $overrides */
    private function candidate(string $name, string $decision, array $overrides = []): array
    {
        return array_merge([
            'name' => $name,
            'concept_type' => 'process',
            'independent_reason' => 'Independent concept.',
            'mentioned_context' => 'section 2',
            'existing_page_title' => null,
            'decision' => $decision,
            'justification' => 'Test justification.',
            'owning_page_title' => null,
            'necessary_for_article' => false,
        ], $overrides);
    }

    private function page(string $title): array
    {
        return [
            'action' => 'create',
            'page_id' => null,
            'title' => $title,
            'proposed_slug' => strtolower(str_replace(' ', '-', $title)),
            'reason' => 'Concept page.',
        ];
    }

    /**
     * A control byte in the model's own response text (run 34's fault) says nothing about the
     * decision and everything about the transmission. Failing the batch aborts every other call in
     * the run, so this one call is retried once — and only for that specific fault.
     */
    public function test_a_corrupted_batch_response_is_retried_once_and_then_succeeds(): void
    {
        $this->forceSingleCandidatePerBatch();

        $corrupted = $this->batch([$this->candidate("Incident\x0BManagement", 'reference_only')], []);

        $this->mockSequentialResponses([
            $this->completedResponse($this->globalPlan([$this->mention('Incident Management')])),
            $this->completedResponse($corrupted),
            $this->completedResponse($this->batch([$this->candidate('Incident Management', 'reference_only')], [])),
        ]);

        $decision = EnterpriseWikiMaintainerDecisionPrompt::parse(
            $this->coordinator()->decide(['title' => 'T', 'filename' => 'T.docx'], 'text', [], 'no')
        );

        $this->assertCount(1, $decision['concept_candidates']);
        $this->assertSame('Incident Management', $decision['concept_candidates'][0]['name']);
    }

    public function test_a_batch_that_stays_corrupted_still_fails_the_decision(): void
    {
        $this->forceSingleCandidatePerBatch();

        $corrupted = $this->batch([$this->candidate("Incident\x0BManagement", 'reference_only')], []);

        $this->mockSequentialResponses([
            $this->completedResponse($this->globalPlan([$this->mention('Incident Management')])),
            $this->completedResponse($corrupted),
            $this->completedResponse($corrupted),
        ]);

        $this->expectException(EnterpriseWikiMaintainerDecisionBatchFailedException::class);

        $this->coordinator()->decide(['title' => 'T', 'filename' => 'T.docx'], 'text', [], 'no');
    }

    public function test_an_ordinary_schema_violation_is_never_retried(): void
    {
        // A real contract violation would only be repeated by a retry — it must cost exactly one call.
        $this->forceSingleCandidatePerBatch();

        $this->mockSequentialResponses([
            $this->completedResponse($this->globalPlan([$this->mention('Incident Management')])),
            $this->completedResponse(['concept_candidates' => [['name' => 'Incident Management']], 'concept_pages' => []]),
        ]);

        $this->expectException(EnterpriseWikiMaintainerDecisionBatchFailedException::class);

        $this->coordinator()->decide(['title' => 'T', 'filename' => 'T.docx'], 'text', [], 'no');
    }
}
