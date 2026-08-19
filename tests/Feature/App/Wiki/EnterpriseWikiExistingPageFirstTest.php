<?php

namespace Tests\Feature\App\Wiki;

use App\Exceptions\EnterpriseWikiMaintainerDecisionInconsistentException;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\EnterpriseWiki\EnterpriseWikiCanonicalOwnershipValidator;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionApplyService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionPrompt;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionService;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionSplitCoordinator;
use App\Services\EnterpriseWiki\EnterpriseWikiPatchCandidateService;
use App\Services\EnterpriseWiki\EnterpriseWikiPlanningContext;
use App\Services\OpenAi\OpenAiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * EXISTING PAGE FIRST — the invariant these three changes exist to make real:
 *
 *   new information -> weigh the relevant existing Wiki pages -> update/reuse the page that owns the
 *   topic -> create a new canonical page only when the topic genuinely has no home.
 *
 * Three mechanisms, tested here as one contract:
 *
 *  J1  The split planning flow — the path production takes for any document large enough to need
 *      batches — now receives the same bounded existing-page candidates the single-call path always
 *      had: at most EnterpriseWikiPatchCandidateService::MAX_CANDIDATES pages, with their REAL
 *      current content. Until now it decided create-vs-reuse from a 200-character index excerpt,
 *      which EnterpriseWikiPatchCandidateContextTest already proves cannot reveal what a page states.
 *  J2  A `create` must name the existing pages it weighed and say why none of them owns the topic —
 *      but only when pages were actually offered, so a genuinely new topic stays creatable.
 *  J3  The backend duplicate gate no longer needs an exact title match: the existing conservative
 *      EnterpriseWikiConceptIdentityMatcher catches "Incident Management" vs "ITIL Incident
 *      Management" without any synonym table.
 *
 * The budget guard is part of the contract, not a side note: J1 adds CONTEXT, never output budget.
 * test_the_candidate_block_never_raises_a_call_s_output_budget() is what holds that line.
 */
class EnterpriseWikiExistingPageFirstTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $capturedPayloads = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    // =========================================================================
    // A. The split flow actually receives the existing-page candidates
    // =========================================================================

    public function test_the_split_candidate_plan_call_sees_the_existing_pages_with_their_real_content(): void
    {
        $customer = $this->createCustomer();
        $this->createConceptPage($customer, 'Tilgangsstyring', 'tilgangsstyring', $this->longPageBody('Tilgang skal revideres hvert kvartal av systemeier.'));
        $document = $this->createDocument($customer, 'Tilgangsstyring skal nå revideres hver måned.');

        $this->runSplitDecision($document, ['Tilgangsstyring']);

        $candidatePlanPrompt = $this->userPromptOfCall(1);

        $this->assertStringContainsString('EXISTING PAGE CANDIDATES', $candidatePlanPrompt);
        $this->assertStringContainsString('Tilgangsstyring', $candidatePlanPrompt);
        $this->assertStringContainsString(
            'Tilgang skal revideres hvert kvartal av systemeier.',
            $candidatePlanPrompt,
            'the candidate block must carry the page\'s real current content, not an index excerpt',
        );
    }

    public function test_every_phase_two_batch_sees_the_existing_page_candidates(): void
    {
        $customer = $this->createCustomer();
        $this->createConceptPage($customer, 'Tilgangsstyring', 'tilgangsstyring', $this->longPageBody('Tilgang skal revideres hvert kvartal av systemeier.'));
        $document = $this->createDocument($customer, 'Tilgangsstyring skal nå revideres hver måned.');

        config(['ai_capacity.operations.enterprise_wiki_maintainer_decision.batch.max_candidates_per_batch' => 1]);
        $this->runSplitDecision($document, ['Tilgangsstyring', 'Beredskapsplan']);

        $batchPrompts = [$this->userPromptOfCall(2), $this->userPromptOfCall(3)];

        foreach ($batchPrompts as $index => $prompt) {
            $this->assertStringContainsString('EXISTING PAGE CANDIDATES', $prompt, "batch {$index} lost the candidate block");
            $this->assertStringContainsString('Tilgang skal revideres hvert kvartal av systemeier.', $prompt);
        }
    }

    public function test_only_the_bounded_candidate_set_is_sent_never_the_whole_wiki(): void
    {
        $customer = $this->createCustomer();

        // Five pages the document names by title; the service caps the offer at three.
        foreach (['Alfa Prosess', 'Beta Prosess', 'Gamma Prosess', 'Delta Prosess', 'Epsilon Prosess'] as $title) {
            $this->createConceptPage($customer, $title, Str::slug($title), $this->longPageBody("Innhold for {$title}."));
        }

        $document = $this->createDocument($customer, 'Alfa Prosess, Beta Prosess, Gamma Prosess, Delta Prosess og Epsilon Prosess endres alle.');

        $this->runSplitDecision($document, ['Alfa Prosess']);

        $prompt = $this->userPromptOfCall(1);

        $this->assertMatchesRegularExpression(
            '/EXISTING PAGE CANDIDATES \((\d+) pages\)/',
            $prompt,
        );
        preg_match('/EXISTING PAGE CANDIDATES \((\d+) pages\)/', $prompt, $m);

        $this->assertLessThanOrEqual(
            EnterpriseWikiPatchCandidateService::MAX_CANDIDATES,
            (int) $m[1],
            'the split flow must reuse the existing bounded candidate mechanism, not send the Wiki',
        );
    }

    /**
     * THE budget guard. The candidate block is context to read, never output to produce, so the
     * planner's output estimate — and therefore every call's max_output_tokens — must be byte for
     * byte what it was before the block existed.
     */
    public function test_the_candidate_block_never_raises_a_call_s_output_budget(): void
    {
        $customer = $this->createCustomer();
        $this->createConceptPage($customer, 'Tilgangsstyring', 'tilgangsstyring', $this->longPageBody('Tilgang skal revideres hvert kvartal av systemeier.'));
        $document = $this->createDocument($customer, 'Tilgangsstyring skal nå revideres hver måned.');

        // Identical customer, document and Wiki index in both runs. The ONLY difference is whether
        // the candidate block is rendered, which is exactly the variable under test — comparing two
        // different customers would also compare two different Wiki indexes.
        $this->mock(EnterpriseWikiPatchCandidateService::class, static fn (MockInterface $mock) => $mock
            ->shouldReceive('findForDocument')->andReturn([]));
        $this->runSplitDecision($document, ['Tilgangsstyring']);
        $budgetsWithout = $this->capturedOutputBudgets();
        $this->assertStringNotContainsString('EXISTING PAGE CANDIDATES', $this->userPromptOfCall(1));

        $this->forgetInstance(EnterpriseWikiPatchCandidateService::class);
        $this->runSplitDecision($document, ['Tilgangsstyring']);
        $budgetsWith = $this->capturedOutputBudgets();

        $this->assertStringContainsString('EXISTING PAGE CANDIDATES', $this->userPromptOfCall(1), 'precondition: this run really did send candidates');
        $this->assertSame(
            $budgetsWithout,
            $budgetsWith,
            'adding existing-page context must not change max_output_tokens on any call',
        );
    }

    private function forgetInstance(string $abstract): void
    {
        $this->app->forgetInstance($abstract);
    }

    public function test_the_number_of_ai_calls_is_unchanged_by_the_candidate_block(): void
    {
        $customer = $this->createCustomer();
        $this->createConceptPage($customer, 'Tilgangsstyring', 'tilgangsstyring', $this->longPageBody('Tilgang skal revideres hvert kvartal.'));
        $document = $this->createDocument($customer, 'Tilgangsstyring skal nå revideres hver måned.');

        $this->runSplitDecision($document, ['Tilgangsstyring']);

        // 1A document plan + 1B candidate plan + one phase-2 batch. Exactly the pre-change shape.
        $this->assertCount(3, $this->capturedPayloads);
    }

    // =========================================================================
    // B / J3. Same concept under a differently qualified name
    // =========================================================================

    public function test_a_create_under_a_same_identity_title_is_refused(): void
    {
        $issues = $this->validator()->findIssues(
            $this->decision(['concept_pages' => [$this->conceptPageEntry('ITIL Incident Management')]]),
            [['id' => 77, 'title' => 'Incident Management']],
        );

        $this->assertNotEmpty($issues);
        $this->assertStringContainsString('[77]', implode(' ', $issues));
        $this->assertStringContainsString('same concept under a differently qualified name', implode(' ', $issues));
    }

    public function test_a_genuinely_different_concept_is_still_creatable(): void
    {
        $issues = $this->validator()->findIssues(
            $this->decision(['concept_pages' => [$this->conceptPageEntry('Problem Management')]]),
            [['id' => 77, 'title' => 'Incident Management']],
        );

        $this->assertSame([], $issues, 'disjoint core tokens are two concepts, not one');
    }

    public function test_a_single_token_existing_title_does_not_swallow_a_qualified_new_page(): void
    {
        $issues = $this->validator()->findIssues(
            $this->decision(['concept_pages' => [$this->conceptPageEntry('Risikostyring i prosjekter')]]),
            [['id' => 88, 'title' => 'Risikostyring']],
        );

        $this->assertSame([], $issues, 'a lone generic word must never block a specialised page');
    }

    public function test_the_exact_title_rule_still_fires_and_names_the_existing_page(): void
    {
        $issues = $this->validator()->findIssues(
            $this->decision(['entity_pages' => [$this->conceptPageEntry('Leverandør AS')]]),
            [['id' => 99, 'title' => 'leverandør  as']],
        );

        $this->assertNotEmpty($issues);
        $this->assertStringContainsString('already carries that title', implode(' ', $issues));
    }

    // =========================================================================
    // C / J2. A create must show which existing pages it weighed
    // =========================================================================

    public function test_create_must_name_the_existing_pages_it_was_shown(): void
    {
        $issues = $this->validator()->findIssues(
            $this->decision(['concept_candidates' => [$this->candidate()]]),
            [],
            [],
            [41, 42],
        );

        $this->assertNotEmpty($issues);
        $this->assertStringContainsString('without naming any of the existing pages it was shown', implode(' ', $issues));
        $this->assertStringContainsString('41, 42', implode(' ', $issues));
    }

    public function test_create_must_say_why_the_weighed_pages_were_rejected(): void
    {
        $issues = $this->validator()->findIssues(
            $this->decision(['concept_candidates' => [$this->candidate([
                'considered_existing_page_ids' => [41],
                'considered_rejection_reason' => '   ',
            ])]]),
            [],
            [],
            [41, 42],
        );

        $this->assertNotEmpty($issues);
        $this->assertStringContainsString('gives no reason for rejecting them', implode(' ', $issues));
    }

    public function test_naming_pages_outside_the_offered_set_does_not_satisfy_the_requirement(): void
    {
        $issues = $this->validator()->findIssues(
            $this->decision(['concept_candidates' => [$this->candidate([
                'considered_existing_page_ids' => [9999],
                'considered_rejection_reason' => 'Ingen av dem eier dette temaet.',
            ])]]),
            [],
            [],
            [41, 42],
        );

        $this->assertNotEmpty($issues, 'a create cannot be waved through by citing pages it was never shown');
    }

    public function test_a_documented_create_passes(): void
    {
        $issues = $this->validator()->findIssues(
            $this->decision(['concept_candidates' => [$this->candidate([
                'considered_existing_page_ids' => [41, 42],
                'considered_rejection_reason' => 'Begge beskriver drift, ingen av dem eier dette temaet.',
            ])]]),
            [],
            [],
            [41, 42],
        );

        $this->assertSame([], $issues);
    }

    // =========================================================================
    // D. A genuinely new topic must stay creatable
    // =========================================================================

    public function test_a_create_is_unaffected_when_no_existing_pages_were_offered(): void
    {
        $issues = $this->validator()->findIssues(
            $this->decision(['concept_candidates' => [$this->candidate()]]),
            [],
            [],
            [],
        );

        $this->assertSame([], $issues, 'an empty consideration list is the truthful answer when nothing was offered');
    }

    public function test_a_decision_predating_the_contract_is_never_retroactively_flagged(): void
    {
        $legacy = $this->candidate();
        unset($legacy['considered_existing_page_ids'], $legacy['considered_rejection_reason']);

        $issues = $this->validator()->findIssues(
            $this->decision(['concept_candidates' => [$legacy]]),
            [],
            [],
            [41],
        );

        $this->assertSame([], $issues);
    }

    // =========================================================================
    // E. Known topic patched, new topic created — in one decision
    // =========================================================================

    public function test_a_known_topic_is_patched_while_a_new_topic_is_created_in_the_same_decision(): void
    {
        $decision = $this->decision([
            'concept_candidates' => [
                $this->candidate([
                    'name' => 'Kjent Tema',
                    'decision' => 'reference_only',
                    'relationship' => 'topic_extended',
                    'existing_owner_page_id' => 41,
                    'owning_page_title' => 'Kjent Tema',
                    'considered_existing_page_ids' => [],
                    'considered_rejection_reason' => null,
                ]),
                $this->candidate([
                    'name' => 'Nytt Tema',
                    'considered_existing_page_ids' => [41],
                    'considered_rejection_reason' => 'Siden dekker drift, ikke dette temaet.',
                ]),
            ],
            'concept_pages' => [$this->conceptPageEntry('Nytt Tema')],
            'patch_targets' => [$this->patchTarget(41, 'Kjent Tema')],
        ]);

        $issues = $this->validator()->findIssues($decision, [['id' => 41, 'title' => 'Kjent Tema']], ['el-1'], [41]);

        $this->assertSame([], $issues, 'extending X and creating Y must remain possible in one decision');
    }

    /**
     * G. The synonym case, expressed without a synonym table: the CONTRACT can point a candidate at
     * an existing page whose title shares no token with it. Nothing deterministic could match these
     * two names; what makes it possible is that the planner now sees that page's real content (J1)
     * and can name its id.
     */
    public function test_the_contract_can_choose_an_existing_page_whose_title_does_not_match(): void
    {
        $decision = $this->decision([
            'concept_candidates' => [$this->candidate([
                'name' => 'Brukertilgang',
                'decision' => 'reference_only',
                'relationship' => 'topic_extended',
                'existing_owner_page_id' => 41,
                'owning_page_title' => 'Tilgangsstyring',
                'considered_existing_page_ids' => [],
                'considered_rejection_reason' => null,
            ])],
            'patch_targets' => [$this->patchTarget(41, 'Brukertilgang')],
        ]);

        $issues = $this->validator()->findIssues($decision, [['id' => 41, 'title' => 'Tilgangsstyring']], ['el-1'], [41]);

        $this->assertSame([], $issues);
    }

    // =========================================================================
    // F. Two different documents, one Wiki — no parallel page
    // =========================================================================

    public function test_a_second_document_does_not_create_a_parallel_page_for_a_topic_the_wiki_already_owns(): void
    {
        $customer = $this->createCustomer();
        $existing = $this->createConceptPage($customer, 'Incident Management', 'incident-management', $this->longPageBody('Hendelser håndteres innen fire timer.'));
        $secondDocument = $this->createDocument($customer, 'ITIL Incident Management: hendelser håndteres nå innen to timer.');

        // The second document's planner proposes a parallel page under a qualified name.
        $parallel = $this->decision(['concept_pages' => [$this->conceptPageEntry('ITIL Incident Management')]]);

        // The bounded repair is given the offending object and returns nothing for it.
        /** @var EnterpriseWikiMaintainerDecisionAiClient&MockInterface $aiClient */
        $aiClient = $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class);
        $aiClient->shouldReceive('maxObjectsPerRepairCall')->andReturn(8);
        $aiClient->shouldReceive('repairGroupFitsOneCall')->andReturn(true);
        $aiClient->shouldReceive('repairGroup')->andReturn(['operations' => [], 'notes' => null]);

        $repaired = app(EnterpriseWikiMaintainerDecisionService::class)->validateAndRepairForDocument(
            $customer->id,
            $secondDocument,
            'no',
            $parallel,
            null,
            EnterpriseWikiPlanningContext::forDocument($customer->id, $secondDocument),
        );

        $this->assertSame(
            [],
            array_filter($repaired['concept_pages'], static fn (array $entry): bool => ($entry['action'] ?? '') === 'create'),
            'no decision that survives validation may create a second owner for a topic the Wiki already has',
        );

        $run = $this->createDecisionOnlyRun($customer, $repaired);
        app(EnterpriseWikiMaintainerDecisionApplyService::class)->apply($run);

        $conceptPages = EnterpriseWikiPage::query()
            ->where('customer_id', $customer->id)
            ->where('page_type', EnterpriseWikiPage::PAGE_TYPE_CONCEPT)
            ->get();

        $this->assertCount(1, $conceptPages, 'the second document must not add a parallel concept page');
        $this->assertSame($existing->id, $conceptPages->first()->id);
    }

    /**
     * Fail-closed at the level that matters: an undocumented `create` never survives the pipeline.
     * The bounded repair is shown the offending candidate; whatever it returns, a decision that
     * still creates a canonical page without having weighed the pages it was offered is never handed
     * on. No silent fallback to create — and none to reuse either, since nothing is invented on the
     * candidate's behalf.
     *
     * (The pipeline's hard EnterpriseWikiMaintainerDecisionInconsistentException path is exercised by
     * EnterpriseWikiMaintainerDecisionConceptPageStabilityIntegrationTest; this asserts the outcome
     * the product depends on rather than the mechanism that produced it.)
     */
    public function test_an_undocumented_create_never_survives_the_pipeline(): void
    {
        $customer = $this->createCustomer();
        $this->createConceptPage($customer, 'Driftsrutiner', 'driftsrutiner', $this->longPageBody('Drift følges opp ukentlig.'));
        $document = $this->createDocument($customer, 'Driftsrutiner endres. Et helt nytt tema innføres også.');

        $undocumented = $this->decision([
            'concept_candidates' => [$this->candidate()],
            'concept_pages' => [$this->conceptPageEntry('Nytt Tema')],
        ]);

        /** @var EnterpriseWikiMaintainerDecisionAiClient&MockInterface $aiClient */
        $aiClient = $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class);
        $aiClient->shouldReceive('maxObjectsPerRepairCall')->andReturn(8);
        $aiClient->shouldReceive('repairGroupFitsOneCall')->andReturn(true);
        $aiClient->shouldReceive('repairGroup')->andReturn(['operations' => [], 'notes' => null]);

        $planning = EnterpriseWikiPlanningContext::forDocument($customer->id, $document);
        $this->assertNotSame([], $planning->existingPageCandidates(), 'precondition: the planner really was offered a page');

        $pagesBefore = EnterpriseWikiPage::query()->where('customer_id', $customer->id)->count();

        try {
            $repaired = app(EnterpriseWikiMaintainerDecisionService::class)->validateAndRepairForDocument(
                $customer->id,
                $document,
                'no',
                $undocumented,
                null,
                $planning,
            );
        } catch (EnterpriseWikiMaintainerDecisionInconsistentException $e) {
            // Equally acceptable: refused outright.
            $this->assertStringContainsString('without naming any of the existing pages it was shown', implode(' ', $e->issues));
            $this->assertSame($pagesBefore, EnterpriseWikiPage::query()->where('customer_id', $customer->id)->count());

            return;
        }

        $survivingCreates = array_filter(
            $repaired['concept_candidates'],
            static fn (array $candidate): bool => ($candidate['decision'] ?? '') === 'create'
                && ($candidate['considered_existing_page_ids'] ?? []) === [],
        );

        $this->assertSame([], $survivingCreates, 'an undocumented create must never reach apply');
    }

    public function test_the_repaired_decision_reuses_the_existing_page_instead_of_creating_one(): void
    {
        $customer = $this->createCustomer();
        $existing = $this->createConceptPage($customer, 'Incident Management', 'incident-management', $this->longPageBody('Hendelser håndteres innen fire timer.'));
        $pagesBefore = EnterpriseWikiPage::query()->count();

        // What a correct planner (or the bounded repair) produces instead: reuse by page_id.
        $reuse = $this->decision(['concept_pages' => [array_merge($this->conceptPageEntry('Incident Management'), [
            'action' => 'update',
            'page_id' => $existing->id,
            'proposed_slug' => 'incident-management',
        ])]]);

        $run = $this->createDecisionOnlyRun($customer, $reuse);
        app(EnterpriseWikiMaintainerDecisionApplyService::class)->apply($run);

        $conceptPages = EnterpriseWikiPage::query()
            ->where('customer_id', $customer->id)
            ->where('page_type', EnterpriseWikiPage::PAGE_TYPE_CONCEPT)
            ->get();

        $this->assertCount(1, $conceptPages, 'a second document must not add a parallel canonical page');
        $this->assertSame($existing->id, $conceptPages->first()->id);
        // The document's own article/summary are still created — that model is deliberate and
        // untouched here, which is why the count above is scoped to canonical concept pages.
        $this->assertSame($pagesBefore + 2, EnterpriseWikiPage::query()->where('customer_id', $customer->id)->count());
    }

    // =========================================================================
    // Guards: what this change must NOT have touched
    // =========================================================================

    public function test_the_source_article_and_summary_contract_is_unchanged(): void
    {
        $schema = EnterpriseWikiMaintainerDecisionPrompt::jsonSchema()['json_schema']['schema']['properties'];

        foreach (['source_article', 'source_summary'] as $slot) {
            $this->assertArrayHasKey($slot, $schema);
            $this->assertArrayNotHasKey('considered_existing_page_ids', $schema[$slot]['properties']);
            $this->assertArrayNotHasKey('page_id', $schema[$slot]['properties'], 'document pages still carry no page_id');
        }
    }

    public function test_the_patch_contract_is_unchanged(): void
    {
        $this->assertSame(['replace', 'amend', 'preserve'], EnterpriseWikiMaintainerDecisionPrompt::PATCH_OPERATIONS);
        $this->assertSame([
            'substance_changed', 'topic_extended', 'topic_specialized', 'reference_only', 'independent_new_topic',
        ], EnterpriseWikiMaintainerDecisionPrompt::TOPIC_RELATIONSHIPS);
        $this->assertSame(['create', 'update'], EnterpriseWikiMaintainerDecisionPrompt::ACTIONS);
    }

    public function test_the_candidate_caps_are_unchanged(): void
    {
        $this->assertSame(3, EnterpriseWikiPatchCandidateService::MAX_CANDIDATES);
        $this->assertSame(6000, EnterpriseWikiPatchCandidateService::MAX_CONTENT_CHARS);
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function validator(): EnterpriseWikiCanonicalOwnershipValidator
    {
        return app(EnterpriseWikiCanonicalOwnershipValidator::class);
    }

    /** Runs the REAL split coordinator with only OpenAiClient faked, capturing every payload. */
    private function runSplitDecision(EnterpriseWikiDocument $document, array $mentionNames): void
    {
        $responses = [
            $this->completedResponse($this->documentPlanResponse()),
            $this->completedResponse([
                'entity_pages' => [],
                'concept_candidate_mentions' => array_map(
                    static fn (string $name): array => ['name' => $name, 'concept_type' => 'process', 'mentioned_context' => 'seksjon 2'],
                    $mentionNames,
                ),
                'warnings' => [],
            ]),
        ];

        $batchSize = (int) config('ai_capacity.operations.enterprise_wiki_maintainer_decision.batch.max_candidates_per_batch', 6);

        foreach (array_chunk($mentionNames, max(1, $batchSize)) as $chunk) {
            $responses[] = $this->completedResponse([
                'concept_candidates' => array_map(fn (string $name): array => $this->candidate([
                    'name' => $name,
                    'decision' => 'reference_only',
                    'relationship' => 'reference_only',
                    'owning_page_title' => 'Tilgangsstyring',
                    'considered_existing_page_ids' => [],
                    'considered_rejection_reason' => null,
                ]), $chunk),
                'concept_pages' => [],
            ]);
        }

        $this->mockOpenAi($responses);

        app(EnterpriseWikiMaintainerDecisionSplitCoordinator::class)->decide(
            EnterpriseWikiPlanningContext::forDocument((int) $document->customer_id, $document),
            'no',
        );
    }

    private function mockOpenAi(array $responses): void
    {
        $this->capturedPayloads = [];

        /** @var OpenAiClient&MockInterface $mock */
        $mock = $this->mock(OpenAiClient::class);
        $index = 0;

        $mock->shouldReceive('createResponse')->andReturnUsing(function (array $payload) use ($responses, &$index): array {
            $this->capturedPayloads[] = $payload;

            return $responses[$index++] ?? $responses[array_key_last($responses)];
        });
    }

    private function userPromptOfCall(int $index): string
    {
        $this->assertArrayHasKey($index, $this->capturedPayloads, "no AI call at index {$index}");

        foreach ($this->capturedPayloads[$index]['input'] as $message) {
            if (($message['role'] ?? null) === 'user') {
                return (string) $message['content'][0]['text'];
            }
        }

        $this->fail("call {$index} carried no user message");
    }

    /** @return list<int> */
    private function capturedOutputBudgets(): array
    {
        return array_map(
            static fn (array $payload): int => (int) ($payload['max_output_tokens'] ?? 0),
            $this->capturedPayloads,
        );
    }

    private function completedResponse(array $body): array
    {
        return ['status' => 'completed', 'output_text' => json_encode($body)];
    }

    private function documentPlanResponse(): array
    {
        return [
            'source_article' => ['action' => 'create', 'title' => 'Endringsnotat', 'proposed_slug' => 'endringsnotat-ab1c2d', 'reason' => 'Nytt dokument.'],
            'source_summary' => ['action' => 'create', 'title' => 'Sammendrag: Endringsnotat', 'proposed_slug' => 'sammendrag-endringsnotat-ab1c2d', 'reason' => 'Følgeside.'],
            'no_action_reason' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function decision(array $overrides = []): array
    {
        return array_merge([
            'source_article' => $this->sourcePage(),
            'source_summary' => $this->sourcePage(['title' => 'Sammendrag: Endringsnotat', 'proposed_slug' => 'sammendrag-endringsnotat-ab1c2d']),
            'concept_candidates' => [],
            'concept_pages' => [],
            'entity_pages' => [],
            'patch_targets' => [],
            'no_action_reason' => null,
            'warnings' => [],
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function sourcePage(array $overrides = []): array
    {
        return array_merge([
            'action' => 'create',
            'title' => 'Endringsnotat',
            'proposed_slug' => 'endringsnotat-ab1c2d',
            'reason' => 'Nytt kildedokument.',
            'owned_topics' => [['topic' => 'Hva dokumentet selv beslutter', 'source_element_keys' => ['el-1']]],
            'reference_only_topics' => [],
            'excluded_topics' => [],
            'related_page_guidance' => [],
            'planned_figures' => [],
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function candidate(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Nytt Tema',
            'concept_type' => 'praksis',
            'independent_reason' => 'Egen definert praksis.',
            'mentioned_context' => 'seksjon 4',
            'existing_page_title' => null,
            'decision' => 'create',
            'justification' => 'Kilden beskriver praksisen.',
            'owning_page_title' => null,
            'necessary_for_article' => true,
            'has_separate_source_evidence' => true,
            'has_reuse_value' => true,
            'relationship' => 'independent_new_topic',
            'existing_owner_page_id' => null,
            'considered_existing_page_ids' => [],
            'considered_rejection_reason' => null,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function conceptPageEntry(string $title): array
    {
        return [
            'action' => 'create',
            'page_id' => null,
            'title' => $title,
            'proposed_slug' => Str::slug($title),
            'reason' => 'Ny kanonisk side.',
            'owned_topics' => [['topic' => 'Kjerneinnhold', 'source_element_keys' => ['el-1']]],
            'reference_only_topics' => [],
            'excluded_topics' => [],
            'related_page_guidance' => [],
            'planned_figures' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function patchTarget(int $pageId, string $topic): array
    {
        return [
            'target_page_id' => $pageId,
            'target_page_title' => 'Kjent Tema',
            'target_page_type' => 'concept',
            'target_topic' => $topic,
            'target_heading' => null,
            'relationship' => 'topic_extended',
            'operation' => 'amend',
            'superseded_substance' => null,
            'replacement_substance' => 'Ny presisering fra dokumentet.',
            'source_element_keys' => ['el-1'],
            'preserve_topics' => [],
            'reason' => 'Dokumentet utdyper temaet.',
        ];
    }

    private function longPageBody(string $keySentence): string
    {
        // The key sentence sits well past the 200-character index excerpt, so a test that finds it
        // in a prompt has proved the candidate block — not the index — carried it.
        return "# Side\n\n".str_repeat('Innledende bakgrunnstekst som beskriver omfang og hensikt. ', 8)."\n\n## Regel\n\n{$keySentence}\n";
    }

    private function createCustomer(string $name = 'Eksisterende Side Først AS'): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function createDocument(Customer $customer, string $text): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'endringsnotat.docx',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.docx',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => $text,
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createConceptPage(Customer $customer, string $title, string $slug, string $markdown): EnterpriseWikiPage
    {
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => $slug,
            'title' => $title,
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
            'status' => EnterpriseWikiPage::STATUS_APPROVED,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => $markdown,
            'content_blocks_json' => [],
            'generated_by_model' => 'gpt-5',
        ]);

        return $page;
    }

    private function createDecisionOnlyRun(Customer $customer, array $decision): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $this->createDocument($customer, 'Kildetekst.')->id,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_PENDING,
            'maintainer_decision_generated_at' => now(),
            'maintainer_decision_json' => $decision,
        ]);
    }
}
