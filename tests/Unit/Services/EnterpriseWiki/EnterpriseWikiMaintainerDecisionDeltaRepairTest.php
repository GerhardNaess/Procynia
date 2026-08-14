<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Exceptions\EnterpriseWikiMaintainerDecisionDeltaRejectedException;
use App\Services\EnterpriseWiki\EnterpriseWikiCanonicalOwnershipValidator;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionConsistencyValidator;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionDeltaMerger;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionDeltaPrompt;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionHierarchyValidator;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionIssueAttributor;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionObjectIndex;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionPrompt;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The bounded delta-repair contract: issue attribution, the delta shape, and the deterministic
 * merge that keeps validated planning out of the model's reach.
 *
 * Run 51 is the case behind all of it: a 31 599-character decision (35 concept candidates, 13
 * concept pages) with 21 validation issues, every one of them local to a single candidate. The old
 * repair pass had to re-emit the entire decision — ~9 500 output tokens against a 9 000 ceiling —
 * and failed twice at exactly that ceiling. These tests fix the shape that made that possible.
 */
class EnterpriseWikiMaintainerDecisionDeltaRepairTest extends TestCase
{
    // =========================================================================
    // Object identity
    // =========================================================================

    public function test_every_addressable_object_of_a_decision_has_an_id(): void
    {
        $ids = EnterpriseWikiMaintainerDecisionObjectIndex::objectIds($this->decision());

        $this->assertSame([
            'source_article',
            'source_summary',
            'concept_candidates[0]',
            'concept_candidates[1]',
            'concept_pages[0]',
        ], $ids);
    }

    public function test_an_object_id_that_does_not_resolve_is_rejected(): void
    {
        $decision = $this->decision();

        $this->assertTrue(EnterpriseWikiMaintainerDecisionObjectIndex::exists($decision, 'concept_candidates[1]'));
        $this->assertFalse(EnterpriseWikiMaintainerDecisionObjectIndex::exists($decision, 'concept_candidates[9]'));
        $this->assertFalse(EnterpriseWikiMaintainerDecisionObjectIndex::exists($decision, 'not_a_collection[0]'));
        $this->assertNull(EnterpriseWikiMaintainerDecisionObjectIndex::parseObjectId('concept_candidates'));
    }

    // =========================================================================
    // Issue attribution
    // =========================================================================

    public function test_a_single_invalid_object_produces_a_single_bounded_group(): void
    {
        $decision = $this->decision();
        // "Migreringsstrategi" is reference_only with nowhere to point.
        $issues = $this->pureIssues($decision);

        $attribution = $this->attributor()->attribute($decision, $issues, fn (array $d): array => $this->pureIssues($d));

        $this->assertSame([], $attribution['unattributed']);
        $this->assertCount(1, $attribution['groups']);
        $this->assertSame(['concept_candidates[1]'], $attribution['groups'][0]['object_ids']);
        $this->assertStringContainsString('Migreringsstrategi', implode(' ', $attribution['groups'][0]['issues']));
    }

    public function test_independent_faults_produce_independent_groups(): void
    {
        $decision = $this->decision();
        $decision['concept_candidates'][0] = $this->candidate('Testplan', ['decision' => 'reference_only']);
        $issues = $this->pureIssues($decision);

        $attribution = $this->attributor()->attribute($decision, $issues, fn (array $d): array => $this->pureIssues($d));

        $groups = array_map(static fn (array $group): array => $group['object_ids'], $attribution['groups']);

        $this->assertSame([], $attribution['unattributed']);
        $this->assertCount(2, $groups, 'two unrelated faults must not be forced into one call');
        // Candidate 0 keeps the page created for it in its own group (demoting the candidate means
        // reconsidering that page); candidate 1 has no page and stays alone.
        $this->assertContains(['concept_candidates[0]', 'concept_pages[0]'], $groups);
        $this->assertContains(['concept_candidates[1]'], $groups);
    }

    /**
     * A structural coupling the issue text never mentions: overfragmentation is reported against
     * the candidate, but the fix also has to drop the page created for it. Repairing the candidate
     * alone would produce a delta the merge rejects — a fail-closed run over a fixable fault.
     */
    public function test_a_candidate_and_the_page_created_for_it_are_repaired_together(): void
    {
        $decision = $this->decision();
        $decision['concept_candidates'] = [$this->candidate('Avvikshåndtering', [
            'decision' => 'create',
            'relationship' => 'independent_new_topic',
            'has_separate_source_evidence' => false,
            'has_reuse_value' => false,
        ])];
        // The page carries a specialising suffix the candidate name does not.
        $decision['concept_pages'] = [$this->page('Avvikshåndtering i prosjekter')];

        $issues = $this->pureIssues($decision);
        $attribution = $this->attributor()->attribute($decision, $issues, fn (array $d): array => $this->pureIssues($d));

        $this->assertNotEmpty($issues);
        $this->assertCount(1, $attribution['groups']);
        $this->assertSame(
            ['concept_candidates[0]', 'concept_pages[0]'],
            $attribution['groups'][0]['object_ids'],
        );
    }

    public function test_issues_naming_an_object_structurally_are_attributed_without_revalidation(): void
    {
        $decision = $this->decision();
        $decision['patch_targets'] = [$this->patchTarget()];
        $revalidations = 0;

        $attribution = $this->attributor()->attribute(
            $decision,
            ['patch_targets[0].target_page_id [77] does not exist.'],
            function (array $d) use (&$revalidations): array {
                $revalidations++;

                return [];
            },
        );

        // The DB-authoritative validator's own findings carry their object id, so attribution never
        // has to re-run anything for them — which is also what keeps the database out of the
        // attribution loop entirely.
        $this->assertSame(0, $revalidations);
        $this->assertSame([['object_ids' => ['patch_targets[0]'], 'issues' => ['patch_targets[0].target_page_id [77] does not exist.']]], $attribution['groups']);
        $this->assertSame([], $attribution['unattributed']);
    }

    public function test_a_structural_reference_that_does_not_resolve_is_never_silently_attributed(): void
    {
        // patch_targets[0] does not exist in this decision: the reference must not scope a repair
        // at an object that is not there, and no attribution means fail closed.
        $attribution = $this->attributor()->attribute(
            $this->decision(),
            ['patch_targets[0].target_page_id [77] does not exist.'],
            fn (array $d): array => [],
        );

        $this->assertSame([], $attribution['groups']);
        $this->assertCount(1, $attribution['unattributed']);
    }

    public function test_an_unattributable_issue_is_reported_rather_than_guessed_at(): void
    {
        $attribution = $this->attributor()->attribute(
            $this->decision(),
            ['Something is wrong with this decision, somewhere.'],
            fn (array $d): array => ['Something is wrong with this decision, somewhere.'],
        );

        $this->assertSame([], $attribution['groups']);
        $this->assertSame(['Something is wrong with this decision, somewhere.'], $attribution['unattributed']);
    }

    // =========================================================================
    // Delta contract
    // =========================================================================

    public function test_delta_schema_requires_the_same_object_shapes_as_the_decision_contract(): void
    {
        $schema = EnterpriseWikiMaintainerDecisionDeltaPrompt::jsonSchema();
        $candidateEntry = $schema['json_schema']['schema']['properties']['concept_candidate_repairs']['items'];

        $this->assertSame('maintainer_decision_repair_delta', $schema['json_schema']['name']);
        $this->assertTrue($schema['json_schema']['strict']);
        $this->assertSame(['object', 'null'], $candidateEntry['properties']['object']['type']);
        $this->assertSame(
            EnterpriseWikiMaintainerDecisionPrompt::conceptCandidateObjectSchema()['required'],
            $candidateEntry['properties']['object']['required'],
            'a repaired object must be the exact shape of the object it replaces',
        );
    }

    public function test_delta_rejects_a_replace_without_an_object_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/object_id is required for operation "replace"/');

        EnterpriseWikiMaintainerDecisionDeltaPrompt::parse([
            'concept_candidate_repairs' => [['object_id' => null, 'operation' => 'replace', 'object' => ['name' => 'X']]],
        ]);
    }

    public function test_delta_rejects_removing_a_source_page(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not valid for a source page/');

        EnterpriseWikiMaintainerDecisionDeltaPrompt::parse([
            'source_page_repairs' => [['object_id' => 'source_article', 'operation' => 'remove', 'object' => null]],
        ]);
    }

    // =========================================================================
    // Deterministic merge
    // =========================================================================

    public function test_merge_replaces_only_the_named_object_and_leaves_everything_else_identical(): void
    {
        $decision = $this->decision();
        $corrected = array_merge($decision['concept_candidates'][1], ['decision' => 'exclude']);

        $merged = $this->merger()->merge($decision, [[
            'group' => ['object_ids' => ['concept_candidates[1]'], 'issues' => ['issue']],
            'delta' => ['operations' => [[
                'collection' => 'concept_candidates',
                'object_id' => 'concept_candidates[1]',
                'operation' => 'replace',
                'object' => $corrected,
            ]], 'notes' => null],
        ]]);

        $this->assertSame('exclude', $merged['decision']['concept_candidates'][1]['decision']);
        $this->assertSame($decision['concept_candidates'][0], $merged['decision']['concept_candidates'][0]);
        $this->assertSame($decision['concept_pages'], $merged['decision']['concept_pages']);
        $this->assertSame($decision['source_article'], $merged['decision']['source_article']);
        $this->assertSame($decision['source_summary'], $merged['decision']['source_summary']);
    }

    public function test_merge_applies_several_deltas_from_several_calls(): void
    {
        $decision = $this->decision();

        $merged = $this->merger()->merge($decision, [
            [
                'group' => ['object_ids' => ['concept_candidates[0]'], 'issues' => ['a']],
                'delta' => ['operations' => [[
                    'collection' => 'concept_candidates',
                    'object_id' => 'concept_candidates[0]',
                    'operation' => 'replace',
                    'object' => array_merge($decision['concept_candidates'][0], ['decision' => 'exclude']),
                ]], 'notes' => null],
            ],
            [
                'group' => ['object_ids' => ['concept_candidates[1]', 'concept_pages[0]'], 'issues' => ['b']],
                'delta' => ['operations' => [
                    [
                        'collection' => 'concept_candidates',
                        'object_id' => 'concept_candidates[1]',
                        'operation' => 'replace',
                        'object' => array_merge($decision['concept_candidates'][1], ['owning_page_title' => 'Migreringsstrategi']),
                    ],
                    [
                        'collection' => 'concept_pages',
                        'object_id' => 'concept_pages[0]',
                        'operation' => 'remove',
                        'object' => null,
                    ],
                ], 'notes' => null],
            ],
        ]);

        $this->assertSame('exclude', $merged['decision']['concept_candidates'][0]['decision']);
        $this->assertSame('Migreringsstrategi', $merged['decision']['concept_candidates'][1]['owning_page_title']);
        $this->assertSame([], $merged['decision']['concept_pages'], 'a removal re-keys the list rather than leaving a gap');
        $this->assertCount(3, $merged['applied']);
    }

    public function test_merge_rejects_an_edit_to_an_object_outside_the_repair_group(): void
    {
        $decision = $this->decision();

        $this->expectException(EnterpriseWikiMaintainerDecisionDeltaRejectedException::class);
        $this->expectExceptionMessageMatches('/was not part of this repair group/');

        $this->merger()->merge($decision, [[
            'group' => ['object_ids' => ['concept_candidates[1]'], 'issues' => ['issue']],
            'delta' => ['operations' => [[
                'collection' => 'concept_candidates',
                'object_id' => 'concept_candidates[0]',
                'operation' => 'remove',
                'object' => null,
            ]], 'notes' => null],
        ]]);
    }

    public function test_merge_rejects_an_unknown_object_identity(): void
    {
        $this->expectException(EnterpriseWikiMaintainerDecisionDeltaRejectedException::class);
        $this->expectExceptionMessageMatches('/not an object of this decision/');

        $this->merger()->merge($this->decision(), [[
            'group' => ['object_ids' => ['concept_candidates[1]'], 'issues' => ['issue']],
            'delta' => ['operations' => [[
                'collection' => 'concept_candidates',
                'object_id' => 'concept_candidates[42]',
                'operation' => 'replace',
                'object' => $this->candidate('Ghost'),
            ]], 'notes' => null],
        ]]);
    }

    public function test_merge_rejects_an_addition_that_duplicates_an_existing_page(): void
    {
        $this->expectException(EnterpriseWikiMaintainerDecisionDeltaRejectedException::class);
        $this->expectExceptionMessageMatches('/would create a second concept_pages/');

        $this->merger()->merge($this->decision(), [[
            'group' => ['object_ids' => ['concept_candidates[1]'], 'issues' => ['issue']],
            'delta' => ['operations' => [[
                'collection' => 'concept_pages',
                'object_id' => null,
                'operation' => 'add',
                'object' => $this->page('Testplan'),
            ]], 'notes' => null],
        ]]);
    }

    public function test_nothing_is_merged_when_any_operation_is_rejected(): void
    {
        $decision = $this->decision();

        try {
            $this->merger()->merge($decision, [[
                'group' => ['object_ids' => ['concept_candidates[1]'], 'issues' => ['issue']],
                'delta' => ['operations' => [
                    [
                        'collection' => 'concept_candidates',
                        'object_id' => 'concept_candidates[1]',
                        'operation' => 'replace',
                        'object' => array_merge($decision['concept_candidates'][1], ['decision' => 'exclude']),
                    ],
                    [
                        'collection' => 'concept_pages',
                        'object_id' => 'concept_pages[0]',
                        'operation' => 'remove',
                        'object' => null,
                    ],
                ], 'notes' => null],
            ]]);

            $this->fail('the merge should have been rejected');
        } catch (EnterpriseWikiMaintainerDecisionDeltaRejectedException $e) {
            $this->assertSame($this->decision(), $decision, 'a rejected delta must leave the decision untouched');
        }
    }

    /**
     * The size property that makes the whole design work: whatever the decision weighs, the delta
     * a repair returns is proportional to the number of faulty objects. Run 51's decision was 31 599
     * characters; the fix for one of its candidates is a fraction of that.
     */
    public function test_a_delta_stays_bounded_even_when_the_decision_is_large(): void
    {
        $decision = $this->decision();

        for ($i = 0; $i < 40; $i++) {
            $decision['concept_candidates'][] = $this->candidate("Konsept {$i}", ['decision' => 'exclude']);
            $decision['concept_pages'][] = $this->page("Konsept {$i} side");
        }

        $decisionSize = mb_strlen((string) json_encode($decision, JSON_UNESCAPED_UNICODE));
        $delta = [
            'concept_candidate_repairs' => [[
                'object_id' => 'concept_candidates[1]',
                'operation' => 'replace',
                'object' => array_merge($decision['concept_candidates'][1], ['decision' => 'exclude']),
            ]],
            'concept_page_repairs' => [],
            'entity_page_repairs' => [],
            'patch_target_repairs' => [],
            'source_page_repairs' => [],
            'notes' => null,
        ];
        $deltaSize = mb_strlen((string) json_encode($delta, JSON_UNESCAPED_UNICODE));

        $this->assertGreaterThan(20_000, $decisionSize, 'the fixture must be a genuinely large decision');
        $this->assertLessThan(
            $decisionSize / 10,
            $deltaSize,
            'a one-object repair must not scale with the decision it came from',
        );

        // And it still merges into exactly that one object.
        $merged = $this->merger()->merge($decision, [[
            'group' => ['object_ids' => ['concept_candidates[1]'], 'issues' => ['issue']],
            'delta' => EnterpriseWikiMaintainerDecisionDeltaPrompt::parse($delta),
        ]]);

        $this->assertCount(count($decision['concept_candidates']), $merged['decision']['concept_candidates']);
        $this->assertSame('exclude', $merged['decision']['concept_candidates'][1]['decision']);
    }

    public function test_a_merged_decision_is_still_a_valid_decision(): void
    {
        $decision = $this->decision();

        $merged = $this->merger()->merge($decision, [[
            'group' => ['object_ids' => ['concept_candidates[1]'], 'issues' => ['issue']],
            'delta' => ['operations' => [[
                'collection' => 'concept_candidates',
                'object_id' => 'concept_candidates[1]',
                'operation' => 'replace',
                'object' => array_merge($decision['concept_candidates'][1], ['decision' => 'exclude']),
            ]], 'notes' => null],
        ]]);

        // Schema revalidation of the whole merged result, exactly as the service performs it.
        $parsed = EnterpriseWikiMaintainerDecisionPrompt::parse($merged['decision']);

        $this->assertSame([], $this->pureIssues($parsed), 'the repaired decision must pass every deterministic validator');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function attributor(): EnterpriseWikiMaintainerDecisionIssueAttributor
    {
        return app(EnterpriseWikiMaintainerDecisionIssueAttributor::class);
    }

    private function merger(): EnterpriseWikiMaintainerDecisionDeltaMerger
    {
        return app(EnterpriseWikiMaintainerDecisionDeltaMerger::class);
    }

    /** The deterministic, pure-array validators the service runs (and re-runs during attribution). */
    private function pureIssues(array $decision): array
    {
        return array_merge(
            app(EnterpriseWikiMaintainerDecisionConsistencyValidator::class)->findIssues($decision, []),
            app(EnterpriseWikiMaintainerDecisionHierarchyValidator::class)->findIssues($decision),
            app(EnterpriseWikiCanonicalOwnershipValidator::class)->findIssues($decision, []),
        );
    }

    /**
     * A decision with one valid candidate/page pair and one candidate that is reference_only with
     * no owning page — the single most common fault shape in run 51.
     */
    private function decision(): array
    {
        return [
            'source_article' => $this->sourcePage('Masterdata Prosjekt', 'masterdata-prosjekt-ab1c2d'),
            'source_summary' => $this->sourcePage('Sammendrag: Masterdata Prosjekt', 'sammendrag-masterdata-prosjekt-ab1c2d'),
            'concept_candidates' => [
                $this->candidate('Testplan', ['decision' => 'create', 'relationship' => 'independent_new_topic']),
                $this->candidate('Migreringsstrategi', ['decision' => 'reference_only']),
            ],
            'concept_pages' => [$this->page('Testplan')],
            'entity_pages' => [],
            'patch_targets' => [],
            'no_action_reason' => null,
            'warnings' => [],
        ];
    }

    private function sourcePage(string $title, string $slug): array
    {
        return [
            'action' => 'create',
            'title' => $title,
            'proposed_slug' => $slug,
            'reason' => 'New page for this source.',
            'owned_topics' => ['Dokumentets egne beslutninger'],
            'reference_only_topics' => [],
            'excluded_topics' => [],
            'related_page_guidance' => [],
            'planned_figures' => [],
        ];
    }

    private function page(string $title): array
    {
        return [
            'action' => 'create',
            'page_id' => null,
            'title' => $title,
            'proposed_slug' => 'planlagt-side',
            'reason' => 'Reusable concept.',
            'owned_topics' => ['Omfang'],
            'reference_only_topics' => [],
            'excluded_topics' => [],
            'related_page_guidance' => [],
            'planned_figures' => [],
        ];
    }

    private function patchTarget(): array
    {
        return [
            'target_page_id' => 77,
            'target_page_title' => 'Styrende prosedyre',
            'target_page_type' => 'article',
            'target_topic' => 'P1-responstid',
            'target_heading' => null,
            'relationship' => 'substance_changed',
            'operation' => 'replace',
            'superseded_substance' => 'innen 30 minutter',
            'replacement_substance' => 'innen 15 minutter',
            'source_element_keys' => ['paragraph-0'],
            'preserve_topics' => [],
            'reason' => 'Kilden halverer fristen.',
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function candidate(string $name, array $overrides = []): array
    {
        return array_merge([
            'name' => $name,
            'concept_type' => 'process',
            'independent_reason' => 'Egen seksjon i kilden.',
            'mentioned_context' => 'seksjon 2',
            'existing_page_title' => null,
            'decision' => 'reference_only',
            'justification' => 'Hører til en bredere side.',
            'owning_page_title' => null,
            'necessary_for_article' => false,
            'has_separate_source_evidence' => true,
            'has_reuse_value' => true,
            'relationship' => 'reference_only',
            'existing_owner_page_id' => null,
        ], $overrides);
    }
}
