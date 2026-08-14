<?php

namespace Tests\Feature\App\Wiki;

use App\Exceptions\EnterpriseWikiMaintainerDecisionDeltaRejectedException;
use App\Exceptions\EnterpriseWikiMaintainerDecisionInconsistentException;
use App\Models\Customer;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mockery\MockInterface;
use Tests\TestCase;

class EnterpriseWikiMaintainerDecisionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    public function test_service_returns_decision_from_ai_client(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $expected = $this->validDecision();

        $this->mockAiClient($expected);

        $result = $this->service()->runForDocument($customer->id, $document->id, 'no');

        $this->assertSame($expected['source_article']['title'], $result['source_article']['title']);
    }

    public function test_service_enforces_customer_scoping(): void
    {
        $owner = $this->createCustomer('Owner');
        $other = $this->createCustomer('Other');
        $document = $this->createDocument($owner);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not found for customer/');

        $this->service()->runForDocument($other->id, $document->id);
    }

    public function test_service_document_not_found_throws(): void
    {
        $customer = $this->createCustomer();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not found for customer/');

        $this->service()->runForDocument($customer->id, 99999);
    }

    public function test_service_strips_extension_from_document_filename_for_source_meta(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, ['original_filename' => 'Masterdata Prosjekt.docx']);

        $captured = [];
        $this->mockAiClientCapturing($captured);

        $this->service()->runForDocument($customer->id, $document->id, 'no');

        $this->assertSame('Masterdata Prosjekt', $captured['sourceMeta']['title']);
        $this->assertSame('Masterdata Prosjekt.docx', $captured['sourceMeta']['filename']);
    }

    public function test_service_passes_extracted_text_to_ai_client(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, ['extracted_text' => 'Spesiell kildetekst.']);

        $captured = [];
        $this->mockAiClientCapturing($captured);

        $this->service()->runForDocument($customer->id, $document->id, 'no');

        $this->assertSame('Spesiell kildetekst.', $captured['sourceText']);
    }

    public function test_service_passes_empty_string_when_extracted_text_is_null(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, ['extracted_text' => null]);

        $captured = [];
        $this->mockAiClientCapturing($captured);

        $this->service()->runForDocument($customer->id, $document->id, 'no');

        $this->assertSame('', $captured['sourceText']);
    }

    public function test_service_includes_existing_wiki_pages_in_index_context(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'bestaende-side',
            'title' => 'Bestående Side',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
            'status' => EnterpriseWikiPage::STATUS_APPROVED,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
        ]);

        $captured = [];
        $this->mockAiClientCapturing($captured);

        $this->service()->runForDocument($customer->id, $document->id, 'no');

        $titles = array_column($captured['indexContext'], 'title');
        $this->assertContains('Bestående Side', $titles);
    }

    public function test_service_index_context_is_empty_when_no_pages_exist(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        $captured = [];
        $this->mockAiClientCapturing($captured);

        $this->service()->runForDocument($customer->id, $document->id, 'no');

        $this->assertSame([], $captured['indexContext']);
    }

    public function test_service_index_context_excludes_pages_from_other_customers(): void
    {
        $customer = $this->createCustomer('Mine');
        $other = $this->createCustomer('Theirs');
        $document = $this->createDocument($customer);

        EnterpriseWikiPage::query()->create([
            'customer_id' => $other->id,
            'slug' => 'their-page',
            'title' => 'Other Customer Page',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
        ]);

        $captured = [];
        $this->mockAiClientCapturing($captured);

        $this->service()->runForDocument($customer->id, $document->id, 'no');

        $titles = array_column($captured['indexContext'], 'title');
        $this->assertNotContains('Other Customer Page', $titles);
    }

    public function test_service_passes_language_code_to_ai_client(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        $captured = [];
        $this->mockAiClientCapturing($captured);

        $this->service()->runForDocument($customer->id, $document->id, 'en');

        $this->assertSame('en', $captured['languageCode']);
    }

    public function test_service_does_not_write_to_database(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $this->mockAiClient($this->validDecision());

        $pagesBefore = EnterpriseWikiPage::query()->count();
        $this->service()->runForDocument($customer->id, $document->id, 'no');
        $pagesAfter = EnterpriseWikiPage::query()->count();

        $this->assertSame($pagesBefore, $pagesAfter);
    }

    // =========================================================================
    // Consistency validation + bounded repair pass (Wiki run-581 fix: "ITIL Incident
    // Management" concept page never proposed even though the article/summary pointed the
    // reader onward to it).
    // =========================================================================

    public function test_consistent_decision_never_triggers_a_repair_call(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        /** @var EnterpriseWikiMaintainerDecisionAiClient&MockInterface $mock */
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class);
        $mock->shouldReceive('decide')->once()->andReturn($this->validDecision());
        $mock->shouldNotReceive('repairGroup');

        $this->service()->runForDocument($customer->id, $document->id, 'no');
    }

    public function test_run_692_source_article_guidance_shape_is_valid_without_repair(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, ['original_filename' => 'Masterdata ITIL.docx']);

        $decision = $this->validDecision();
        $decision['source_article']['title'] = 'Masterdata ITIL';
        $decision['source_article']['proposed_slug'] = 'masterdata-itil-ab1c2d';
        $decision['source_summary']['title'] = 'Sammendrag: Masterdata ITIL';
        $decision['source_summary']['proposed_slug'] = 'sammendrag-masterdata-itil-ab1c2d';
        $decision['source_summary']['related_page_guidance'] = [
            ['page_title' => 'Masterdata ITIL', 'relationship' => 'Point readers to the article for detail.'],
        ];
        $decision['concept_pages'] = [[
            'action' => 'create',
            'page_id' => null,
            'title' => 'ITIL',
            'proposed_slug' => 'itil',
            'reason' => 'Central framework in the source.',
            'owned_topics' => [['topic' => 'ITIL som rammeverk', 'source_element_keys' => ['paragraph-0']]],
            'related_page_guidance' => [
                ['page_title' => 'Masterdata ITIL', 'relationship' => 'Link to the article for source-specific application.'],
            ],
        ]];

        /** @var EnterpriseWikiMaintainerDecisionAiClient&MockInterface $mock */
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class);
        $mock->shouldReceive('decide')->once()->andReturn($decision);
        $mock->shouldNotReceive('repairGroup');

        $result = $this->service()->runForDocument($customer->id, $document->id, 'no');

        $this->assertSame($decision, $result);
    }

    public function test_structural_related_page_guidance_title_drift_is_normalized_without_repair(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, ['original_filename' => 'Masterdata ITIL.docx']);

        $decision = $this->validDecision();
        $decision['source_article']['title'] = 'Masterdata ITIL';
        $decision['source_article']['proposed_slug'] = 'masterdata-itil-ab1c2d';
        $decision['source_summary']['title'] = 'Sammendrag: Masterdata ITIL';
        $decision['source_summary']['proposed_slug'] = 'sammendrag-masterdata-itil-ab1c2d';
        $decision['source_summary']['related_page_guidance'] = [
            ['page_title' => 'masterdata-itil.docx', 'relationship' => 'Point readers to the article for detail.'],
        ];

        /** @var EnterpriseWikiMaintainerDecisionAiClient&MockInterface $mock */
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class);
        $mock->shouldReceive('decide')->once()->andReturn($decision);
        $mock->shouldNotReceive('repairGroup');

        $result = $this->service()->runForDocument($customer->id, $document->id, 'no');

        $this->assertSame('Masterdata ITIL', $result['source_summary']['related_page_guidance'][0]['page_title']);
        $this->assertSame(
            $decision['source_summary']['related_page_guidance'][0]['relationship'],
            $result['source_summary']['related_page_guidance'][0]['relationship'],
        );
        $this->assertSame($decision['source_article'], $result['source_article']);
        $this->assertSame($decision['concept_pages'], $result['concept_pages']);
    }

    public function test_inconsistent_decision_triggers_one_bounded_repair_pass(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        $inconsistent = $this->validDecision();
        $inconsistent['source_article']['related_page_guidance'] = [
            ['page_title' => 'ITIL Incident Management', 'relationship' => 'See the concept page.'],
        ];

        /** @var EnterpriseWikiMaintainerDecisionAiClient&MockInterface $mock */
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class);
        $mock->shouldReceive('decide')->once()->andReturn($inconsistent);
        $this->allowRepairPacking($mock);
        $mock->shouldReceive('repairGroup')
            ->once()
            ->withArgs(function (array $sourceMeta, string $sourceText, array $indexContext, string $languageCode, array $decision, array $group) use ($inconsistent): bool {
                return $decision === $inconsistent
                    && $group['object_ids'] === ['source_article']
                    && str_contains(implode(' ', $group['issues']), 'ITIL Incident Management');
            })
            ->andReturn($this->delta([[
                'collection' => 'concept_pages',
                'object_id' => null,
                'operation' => 'add',
                'object' => $this->conceptPageObject('ITIL Incident Management'),
            ]]));

        $result = $this->service()->runForDocument($customer->id, $document->id, 'no');

        $this->assertCount(1, $result['concept_pages']);
        $this->assertSame('ITIL Incident Management', $result['concept_pages'][0]['title']);
        $this->assertSame(
            $inconsistent['source_article'],
            $result['source_article'],
            'an object the delta does not name must survive the repair byte for byte',
        );
    }

    /**
     * Run 55: the decision named an existing ENTITY page through the concept slot. Nothing checked
     * it until apply refused to retype the page — mid-run, after the decision had been validated,
     * repaired and persisted. The mismatch is knowable the moment the decision exists, and it is
     * repairable: drop the wrong-slot claim, keep the one that matches the page's real type.
     */
    public function test_an_existing_page_in_the_wrong_typed_slot_is_repaired_at_decision_time(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $entity = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'et-selskap',
            'title' => 'Et Selskap',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ENTITY,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
        ]);

        $mismatched = $this->validDecision();
        $wrongSlotEntry = array_merge($this->conceptPageObject('Et Selskap'), [
            'action' => 'update',
            'page_id' => $entity->id,
        ]);
        $mismatched['concept_pages'] = [$wrongSlotEntry];
        $mismatched['entity_pages'] = [array_merge($this->conceptPageObject('Et Selskap'), [
            'action' => 'update',
            'page_id' => $entity->id,
        ])];

        /** @var EnterpriseWikiMaintainerDecisionAiClient&MockInterface $mock */
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class);
        $mock->shouldReceive('decide')->once()->andReturn($mismatched);
        $this->allowRepairPacking($mock);

        $captured = [];

        $mock->shouldReceive('repairGroup')
            ->once()
            ->andReturnUsing(function (...$args) use (&$captured): array {
                $captured = $args[5];

                // The correct resolution: the entity page keeps its own slot, the concept claim goes.
                return $this->delta([[
                    'collection' => 'concept_pages',
                    'object_id' => 'concept_pages[0]',
                    'operation' => 'remove',
                    'object' => null,
                ]]);
            });

        $result = $this->service()->runForDocument($customer->id, $document->id, 'no');

        $this->assertSame(['concept_pages[0]'], $captured['object_ids']);
        $this->assertStringContainsString('through a [concept] slot', implode(' ', $captured['issues']));
        $this->assertSame([], $result['concept_pages']);
        $this->assertCount(1, $result['entity_pages'], 'the correctly slotted claim survives');
        $this->assertSame($entity->id, $result['entity_pages'][0]['page_id']);
    }

    /**
     * Run 53's cost lesson: an owned topic no source element supports must be caught while the
     * decision is still being validated — a few hundred repair tokens — instead of one page-
     * generation call per unsupported section, five failed pages and a failed run.
     */
    public function test_an_owned_topic_without_evidence_is_repaired_at_decision_time(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer, ['extracted_text' => 'Kildetekst med innhold.']);

        $ungrounded = $this->validDecision();
        $ungrounded['concept_pages'] = [array_merge($this->conceptPageObject('Et Konsept'), [
            'owned_topics' => [
                ['topic' => 'Godt underbygget tema', 'source_element_keys' => ['manual-0']],
                ['topic' => 'Plausibel men ikke dekket', 'source_element_keys' => []],
            ],
        ])];

        /** @var EnterpriseWikiMaintainerDecisionAiClient&MockInterface $mock */
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class);
        $mock->shouldReceive('decide')->once()->andReturn($ungrounded);
        $this->allowRepairPacking($mock);

        $captured = [];

        $mock->shouldReceive('repairGroup')
            ->once()
            ->andReturnUsing(function (...$args) use (&$captured, $ungrounded): array {
                $captured = $args[5];
                $page = $ungrounded['concept_pages'][0];
                // The correct resolution: stop owning what the document does not cover.
                $page['owned_topics'] = [$page['owned_topics'][0]];

                return $this->delta([[
                    'collection' => 'concept_pages',
                    'object_id' => 'concept_pages[0]',
                    'operation' => 'replace',
                    'object' => $page,
                ]]);
            });

        $result = $this->service()->runForDocument($customer->id, $document->id, 'no');

        $this->assertSame(['concept_pages[0]'], $captured['object_ids']);
        $this->assertStringContainsString('Plausibel men ikke dekket', implode(' ', $captured['issues']));
        $this->assertStringContainsString('without naming any source element', implode(' ', $captured['issues']));
        $this->assertCount(1, $result['concept_pages'][0]['owned_topics']);
        $this->assertSame('Godt underbygget tema', $result['concept_pages'][0]['owned_topics'][0]['topic']);
    }

    /**
     * A correct fix inside one group can leave an object OUTSIDE that group dangling — observed in
     * the first bounded runtime verification, where demoting a concept left two already-validated
     * pages pointing at a page that no longer existed. The merge refuses to touch those objects, so
     * the follow-up must be its own attributed repair in a second bounded round.
     */
    public function test_a_fault_introduced_outside_the_repaired_group_is_fixed_in_a_second_round(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        $decision = $this->validDecision();
        $decision['concept_candidates'] = [$this->candidate('Teststrategi', ['decision' => 'reference_only'])];
        $decision['concept_pages'] = [
            $this->conceptPageObject('Teststrategi'),
            array_merge($this->conceptPageObject('Endringshåndtering'), [
                'related_page_guidance' => [['page_title' => 'Teststrategi', 'relationship' => 'Se testsiden.']],
            ]),
        ];

        /** @var EnterpriseWikiMaintainerDecisionAiClient&MockInterface $mock */
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class);
        $mock->shouldReceive('decide')->once()->andReturn($decision);
        $this->allowRepairPacking($mock);

        $rounds = [];

        $mock->shouldReceive('repairGroup')
            ->twice()
            ->andReturnUsing(function (...$args) use (&$rounds, $decision): array {
                $group = $args[5];
                $rounds[] = $group['object_ids'];

                // Round 1: demote the candidate and drop its page — correct for the reported issue,
                // but it leaves "Endringshåndtering" pointing at a page that is now gone.
                if (count($rounds) === 1) {
                    return $this->delta([
                        [
                            'collection' => 'concept_candidates',
                            'object_id' => 'concept_candidates[0]',
                            'operation' => 'replace',
                            'object' => array_merge($decision['concept_candidates'][0], ['decision' => 'exclude']),
                        ],
                        [
                            'collection' => 'concept_pages',
                            'object_id' => 'concept_pages[0]',
                            'operation' => 'remove',
                            'object' => null,
                        ],
                    ]);
                }

                // Round 2: the now-dangling page is its own repair group.
                return $this->delta([[
                    'collection' => 'concept_pages',
                    'object_id' => $group['object_ids'][0],
                    'operation' => 'replace',
                    'object' => $this->conceptPageObject('Endringshåndtering'),
                ]]);
            });

        $result = $this->service()->runForDocument($customer->id, $document->id, 'no');

        $this->assertSame([['concept_candidates[0]', 'concept_pages[0]'], ['concept_pages[0]']], $rounds);
        $this->assertCount(1, $result['concept_pages']);
        $this->assertSame('Endringshåndtering', $result['concept_pages'][0]['title']);
        $this->assertSame([], $result['concept_pages'][0]['related_page_guidance'] ?? [], 'the dangling reference is gone');
    }

    /**
     * Rounds are for progress, not for negotiating: a round that changes nothing about the issue
     * set ends the repair immediately instead of spending another call on the same question.
     */
    public function test_a_repair_round_that_makes_no_progress_stops_instead_of_retrying(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        $decision = $this->validDecision();
        $decision['concept_candidates'] = [$this->candidate('Teststrategi', ['decision' => 'reference_only'])];

        /** @var EnterpriseWikiMaintainerDecisionAiClient&MockInterface $mock */
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class);
        $mock->shouldReceive('decide')->once()->andReturn($decision);
        $this->allowRepairPacking($mock);
        // Exactly one call: the empty delta means no progress, so no second round is attempted.
        $mock->shouldReceive('repairGroup')->once()->andReturn($this->delta([]));

        $this->expectException(EnterpriseWikiMaintainerDecisionInconsistentException::class);

        $this->service()->runForDocument($customer->id, $document->id, 'no');
    }

    /**
     * The bounded contract's core guarantee: a repair that names an object it was not given is
     * rejected before anything is merged, rather than silently rewriting validated planning.
     */
    public function test_repair_delta_touching_an_object_outside_its_group_is_rejected(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        $inconsistent = $this->validDecision();
        $inconsistent['source_article']['related_page_guidance'] = [
            ['page_title' => 'ITIL Incident Management', 'relationship' => 'See the concept page.'],
        ];

        /** @var EnterpriseWikiMaintainerDecisionAiClient&MockInterface $mock */
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class);
        $mock->shouldReceive('decide')->once()->andReturn($inconsistent);
        $this->allowRepairPacking($mock);
        $mock->shouldReceive('repairGroup')->once()->andReturn($this->delta([[
            'collection' => 'source_summary',
            'object_id' => 'source_summary',
            'operation' => 'replace',
            'object' => array_merge($inconsistent['source_summary'], ['title' => 'REWRITTEN BEHIND THE VALIDATORS BACK']),
        ]]));

        $this->expectException(EnterpriseWikiMaintainerDecisionDeltaRejectedException::class);
        $this->expectExceptionMessageMatches('/was not part of this repair group/');

        $this->service()->runForDocument($customer->id, $document->id, 'no');
    }

    public function test_repair_delta_naming_an_unknown_object_is_rejected(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        $inconsistent = $this->validDecision();
        $inconsistent['source_article']['related_page_guidance'] = [
            ['page_title' => 'ITIL Incident Management', 'relationship' => 'See the concept page.'],
        ];

        /** @var EnterpriseWikiMaintainerDecisionAiClient&MockInterface $mock */
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class);
        $mock->shouldReceive('decide')->once()->andReturn($inconsistent);
        $this->allowRepairPacking($mock);
        $mock->shouldReceive('repairGroup')->once()->andReturn($this->delta([[
            'collection' => 'concept_candidates',
            'object_id' => 'concept_candidates[7]',
            'operation' => 'remove',
            'object' => null,
        ]]));

        $this->expectException(EnterpriseWikiMaintainerDecisionDeltaRejectedException::class);
        $this->expectExceptionMessageMatches('/not an object of this decision/');

        $this->service()->runForDocument($customer->id, $document->id, 'no');
    }

    /**
     * Several independent faults are repaired as several bounded calls, and every delta lands in
     * the same final decision — the shape run 51 needed and could not express as one call.
     */
    public function test_multiple_invalid_objects_are_repaired_as_bounded_calls_and_merged_together(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        $inconsistent = $this->validDecision();
        $inconsistent['concept_candidates'] = [
            $this->candidate('Migreringsstrategi', ['decision' => 'reference_only', 'owning_page_title' => null]),
            $this->candidate('Testplan', ['decision' => 'reference_only', 'owning_page_title' => null]),
        ];

        /** @var EnterpriseWikiMaintainerDecisionAiClient&MockInterface $mock */
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class);
        $mock->shouldReceive('decide')->once()->andReturn($inconsistent);
        // One object per call, so two independent faults become two bounded calls.
        $mock->shouldReceive('maxObjectsPerRepairCall')->andReturn(1);
        $mock->shouldReceive('repairGroupFitsOneCall')->andReturn(true);

        $seenGroups = [];

        $mock->shouldReceive('repairGroup')
            ->twice()
            ->andReturnUsing(function (...$args) use (&$seenGroups, $inconsistent): array {
                $group = $args[5];
                $seenGroups[] = $group['object_ids'];
                $index = (int) str_replace(['concept_candidates[', ']'], '', $group['object_ids'][0]);

                return $this->delta([[
                    'collection' => 'concept_candidates',
                    'object_id' => $group['object_ids'][0],
                    'operation' => 'replace',
                    'object' => array_merge($inconsistent['concept_candidates'][$index], ['decision' => 'exclude']),
                ]]);
            });

        $result = $this->service()->runForDocument($customer->id, $document->id, 'no');

        $this->assertSame([['concept_candidates[0]'], ['concept_candidates[1]']], $seenGroups);
        $this->assertSame(['exclude', 'exclude'], array_column($result['concept_candidates'], 'decision'));
        $this->assertSame('Migreringsstrategi', $result['concept_candidates'][0]['name'], 'untouched fields survive the merge');
    }

    public function test_decision_still_inconsistent_after_repair_throws(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        $inconsistent = $this->validDecision();
        $inconsistent['source_article']['related_page_guidance'] = [
            ['page_title' => 'ITIL Incident Management', 'relationship' => 'See the concept page.'],
        ];

        /** @var EnterpriseWikiMaintainerDecisionAiClient&MockInterface $mock */
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class);
        $mock->shouldReceive('decide')->once()->andReturn($inconsistent);
        $this->allowRepairPacking($mock);
        // A delta that changes nothing leaves the contradiction in place — full revalidation of the
        // merged decision must still fail the run rather than accept it.
        $mock->shouldReceive('repairGroup')->once()->andReturn($this->delta([]));

        $this->expectException(EnterpriseWikiMaintainerDecisionInconsistentException::class);

        $this->service()->runForDocument($customer->id, $document->id, 'no');
    }

    public function test_invalid_target_heading_triggers_repair_with_structured_heading_metadata(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $page = $this->createExistingPageWithClause($customer);

        $broken = $this->decisionWithReplaceTarget($page->id, self::REAL_CLAUSE);
        $broken['patch_targets'][0]['target_heading'] = 'Standardendringer';

        $repaired = $this->decisionWithReplaceTarget($page->id, self::REAL_CLAUSE);
        $repaired['patch_targets'][0]['target_heading'] = 'Krav og praksis';

        $captured = [];

        /** @var EnterpriseWikiMaintainerDecisionAiClient&MockInterface $mock */
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class);
        $mock->shouldReceive('decide')->once()->andReturn($broken);
        $this->allowRepairPacking($mock);
        $mock->shouldReceive('repairGroup')
            ->once()
            ->andReturnUsing(function (...$args) use (&$captured, $repaired): array {
                $captured = $args[5]['issues'] ?? [];

                return $this->delta([[
                    'collection' => 'patch_targets',
                    'object_id' => 'patch_targets[0]',
                    'operation' => 'replace',
                    'object' => $repaired['patch_targets'][0],
                ]]);
            });

        $result = $this->service()->runForDocument($customer->id, $document->id, 'no');
        $issues = implode(' | ', $captured);

        $this->assertStringContainsString('issue_code=invalid_target_heading', $issues);
        $this->assertStringContainsString('page_has_subsections=true', $issues);
        $this->assertStringContainsString('valid_target_headings=[', $issues);
        $this->assertStringContainsString('"Krav og praksis"', $issues);
        $this->assertSame('Krav og praksis', $result['patch_targets'][0]['target_heading']);
    }

    public function test_overfragmented_decision_triggers_one_bounded_repair_pass(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $owner = $this->createPageStating($customer, 'Overordnet rammeverk', EnterpriseWikiPage::PAGE_TYPE_CONCEPT, 'Kort rammeverk som eier denne praksisen.');

        $overfragmented = $this->validDecision();
        $overfragmented['concept_candidates'] = [[
            'name' => 'Incident Logging',
            'concept_type' => 'practice',
            'independent_reason' => 'A short practice under the framework.',
            'mentioned_context' => 'bullet list in section 2',
            'existing_page_title' => null,
            'decision' => 'create',
            'justification' => 'Named as a practice.',
            'owning_page_title' => null,
            'necessary_for_article' => false,
            'has_separate_source_evidence' => false,
            'has_reuse_value' => false,
        ]];
        $overfragmented['concept_pages'] = [[
            'action' => 'create',
            'page_id' => null,
            'title' => 'Incident Logging',
            'proposed_slug' => 'incident-logging',
            'reason' => 'Practice under framework.',
            'owned_topics' => [['topic' => 'Logging av hendelser', 'source_element_keys' => ['paragraph-0']]],
        ]];

        $demoted = $overfragmented['concept_candidates'][0];
        $demoted['decision'] = 'reference_only';
        $demoted['owning_page_title'] = $owner->title;

        /** @var EnterpriseWikiMaintainerDecisionAiClient&MockInterface $mock */
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class);
        $mock->shouldReceive('decide')->once()->andReturn($overfragmented);
        $this->allowRepairPacking($mock);
        $mock->shouldReceive('repairGroup')
            ->once()
            ->withArgs(function (array $sourceMeta, string $sourceText, array $indexContext, string $languageCode, array $decision, array $group) use ($overfragmented): bool {
                // The candidate AND the page created for it: demoting one without dropping the
                // other is not a valid fix, so both must be in the same bounded group.
                return $decision === $overfragmented
                    && $group['object_ids'] === ['concept_candidates[0]', 'concept_pages[0]']
                    && str_contains(implode(' ', $group['issues']), 'Incident Logging');
            })
            ->andReturn($this->delta([
                ['collection' => 'concept_candidates', 'object_id' => 'concept_candidates[0]', 'operation' => 'replace', 'object' => $demoted],
                ['collection' => 'concept_pages', 'object_id' => 'concept_pages[0]', 'operation' => 'remove', 'object' => null],
            ]));

        $result = $this->service()->runForDocument($customer->id, $document->id, 'no');

        $this->assertSame([], $result['concept_pages']);
        $this->assertSame('reference_only', $result['concept_candidates'][0]['decision']);
    }

    public function test_decision_still_overfragmented_after_repair_throws(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        $overfragmented = $this->validDecision();
        $overfragmented['concept_candidates'] = [[
            'name' => 'Incident Logging',
            'concept_type' => 'practice',
            'independent_reason' => 'A short practice under the framework.',
            'mentioned_context' => 'bullet list in section 2',
            'existing_page_title' => null,
            'decision' => 'create',
            'justification' => 'Named as a practice.',
            'owning_page_title' => null,
            'necessary_for_article' => false,
            'has_separate_source_evidence' => false,
            'has_reuse_value' => false,
        ]];
        $overfragmented['concept_pages'] = [[
            'action' => 'create',
            'page_id' => null,
            'title' => 'Incident Logging',
            'proposed_slug' => 'incident-logging',
            'reason' => 'Practice under framework.',
            'owned_topics' => [['topic' => 'Logging av hendelser', 'source_element_keys' => ['paragraph-0']]],
        ]];

        /** @var EnterpriseWikiMaintainerDecisionAiClient&MockInterface $mock */
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class);
        $mock->shouldReceive('decide')->once()->andReturn($overfragmented);
        $this->allowRepairPacking($mock);
        $mock->shouldReceive('repairGroup')->once()->andReturn($this->delta([]));

        $this->expectException(EnterpriseWikiMaintainerDecisionInconsistentException::class);

        $this->service()->runForDocument($customer->id, $document->id, 'no');
    }

    public function test_composed_decision_validation_does_not_call_decide_or_persist(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class);
        $mock->shouldNotReceive('decide');
        $mock->shouldNotReceive('repairGroup');

        $result = $this->service()->validateAndRepairForDocument($customer->id, $document, 'no', $this->validDecision());

        $this->assertSame($this->validDecision()['source_article']['title'], $result['source_article']['title']);
    }

    public function test_composed_inconsistent_decision_uses_same_single_repair_pass(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $decision = $this->validDecision();
        $decision['source_article']['related_page_guidance'] = [['page_title' => 'ITIL Incident Management', 'relationship' => 'See']];
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class);
        $mock->shouldNotReceive('decide');
        $this->allowRepairPacking($mock);
        $mock->shouldReceive('repairGroup')->once()->andReturn($this->delta([[
            'collection' => 'concept_pages',
            'object_id' => null,
            'operation' => 'add',
            'object' => $this->conceptPageObject('ITIL Incident Management'),
        ]]));

        $this->assertCount(1, $this->service()->validateAndRepairForDocument($customer->id, $document, 'no', $decision)['concept_pages']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function service(): EnterpriseWikiMaintainerDecisionService
    {
        return app(EnterpriseWikiMaintainerDecisionService::class);
    }

    /**
     * The capacity questions the service asks the client before packing repair groups into calls.
     * Answering them generously keeps each test about the repair semantics it is actually written
     * for; the packing arithmetic itself is covered in EnterpriseWikiAiCapacityPlannerTest.
     */
    private function allowRepairPacking(MockInterface $mock): void
    {
        $mock->shouldReceive('maxObjectsPerRepairCall')->andReturn(8);
        $mock->shouldReceive('repairGroupFitsOneCall')->andReturn(true);
    }

    /**
     * A repair delta in the shape EnterpriseWikiMaintainerDecisionAiClient::repairGroup() returns
     * it (already parsed by EnterpriseWikiMaintainerDecisionDeltaPrompt).
     *
     * @param  list<array{collection: string, object_id: ?string, operation: string, object: ?array<string, mixed>}>  $operations
     */
    private function delta(array $operations): array
    {
        return ['operations' => $operations, 'notes' => null];
    }

    /** Replaces patch_targets[0] with the corrected target from an already-built decision. */
    private function patchTargetDelta(array $repairedDecision): array
    {
        return $this->delta([[
            'collection' => 'patch_targets',
            'object_id' => 'patch_targets[0]',
            'operation' => 'replace',
            'object' => $repairedDecision['patch_targets'][0],
        ]]);
    }

    private function conceptPageObject(string $title): array
    {
        return [
            'action' => 'create',
            'page_id' => null,
            'title' => $title,
            'proposed_slug' => Str::slug($title),
            'reason' => 'Central concept the article points to.',
            // A created concept page owns at least one evidence-bound topic — see
            // EnterpriseWikiPlannedTopicEvidenceValidator. A page with no scope is not a page.
            'owned_topics' => [['topic' => $title.': omfang', 'source_element_keys' => ['paragraph-0']]],
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function candidate(string $name, array $overrides = []): array
    {
        return array_merge([
            'name' => $name,
            'concept_type' => 'process',
            'independent_reason' => 'Own section in the source.',
            'mentioned_context' => 'section 2',
            'existing_page_title' => null,
            'decision' => 'reference_only',
            'justification' => 'Belongs to a broader page.',
            'owning_page_title' => null,
            'necessary_for_article' => false,
            'has_separate_source_evidence' => true,
            'has_reuse_value' => true,
            'relationship' => 'reference_only',
            'existing_owner_page_id' => null,
        ], $overrides);
    }

    private function mockAiClient(array $decision): void
    {
        /** @var EnterpriseWikiMaintainerDecisionAiClient&MockInterface $mock */
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class);
        $mock->shouldReceive('decide')->once()->andReturn($decision);
    }

    private function mockAiClientCapturing(array &$captured): void
    {
        /** @var EnterpriseWikiMaintainerDecisionAiClient&MockInterface $mock */
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class);
        $mock->shouldReceive('decide')
            ->once()
            ->andReturnUsing(
                function (
                    array $sourceMeta,
                    string $sourceText,
                    array $indexContext,
                    string $languageCode,
                ) use (&$captured): array {
                    $captured = compact('sourceMeta', 'sourceText', 'indexContext', 'languageCode');

                    return $this->validDecision();
                }
            );
    }

    // =========================================================================
    // Fase 8K-3 correction — superseded_substance is verified at DECISION time
    // =========================================================================

    /**
     * THE RUN-28 REGRESSION. The maintainer quoted a clause as if it were a whole sentence: it wrote
     * "... innen 30 minutter." while the page states "... innen 30 minutter, driftsleder skal varsle ...".
     *
     * Before this correction the decision validated (the heading existed), was persisted, and only
     * failed hours later inside the patch engine — long after the bounded repair pass that could have
     * fixed it, taking nine otherwise-valid targets on that page down with it.
     *
     * The fix is NOT fuzzy matching in the engine. The engine must stay strict; the maintainer gets a
     * repair opportunity instead.
     */
    public function test_a_superseded_substance_quoted_as_a_sentence_triggers_a_bounded_repair(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $page = $this->createExistingPageWithClause($customer);

        $broken = $this->decisionWithReplaceTarget($page->id, 'P1-hendelser skal bekreftes av driftsteamet innen 30 minutter.');
        $repaired = $this->decisionWithReplaceTarget($page->id, self::REAL_CLAUSE);

        $captured = [];

        /** @var EnterpriseWikiMaintainerDecisionAiClient&MockInterface $mock */
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class);
        $mock->shouldReceive('decide')->once()->andReturn($broken);
        $this->allowRepairPacking($mock);
        $mock->shouldReceive('repairGroup')->once()
            ->andReturnUsing(function (...$args) use (&$captured, $repaired): array {
                $captured = $args[5]['issues'] ?? [];

                return $this->patchTargetDelta($repaired);
            });

        $result = $this->service()->runForDocument($customer->id, $document->id, 'no');

        $issues = implode(' | ', $captured);

        $this->assertStringContainsString('is not present verbatim', $issues, 'the repair pass must be told what is wrong');
        $this->assertStringContainsString(self::REAL_CLAUSE, $issues, 'and shown the wording that actually exists');
        $this->assertStringContainsString('copying an EXACT substring', $issues);
        $this->assertSame(self::REAL_CLAUSE, $result['patch_targets'][0]['superseded_substance'], 'the repaired decision is what is returned');
    }

    public function test_a_correctly_quoted_superseded_substance_needs_no_repair(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $page = $this->createExistingPageWithClause($customer);

        /** @var EnterpriseWikiMaintainerDecisionAiClient&MockInterface $mock */
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class);
        $mock->shouldReceive('decide')->once()
            ->andReturn($this->decisionWithReplaceTarget($page->id, self::REAL_CLAUSE));
        $mock->shouldNotReceive('repairGroup');

        $this->service()->runForDocument($customer->id, $document->id, 'no');
    }

    /** The clause as the page actually states it — note it continues past the deadline. */
    private const REAL_CLAUSE = 'P1-hendelser skal bekreftes av driftsteamet innen 30 minutter, driftsleder skal varsle tjenesteeier ved alle P1-hendelser.';

    /**
     * Filler that pushes the real clause deep into the target area, reproducing run 29's geometry:
     * there, the text the repair pass had to copy sat 537–1077 characters past where the old
     * 400-character excerpt stopped, so the instruction "copy it verbatim" was unfollowable.
     */
    private const DEEP_FILLER = 'Denne innledende teksten beskriver bakgrunn, formaal og omfang i generelle vendinger uten aa tallfeste noe som helst. Den forklarer hvordan styringen henger sammen med oevrige rutiner, hvilke roller som deltar, og hvordan avvik foelges opp i den loepende driften. Teksten fortsetter tilstrekkelig lenge til at et kort utdrag fra begynnelsen av seksjonen ikke naar frem til det konkrete kravet som staar lenger nede, slik tilfellet var i den observerte kjoeringen.';

    // =========================================================================
    // Run-29 regression — the repair pass must SEE the text it is told to copy
    // =========================================================================

    /**
     * THE RUN-29 REGRESSION. The clause sits ~900 characters into the target area. With the old
     * "first 400 characters" excerpt it was invisible, and the repair pass paraphrased. The context
     * must now contain it, so a repair that copies an exact substring re-validates cleanly.
     */
    public function test_repair_context_contains_the_clause_even_when_it_sits_deep_in_the_area(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $page = $this->createExistingPageWithClause($customer, deep: true);

        $captured = [];

        /** @var EnterpriseWikiMaintainerDecisionAiClient&MockInterface $mock */
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class);
        $mock->shouldReceive('decide')->once()
            ->andReturn($this->decisionWithReplaceTarget($page->id, 'P1-hendelser skal bekreftes av driftsteamet innen 30 minutter.'));
        $this->allowRepairPacking($mock);
        $mock->shouldReceive('repairGroup')->once()
            ->andReturnUsing(function (...$args) use (&$captured, $page): array {
                $captured = $args[5]['issues'] ?? [];

                // A repair pass that can actually READ the clause copies an exact substring of it.
                return $this->patchTargetDelta($this->decisionWithReplaceTarget($page->id, self::REAL_CLAUSE));
            });

        $result = $this->service()->runForDocument($customer->id, $document->id, 'no');

        $issues = implode(' | ', $captured);
        $contextStart = mb_strpos($issues, 'The relevant target area currently states');
        $clauseAt = mb_strpos($issues, self::REAL_CLAUSE);

        $this->assertNotFalse($clauseAt, 'the deep clause must be visible in the repair context');
        // Guards the test itself: if the clause ever drifts back inside the first 400 characters of
        // the area, this stops reproducing run 29 and would pass for the wrong reason.
        $this->assertGreaterThan(
            400,
            $clauseAt - (int) $contextStart,
            'the fixture must keep the clause past the old 400-character cut',
        );
        $this->assertSame(self::REAL_CLAUSE, $result['patch_targets'][0]['superseded_substance']);
    }

    public function test_a_shorter_exact_substring_is_an_acceptable_repair(): void
    {
        // The contract does not demand a whole sentence — only exact, present and identifying. A
        // shorter fragment is usually the safer correction.
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $page = $this->createExistingPageWithClause($customer, deep: true);

        $fragment = 'innen 30 minutter, driftsleder skal varsle';

        /** @var EnterpriseWikiMaintainerDecisionAiClient&MockInterface $mock */
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class);
        $mock->shouldReceive('decide')->once()
            ->andReturn($this->decisionWithReplaceTarget($page->id, 'P1-hendelser skal bekreftes av driftsteamet innen 30 minutter.'));
        $this->allowRepairPacking($mock);
        $mock->shouldReceive('repairGroup')->once()
            ->andReturn($this->patchTargetDelta($this->decisionWithReplaceTarget($page->id, $fragment)));

        $result = $this->service()->runForDocument($customer->id, $document->id, 'no');

        $this->assertSame($fragment, $result['patch_targets'][0]['superseded_substance']);
    }

    /**
     * Run 29 also showed the repair pass reaching for wording it COULD see — from another target's
     * page — when its own was cut off. Each issue must therefore quote its own target's area.
     */
    public function test_each_issue_quotes_its_own_target_area_and_not_another_pages(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);

        // Two pages stating the SAME value in DIFFERENT words — run 29's exact contamination setup.
        $pageA = $this->createExistingPageWithClause($customer, deep: true);
        $pageB = $this->createPageStating(
            $customer,
            'Sammendrag: Styrende prosedyre',
            EnterpriseWikiPage::PAGE_TYPE_SUMMARY,
            self::DEEP_FILLER.' Tjenesten styres mot en bekreftelsestid paa 30 minutter, og rapporteres maanedlig.',
        );

        $decision = $this->decisionWithReplaceTarget($pageA->id, 'P1-hendelser skal bekreftes av driftsteamet innen 30 minutter.');
        $decision['patch_targets'][] = array_merge($decision['patch_targets'][0], [
            'target_page_id' => $pageB->id,
            'target_page_title' => $pageB->title,
            'target_page_type' => EnterpriseWikiPage::PAGE_TYPE_SUMMARY,
            'target_heading' => null,
            'superseded_substance' => 'Tjenesten styres mot en bekreftelsestid paa 30 minutter.',
        ]);

        $captured = [];

        /** @var EnterpriseWikiMaintainerDecisionAiClient&MockInterface $mock */
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class);
        $mock->shouldReceive('decide')->once()->andReturn($decision);
        $this->allowRepairPacking($mock);
        $mock->shouldReceive('repairGroup')->once()
            ->andReturnUsing(function (...$args) use (&$captured): array {
                $captured = $args[5]['issues'] ?? [];

                throw new \RuntimeException('stop-after-capture');
            });

        try {
            $this->service()->runForDocument($customer->id, $document->id, 'no');
        } catch (\RuntimeException $e) {
            $this->assertSame('stop-after-capture', $e->getMessage());
        }

        $this->assertCount(2, $captured, 'both targets must raise their own issue');

        $issueA = $this->issueMentioning($captured, "page [{$pageA->id}]");
        $issueB = $this->issueMentioning($captured, "page [{$pageB->id}]");

        $this->assertStringContainsString(self::REAL_CLAUSE, $issueA, "page A's issue must quote page A");
        $this->assertStringNotContainsString('Tjenesten styres mot en bekreftelsestid', $issueA, "page A's issue must not leak page B's wording");

        $this->assertStringContainsString('Tjenesten styres mot en bekreftelsestid', $issueB, "page B's issue must quote page B");
        $this->assertStringNotContainsString(self::REAL_CLAUSE, $issueB, "page B's issue must not leak page A's wording");
    }

    public function test_a_flat_summary_area_is_shown_in_the_repair_context(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $needed = 'Kort: bekreftelse skjer innen 30 minutter, og avvik rapporteres maanedlig.';
        $page = $this->createPageStating(
            $customer,
            'Sammendrag: Styrende prosedyre',
            EnterpriseWikiPage::PAGE_TYPE_SUMMARY,
            self::DEEP_FILLER.' '.$needed,
        );

        $decision = $this->decisionWithReplaceTarget($page->id, 'Bekreftelse skjer innen 30 minutter.');
        $decision['patch_targets'][0]['target_page_type'] = EnterpriseWikiPage::PAGE_TYPE_SUMMARY;
        $decision['patch_targets'][0]['target_page_title'] = $page->title;
        $decision['patch_targets'][0]['target_heading'] = null;

        $captured = [];

        /** @var EnterpriseWikiMaintainerDecisionAiClient&MockInterface $mock */
        $mock = $this->mock(EnterpriseWikiMaintainerDecisionAiClient::class);
        $mock->shouldReceive('decide')->once()->andReturn($decision);
        $this->allowRepairPacking($mock);
        $mock->shouldReceive('repairGroup')->once()
            ->andReturnUsing(function (...$args) use (&$captured, $page, $needed): array {
                $captured = $args[5]['issues'] ?? [];
                $repaired = $this->decisionWithReplaceTarget($page->id, $needed);
                $repaired['patch_targets'][0]['target_page_type'] = EnterpriseWikiPage::PAGE_TYPE_SUMMARY;
                $repaired['patch_targets'][0]['target_page_title'] = $page->title;
                $repaired['patch_targets'][0]['target_heading'] = null;

                return $this->patchTargetDelta($repaired);
            });

        $result = $this->service()->runForDocument($customer->id, $document->id, 'no');
        $issues = implode(' | ', $captured);

        $this->assertStringContainsString('page body (this page has no sub-sections)', $issues);
        $this->assertStringContainsString($needed, $issues, 'a flat page body must be shown to the repair pass too');
        $this->assertSame($needed, $result['patch_targets'][0]['superseded_substance']);
    }

    /** @param list<string> $issues */
    private function issueMentioning(array $issues, string $needle): string
    {
        foreach ($issues as $issue) {
            if (str_contains($issue, $needle)) {
                return $issue;
            }
        }

        $this->fail("no issue mentions [{$needle}]");
    }

    private function createPageStating(Customer $customer, string $title, string $pageType, string $body): EnterpriseWikiPage
    {
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'title' => $title,
            'page_type' => $pageType,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        $this->writeVersion($page, "# {$title}\n\n{$body}");

        return $page;
    }

    /**
     * @param  bool  $deep  place the clause ~900 characters into the section, as run 29's pages did
     */
    private function createExistingPageWithClause(Customer $customer, bool $deep = false): EnterpriseWikiPage
    {
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'styrende-prosedyre-'.Str::lower(Str::random(4)),
            'title' => 'Styrende prosedyre',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        $body = $deep
            ? self::DEEP_FILLER."\n\n".self::REAL_CLAUSE
            : self::REAL_CLAUSE;

        $this->writeVersion($page, "# Styrende prosedyre\n\n## Krav og praksis\n\n".$body);

        return $page;
    }

    private function writeVersion(EnterpriseWikiPage $page, string $markdown): void
    {
        $parts = explode("\n\n", $markdown);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => $markdown,
            'content_blocks_json' => array_map(
                static fn (string $part, int $i): array => [
                    'block_key' => 'block-'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
                    'position' => $i,
                    'markdown' => $part,
                ],
                $parts,
                array_keys($parts),
            ),
            'generated_by_model' => 'gpt-5',
        ]);
    }

    /** @return array<string, mixed> */
    private function decisionWithReplaceTarget(int $pageId, string $superseded): array
    {
        $decision = $this->validDecision();

        $decision['patch_targets'] = [[
            'target_page_id' => $pageId,
            'target_page_title' => 'Styrende prosedyre',
            'target_page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'target_topic' => 'P1-responstid',
            'target_heading' => 'Krav og praksis',
            'relationship' => 'substance_changed',
            'operation' => 'replace',
            'superseded_substance' => $superseded,
            'replacement_substance' => 'P1-hendelser skal bekreftes av driftsteamet innen 15 minutter, driftsleder skal varsle tjenesteeier ved alle P1-hendelser.',
            // A replace must name the source element authorising it — the merged decision is
            // re-parsed against the full contract after a delta repair, exactly as a first-pass
            // decision is, so a fixture that skipped this would not be a decision the pipeline
            // could ever have produced.
            'source_element_keys' => ['paragraph-0'],
            'preserve_topics' => [],
            'reason' => 'Kilden halverer bekreftelsestiden.',
        ]];

        return $decision;
    }

    private function validDecision(): array
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
            'concept_pages' => [],
            'entity_pages' => [],
            'no_action_reason' => null,
            'warnings' => [],
        ];
    }

    private function createCustomer(string $name = 'Test AS'): Customer
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function createDocument(Customer $customer, array $overrides = []): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create(array_merge([
            'customer_id' => $customer->id,
            'original_filename' => 'selskapsinfo.docx',
            'file_path' => 'wiki/'.Str::random(12).'.docx',
            'file_hash_sha256' => Str::random(64),
            'extracted_text' => 'Standardinnhold.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ], $overrides));
    }
}
