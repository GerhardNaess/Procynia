<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Services\EnterpriseWiki\EnterpriseWikiCanonicalOwnershipValidator;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionMerger;
use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionPrompt;
use PHPUnit\Framework\TestCase;

/**
 * Fase 8K-2 — the structured patch decision contract, canonical ownership, and page granularity.
 *
 * What 8K-1's observation established, and what this contract has to fix: the maintainer correctly
 * identified an existing article as outdated for two requirements, and could only say so in
 * `warnings`, because the decision had no slot able to address an existing article at all. The
 * contract could express "make a new page" and "reuse a page", never "this existing page states
 * something this document supersedes, here is what and why".
 *
 * These tests are pure: no database, no AI, no container. They assert the contract itself — schema
 * shape, field coupling, and the two product rules that decide whether `create` is even legal.
 *
 * The fixture is domain-free: generic page titles and invented substance, so what is exercised is
 * the mechanism and not any customer's subject matter.
 */
class EnterpriseWikiPatchDecisionContractTest extends TestCase
{
    // =========================================================================
    // Schema shape and strictness
    // =========================================================================

    public function test_patch_targets_is_a_required_strict_top_level_field(): void
    {
        $schema = EnterpriseWikiMaintainerDecisionPrompt::jsonSchema()['json_schema'];

        $this->assertTrue($schema['strict']);
        $this->assertFalse($schema['schema']['additionalProperties']);
        $this->assertArrayHasKey('patch_targets', $schema['schema']['properties']);
        $this->assertContains('patch_targets', $schema['schema']['required']);
    }

    public function test_patch_target_schema_is_strict_and_requires_every_field(): void
    {
        $item = EnterpriseWikiMaintainerDecisionPrompt::jsonSchema()['json_schema']['schema']['properties']['patch_targets']['items'];

        $this->assertFalse($item['additionalProperties'], 'the patch target object must be strict');
        $this->assertSame(array_keys($item['properties']), $item['required'], 'strict mode requires every property');

        foreach ([
            'target_page_id', 'target_page_title', 'target_page_type', 'target_topic', 'target_heading',
            'relationship', 'operation', 'superseded_substance', 'replacement_substance',
            'source_element_keys', 'preserve_topics', 'reason',
        ] as $field) {
            $this->assertArrayHasKey($field, $item['properties'], "{$field} must be part of the patch contract");
        }
    }

    public function test_patch_target_relationship_enum_excludes_independent_new_topic(): void
    {
        $item = EnterpriseWikiMaintainerDecisionPrompt::jsonSchema()['json_schema']['schema']['properties']['patch_targets']['items'];

        // A patch target is by definition about an EXISTING page. "independent_new_topic" asserts
        // the opposite — that the topic needs its own new page — so it can never be a patch target.
        $this->assertNotContains('independent_new_topic', $item['properties']['relationship']['enum']);
        $this->assertSame(['replace', 'amend', 'preserve'], $item['properties']['operation']['enum']);
    }

    public function test_concept_candidate_schema_carries_the_granularity_fields(): void
    {
        $item = EnterpriseWikiMaintainerDecisionPrompt::jsonSchema()['json_schema']['schema']['properties']['concept_candidates']['items'];

        $this->assertArrayHasKey('relationship', $item['properties']);
        $this->assertArrayHasKey('existing_owner_page_id', $item['properties']);
        $this->assertContains('relationship', $item['required']);
        $this->assertContains('existing_owner_page_id', $item['required']);
        $this->assertContains('independent_new_topic', $item['properties']['relationship']['enum']);
    }

    // =========================================================================
    // Backward compatibility — a decision with no patch targets stays valid
    // =========================================================================

    public function test_decision_without_patch_targets_still_validates(): void
    {
        $decision = $this->baseDecision();

        unset($decision['patch_targets']);

        $this->assertSame([], EnterpriseWikiMaintainerDecisionPrompt::validate($decision));
        $this->assertSame([], EnterpriseWikiMaintainerDecisionPrompt::parse($decision)['patch_targets']);
    }

    public function test_legacy_candidate_without_relationship_is_not_retroactively_gated(): void
    {
        $decision = $this->baseDecision([
            'concept_candidates' => [$this->candidate(['decision' => 'create'], dropGranularity: true)],
        ]);

        $this->assertSame([], EnterpriseWikiMaintainerDecisionPrompt::validate($decision));
        $this->assertSame([], $this->validator()->findIssues($decision));
    }

    // =========================================================================
    // Operation <-> substance coupling: preserve is not decoration
    // =========================================================================

    public function test_replace_requires_the_superseded_substance(): void
    {
        $errors = $this->validateTarget($this->target(['superseded_substance' => null]));

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('superseded_substance is required for operation "replace"', implode(' | ', $errors));
    }

    public function test_replace_requires_the_replacement_substance(): void
    {
        $errors = $this->validateTarget($this->target(['replacement_substance' => null]));

        $this->assertStringContainsString('replacement_substance is required for operation "replace"', implode(' | ', $errors));
    }

    public function test_amend_requires_the_amendment_substance(): void
    {
        $errors = $this->validateTarget($this->target([
            'operation' => 'amend',
            'relationship' => 'topic_extended',
            'superseded_substance' => null,
            'replacement_substance' => null,
        ]));

        $this->assertStringContainsString('replacement_substance is required for operation "amend"', implode(' | ', $errors));
    }

    public function test_amend_does_not_require_superseded_substance(): void
    {
        $errors = $this->validateTarget($this->target([
            'operation' => 'amend',
            'relationship' => 'topic_extended',
            'superseded_substance' => null,
            'replacement_substance' => 'Ny presisering innenfor samme tema.',
        ]));

        $this->assertSame([], $errors);
    }

    public function test_preserve_carries_no_substance_and_no_source_elements(): void
    {
        $errors = $this->validateTarget($this->target([
            'operation' => 'preserve',
            'relationship' => 'reference_only',
            'replacement_substance' => 'noe',
            'source_element_keys' => ['paragraph-1'],
        ]));

        $joined = implode(' | ', $errors);

        $this->assertStringContainsString('must not carry superseded_substance or replacement_substance', $joined);
        $this->assertStringContainsString('source_element_keys must be empty for operation "preserve"', $joined);
    }

    public function test_preserve_target_is_valid_as_an_explicit_untouched_assertion(): void
    {
        // The whole point of preserve: distinguishing "examined and deliberately left alone" from
        // "never examined at all". It must be expressible on its own.
        $errors = $this->validateTarget($this->target([
            'operation' => 'preserve',
            'relationship' => 'reference_only',
            'superseded_substance' => null,
            'replacement_substance' => null,
            'source_element_keys' => [],
        ]));

        $this->assertSame([], $errors);
    }

    public function test_preserve_topics_express_what_must_survive_a_patch(): void
    {
        $errors = $this->validateTarget($this->target([
            'preserve_topics' => ['Urelatert avsnitt om noe annet', 'Definisjonen av siden selv'],
        ]));

        $this->assertSame([], $errors);
    }

    public function test_operation_and_relationship_must_agree(): void
    {
        $joined = implode(' | ', $this->validateTarget($this->target(['relationship' => 'topic_extended'])));

        $this->assertStringContainsString('"replace" requires relationship "substance_changed"', $joined);
    }

    // =========================================================================
    // Source element references
    // =========================================================================

    public function test_substantive_operation_requires_at_least_one_source_element_key(): void
    {
        $joined = implode(' | ', $this->validateTarget($this->target(['source_element_keys' => []])));

        $this->assertStringContainsString('must name at least one source element', $joined);
    }

    public function test_several_source_element_keys_are_supported(): void
    {
        // Multi-element provenance is existing architecture (Fase 8J) and must not be narrowed to
        // one-element-one-owner by the patch contract.
        $decision = $this->baseDecision([
            'patch_targets' => [$this->target(['source_element_keys' => ['paragraph-3', 'paragraph-4', 'tbl0-row2']])],
        ]);

        $this->assertSame([], EnterpriseWikiMaintainerDecisionPrompt::validate($decision));
        $this->assertSame([], $this->validator()->findIssues($decision, [], ['paragraph-3', 'paragraph-4', 'tbl0-row2']));
    }

    public function test_unknown_source_element_key_is_rejected(): void
    {
        $decision = $this->baseDecision([
            'patch_targets' => [$this->target(['source_element_keys' => ['paragraph-3', 'paragraph-999']])],
        ]);

        $issues = $this->validator()->findIssues($decision, [], ['paragraph-3', 'tbl0-row2']);

        $this->assertNotEmpty($issues);
        $this->assertStringContainsString('paragraph-999', implode(' | ', $issues));
    }

    public function test_same_source_element_may_authorise_several_targets(): void
    {
        $decision = $this->baseDecision([
            'patch_targets' => [
                $this->target(['target_page_id' => 11, 'source_element_keys' => ['paragraph-3']]),
                $this->target(['target_page_id' => 12, 'source_element_keys' => ['paragraph-3']]),
            ],
        ]);

        $this->assertSame([], $this->validator()->findIssues($decision, [], ['paragraph-3']));
    }

    // =========================================================================
    // Multiple targets
    // =========================================================================

    public function test_multiple_targets_for_the_same_substance_are_supported(): void
    {
        // The observed run's core gap: one superseded value sat current on several existing pages.
        // Forcing a single owner would leave the rest outdated by construction.
        $decision = $this->baseDecision([
            'patch_targets' => [
                $this->target(['target_page_id' => 11, 'target_page_type' => 'article']),
                $this->target(['target_page_id' => 12, 'target_page_type' => 'summary']),
                $this->target(['target_page_id' => 13, 'target_page_type' => 'concept']),
                $this->target(['target_page_id' => 14, 'target_page_type' => 'entity']),
            ],
        ]);

        $this->assertSame([], EnterpriseWikiMaintainerDecisionPrompt::validate($decision));
        $this->assertSame([], $this->validator()->findIssues($decision, [], ['paragraph-3']));
        $this->assertCount(4, EnterpriseWikiMaintainerDecisionPrompt::parse($decision)['patch_targets']);
    }

    public function test_same_page_may_be_targeted_twice_for_different_topics(): void
    {
        $decision = $this->baseDecision([
            'patch_targets' => [
                $this->target(['target_topic' => 'Terskelverdi']),
                $this->target(['target_topic' => 'Frist for respons']),
            ],
        ]);

        $this->assertSame([], $this->validator()->findIssues($decision, [], ['paragraph-3']));
    }

    public function test_same_page_and_topic_cannot_have_conflicting_operations(): void
    {
        $decision = $this->baseDecision([
            'patch_targets' => [
                $this->target(['target_topic' => 'Terskelverdi']),
                $this->target([
                    'target_topic' => 'Terskelverdi',
                    'operation' => 'amend',
                    'relationship' => 'topic_extended',
                    'superseded_substance' => null,
                ]),
            ],
        ]);

        $joined = implode(' | ', $this->validator()->findIssues($decision, [], ['paragraph-3']));

        $this->assertStringContainsString('cannot have two different operations', $joined);
    }

    // =========================================================================
    // Same-run canonical ownership — the owner may be a page THIS decision creates
    //
    // Run 51 classified four migration variants as "topic_specialized" under
    // "Migreringsstrategi", a page the same decision was creating. existing_owner_page_id can only
    // name a page that already exists, so on an empty or young Wiki the classification was
    // unsatisfiable — and the only way out the model had was to relabel them
    // "independent_new_topic", producing four duplicate canonical pages.
    // =========================================================================

    public function test_a_sub_topic_may_name_an_owner_this_decision_itself_creates(): void
    {
        $decision = $this->baseDecision([
            'concept_candidates' => [
                $this->candidate(['name' => 'Migreringsstrategi']),
                $this->candidate([
                    'name' => 'Cutover (Big Bang)',
                    'decision' => 'reference_only',
                    'relationship' => 'topic_specialized',
                    'existing_owner_page_id' => null,
                    'owning_page_title' => 'Migreringsstrategi',
                ]),
            ],
            'concept_pages' => [$this->page(['title' => 'Migreringsstrategi', 'proposed_slug' => 'migreringsstrategi'])],
        ]);

        $this->assertSame([], $this->validator()->findIssues($decision, [], ['paragraph-3']));
    }

    public function test_a_sub_topic_without_any_owner_is_still_rejected(): void
    {
        $decision = $this->baseDecision([
            'concept_candidates' => [
                $this->candidate([
                    'name' => 'Cutover (Big Bang)',
                    'decision' => 'reference_only',
                    'relationship' => 'topic_specialized',
                    'existing_owner_page_id' => null,
                    'owning_page_title' => 'En side som ikke finnes',
                ]),
            ],
        ]);

        $joined = implode(' | ', $this->validator()->findIssues($decision, [], ['paragraph-3']));

        $this->assertStringContainsString('which asserts that an existing page already owns this topic', $joined);
    }

    public function test_a_candidate_cannot_be_its_own_owner(): void
    {
        // Pointing at the page created for the candidate itself would assert both "this belongs
        // under a broader topic" and "this topic is its own page".
        $decision = $this->baseDecision([
            'concept_candidates' => [
                $this->candidate([
                    'name' => 'Migreringsstrategi',
                    'decision' => 'reference_only',
                    'relationship' => 'topic_specialized',
                    'existing_owner_page_id' => null,
                    'owning_page_title' => 'Migreringsstrategi',
                ]),
            ],
            'concept_pages' => [$this->page(['title' => 'Migreringsstrategi', 'proposed_slug' => 'migreringsstrategi'])],
        ]);

        $joined = implode(' | ', $this->validator()->findIssues($decision, [], ['paragraph-3']));

        $this->assertStringContainsString('which asserts that an existing page already owns this topic', $joined);
    }

    /**
     * The relaxation stops at substance_changed on purpose: superseding substance requires
     * substance that already exists in the Wiki, and a structured patch target against that page.
     * A page being created in this run has nothing to supersede.
     */
    public function test_substance_changed_still_requires_a_real_existing_owner(): void
    {
        $decision = $this->baseDecision([
            'concept_candidates' => [
                $this->candidate([
                    'name' => 'Responstid',
                    'decision' => 'reference_only',
                    'relationship' => 'substance_changed',
                    'existing_owner_page_id' => null,
                    'owning_page_title' => 'Migreringsstrategi',
                ]),
            ],
            'concept_pages' => [$this->page(['title' => 'Migreringsstrategi', 'proposed_slug' => 'migreringsstrategi'])],
        ]);

        $joined = implode(' | ', $this->validator()->findIssues($decision, [], ['paragraph-3']));

        $this->assertStringContainsString('which asserts that an existing page already owns this topic', $joined);
    }

    // =========================================================================
    // Target identity — (page, topic, heading), not (page, topic)
    // =========================================================================

    public function test_same_page_topic_and_heading_is_a_duplicate(): void
    {
        $decision = $this->baseDecision([
            'patch_targets' => [
                $this->target(['target_topic' => 'Terskelverdi', 'target_heading' => 'Krav og terskler']),
                $this->target(['target_topic' => 'Terskelverdi', 'target_heading' => 'Krav og terskler']),
            ],
        ]);

        $joined = implode(' | ', $this->validator()->findIssues($decision, [], ['paragraph-3']));

        $this->assertStringContainsString('repeats page [11]', $joined);
        $this->assertStringContainsString('target_topic and target_heading', $joined);
    }

    /**
     * The run-27 shape: one existing page states the SAME superseded requirement twice, under two
     * different headings (a duplicated section inherited from an earlier run). Both occurrences need
     * their own target, or whichever one is dropped stays stale after 8K-3 and the page contradicts
     * itself. Keying identity on (page, topic) alone rejected the second target as a duplicate.
     */
    public function test_same_page_and_topic_under_different_headings_are_both_allowed(): void
    {
        $decision = $this->baseDecision([
            'patch_targets' => [
                $this->target(['target_topic' => 'Terskelverdi', 'target_heading' => 'Krav og terskler']),
                $this->target(['target_topic' => 'Terskelverdi', 'target_heading' => 'Krav og terskler for tjenesten']),
            ],
        ]);

        $this->assertSame([], $this->validator()->findIssues($decision, [], ['paragraph-3']));
        $this->assertSame([], EnterpriseWikiMaintainerDecisionPrompt::validate($decision));
        $this->assertCount(2, EnterpriseWikiMaintainerDecisionPrompt::parse($decision)['patch_targets']);
    }

    public function test_same_heading_with_different_topics_is_allowed(): void
    {
        // One section can legitimately hold two distinct requirements this document changes.
        $decision = $this->baseDecision([
            'patch_targets' => [
                $this->target(['target_topic' => 'Terskelverdi', 'target_heading' => 'Krav og terskler']),
                $this->target(['target_topic' => 'Frist for respons', 'target_heading' => 'Krav og terskler']),
            ],
        ]);

        $this->assertSame([], $this->validator()->findIssues($decision, [], ['paragraph-3']));
    }

    public function test_null_heading_is_one_deterministic_identity_not_a_wildcard(): void
    {
        $a = $this->target(['target_topic' => 'Terskelverdi', 'target_heading' => null]);
        $b = $this->target(['target_topic' => 'Terskelverdi', 'target_heading' => null]);
        $withHeading = $this->target(['target_topic' => 'Terskelverdi', 'target_heading' => 'Krav og terskler']);

        // Deterministic and stable across calls.
        $this->assertSame(
            EnterpriseWikiMaintainerDecisionPrompt::patchTargetIdentity($a),
            EnterpriseWikiMaintainerDecisionPrompt::patchTargetIdentity($b),
        );
        $this->assertStringEndsWith(
            EnterpriseWikiMaintainerDecisionPrompt::NO_HEADING_IDENTITY,
            EnterpriseWikiMaintainerDecisionPrompt::patchTargetIdentity($a),
        );
        // A named heading is a different identity than no heading.
        $this->assertNotSame(
            EnterpriseWikiMaintainerDecisionPrompt::patchTargetIdentity($a),
            EnterpriseWikiMaintainerDecisionPrompt::patchTargetIdentity($withHeading),
        );

        // Two headingless targets for the same topic remain indistinguishable, so still a duplicate.
        $joined = implode(' | ', $this->validator()->findIssues(
            $this->baseDecision(['patch_targets' => [$a, $b]]), [], ['paragraph-3'],
        ));

        $this->assertStringContainsString('both without a target_heading', $joined);
    }

    public function test_identity_ignores_case_whitespace_and_trailing_atx_hashes(): void
    {
        $this->assertSame(
            EnterpriseWikiMaintainerDecisionPrompt::patchTargetIdentity(
                $this->target(['target_topic' => 'Terskelverdi', 'target_heading' => 'Krav og terskler']),
            ),
            EnterpriseWikiMaintainerDecisionPrompt::patchTargetIdentity(
                $this->target(['target_topic' => '  terskelVERDI ', 'target_heading' => 'krav  og   terskler ##']),
            ),
        );
    }

    public function test_validator_and_merger_share_one_identity_definition(): void
    {
        // The regression guarded here is drift: the two used to carry their own, subtly different
        // keys, so the same rule could mean two things depending on which path a decision took.
        $targets = [
            $this->target(['target_topic' => 'Terskelverdi', 'target_heading' => 'Krav og terskler']),
            $this->target(['target_topic' => 'Terskelverdi', 'target_heading' => 'Krav og terskler for tjenesten']),
        ];

        $merged = (new EnterpriseWikiMaintainerDecisionMerger)->merge(
            [
                'source_article' => $this->sourcePage(),
                'source_summary' => $this->sourcePage(),
                'entity_pages' => [],
                'patch_targets' => $targets,
                'no_action_reason' => null,
                'warnings' => [],
            ],
            [],
        );

        // Validator keeps both, merger keeps both — same verdict from the same definition.
        $this->assertSame([], $this->validator()->findIssues($this->baseDecision(['patch_targets' => $targets]), [], ['paragraph-3']));
        $this->assertCount(2, $merged['patch_targets']);
    }

    public function test_a_page_cannot_be_preserved_and_changed_at_once(): void
    {
        $decision = $this->baseDecision([
            'patch_targets' => [
                $this->target(['target_topic' => 'Terskelverdi']),
                $this->target([
                    'target_topic' => 'Noe helt annet',
                    'operation' => 'preserve',
                    'relationship' => 'reference_only',
                    'superseded_substance' => null,
                    'replacement_substance' => null,
                    'source_element_keys' => [],
                ]),
            ],
        ]);

        $joined = implode(' | ', $this->validator()->findIssues($decision, [], ['paragraph-3']));

        $this->assertStringContainsString('preserved (left untouched) while also changing it', $joined);
    }

    // =========================================================================
    // The create-gate: `new` is never sufficient
    // =========================================================================

    public function test_create_is_refused_for_every_relationship_an_existing_page_owns(): void
    {
        // Only "independent_new_topic" may create. Each of these asserts that an existing page is
        // the natural home, so pairing it with create is a self-contradiction rather than a choice.
        foreach (['substance_changed', 'topic_extended', 'topic_specialized', 'reference_only'] as $relationship) {
            $decision = $this->baseDecision([
                'concept_candidates' => [$this->candidate([
                    'decision' => 'create',
                    'relationship' => $relationship,
                    'existing_owner_page_id' => $relationship === 'reference_only' ? null : 11,
                ])],
                'concept_pages' => [$this->page(['title' => 'Nytt Tema'])],
                'patch_targets' => [$this->target(['target_page_id' => 11])],
            ]);

            $joined = implode(' | ', $this->validator()->findIssues($decision, [], ['paragraph-3']));

            $this->assertStringContainsString(
                'requires relationship "independent_new_topic"',
                $joined,
                "create must be refused for relationship [{$relationship}]",
            );
        }
    }

    public function test_create_is_allowed_for_a_genuinely_independent_new_topic(): void
    {
        $decision = $this->baseDecision([
            'concept_candidates' => [$this->candidate([
                'decision' => 'create',
                'relationship' => 'independent_new_topic',
                'existing_owner_page_id' => null,
            ])],
            'concept_pages' => [$this->page(['title' => 'Selvstendig Tema'])],
        ]);

        $this->assertSame([], $this->validator()->findIssues($decision, [], ['paragraph-3']));
    }

    public function test_specialisation_remedy_points_at_the_broader_owner(): void
    {
        // New terminology plus new detail is still not independence — the remedy must say so, since
        // this is the exact judgement the observed run got wrong.
        $decision = $this->baseDecision([
            'concept_candidates' => [$this->candidate([
                'decision' => 'create',
                'relationship' => 'topic_specialized',
                'existing_owner_page_id' => 11,
            ])],
            'patch_targets' => [$this->target(['target_page_id' => 11])],
        ]);

        $joined = implode(' | ', $this->validator()->findIssues($decision, [], ['paragraph-3']));

        $this->assertStringContainsString('a variant or sub-topic belongs there', $joined);
    }

    public function test_relationship_asserting_an_existing_owner_must_name_it(): void
    {
        $decision = $this->baseDecision([
            'concept_candidates' => [$this->candidate([
                'decision' => 'reference_only',
                'relationship' => 'topic_extended',
                'existing_owner_page_id' => null,
                'owning_page_title' => 'Eksisterende Side',
            ])],
        ]);

        $joined = implode(' | ', $this->validator()->findIssues($decision));

        $this->assertStringContainsString('name that page in existing_owner_page_id', $joined);
    }

    public function test_independent_topic_must_not_name_an_existing_owner(): void
    {
        $decision = $this->baseDecision([
            'concept_candidates' => [$this->candidate([
                'decision' => 'create',
                'relationship' => 'independent_new_topic',
                'existing_owner_page_id' => 11,
            ])],
        ]);

        $joined = implode(' | ', $this->validator()->findIssues($decision));

        $this->assertStringContainsString('is not independent', $joined);
    }

    // =========================================================================
    // Duplicate canonical ownership
    // =========================================================================

    public function test_creating_a_canonical_page_under_an_existing_title_is_refused(): void
    {
        $decision = $this->baseDecision([
            'entity_pages' => [$this->page(['title' => 'Plattform Alfa', 'proposed_slug' => 'plattform-alfa-ny'])],
        ]);

        $joined = implode(' | ', $this->validator()->findIssues($decision, [
            ['id' => 55, 'title' => 'Plattform Alfa', 'page_type' => 'entity'],
        ]));

        $this->assertStringContainsString('never create a second owner for the same topic', $joined);
    }

    public function test_title_match_ignores_case_and_punctuation_drift(): void
    {
        $decision = $this->baseDecision([
            'concept_pages' => [$this->page(['title' => 'endringsstyring  (itsm)'])],
        ]);

        $this->assertNotEmpty($this->validator()->findIssues($decision, [
            ['id' => 52, 'title' => 'Endringsstyring (ITSM)', 'page_type' => 'concept'],
        ]));
    }

    public function test_updating_an_existing_canonical_page_by_id_is_not_duplicate_ownership(): void
    {
        $decision = $this->baseDecision([
            'concept_pages' => [$this->page([
                'action' => 'update',
                'page_id' => 52,
                'title' => 'Endringsstyring (ITSM)',
            ])],
        ]);

        $this->assertSame([], $this->validator()->findIssues($decision, [
            ['id' => 52, 'title' => 'Endringsstyring (ITSM)', 'page_type' => 'concept'],
        ]));
    }

    // =========================================================================
    // Document pages are not canonical owners
    // =========================================================================

    public function test_a_new_document_article_and_summary_remain_legal_when_all_substance_is_patched(): void
    {
        // The change-note case: every factual change belongs to existing pages, and the document
        // still legitimately gets its own article and summary. That is document representation, not
        // duplicate canonical ownership.
        $decision = $this->baseDecision([
            'patch_targets' => [
                $this->target(['target_page_id' => 11, 'target_page_type' => 'article']),
                $this->target(['target_page_id' => 12, 'target_page_type' => 'summary']),
            ],
            'concept_pages' => [],
            'entity_pages' => [],
        ]);

        $this->assertSame('create', $decision['source_article']['action']);
        $this->assertSame('create', $decision['source_summary']['action']);
        $this->assertSame([], EnterpriseWikiMaintainerDecisionPrompt::validate($decision));
        $this->assertSame([], $this->validator()->findIssues($decision, [
            ['id' => 11, 'title' => 'Styrende Prosedyre', 'page_type' => 'article'],
            ['id' => 12, 'title' => 'Sammendrag: Styrende Prosedyre', 'page_type' => 'summary'],
        ], ['paragraph-3']));
    }

    public function test_document_article_is_exempt_from_the_existing_title_gate(): void
    {
        // The create-gate deliberately applies to canonical knowledge pages only. A document article
        // resolves by canonical slug in the apply layer, and must not be blocked here.
        $decision = $this->baseDecision();
        $decision['source_article']['title'] = 'Styrende Prosedyre';

        $this->assertSame([], $this->validator()->findIssues($decision, [
            ['id' => 11, 'title' => 'Styrende Prosedyre', 'page_type' => 'article'],
        ]));
    }

    // =========================================================================
    // Warnings must not be the only channel for an actionable finding
    // =========================================================================

    public function test_identified_substance_change_must_become_a_target_not_a_warning(): void
    {
        // This is precisely the observed failure, as a test: the maintainer knows an existing page is
        // outdated and writes it in warnings instead of targeting it.
        $decision = $this->baseDecision([
            'concept_candidates' => [$this->candidate([
                'decision' => 'reference_only',
                'relationship' => 'substance_changed',
                'existing_owner_page_id' => 11,
            ])],
            'patch_targets' => [],
            'warnings' => ['Eksisterende side 11 oppgir fortsatt den gamle verdien og bor oppdateres senere.'],
        ]);

        $joined = implode(' | ', $this->validator()->findIssues($decision));

        $this->assertStringContainsString('must be a structured patch target, not a warning', $joined);
    }

    public function test_a_patch_target_needs_no_warning_to_be_expressible(): void
    {
        $decision = $this->baseDecision([
            'concept_candidates' => [$this->candidate([
                'decision' => 'reference_only',
                'relationship' => 'substance_changed',
                'existing_owner_page_id' => 11,
            ])],
            'patch_targets' => [$this->target(['target_page_id' => 11])],
            'warnings' => [],
        ]);

        $this->assertSame([], $this->validator()->findIssues($decision, [], ['paragraph-3']));
        $this->assertSame([], EnterpriseWikiMaintainerDecisionPrompt::parse($decision)['warnings']);
    }

    // =========================================================================
    // A patch target is never also a generated page for this run
    // =========================================================================

    public function test_page_cannot_be_both_a_patch_target_and_a_planned_page(): void
    {
        // The ambiguity behind the destructive rewrite: the page-entry route regenerates from the new
        // document alone, which would discard exactly what the patch target preserves.
        $decision = $this->baseDecision([
            'entity_pages' => [$this->page(['action' => 'update', 'page_id' => 11, 'title' => 'Plattform Alfa'])],
            'patch_targets' => [$this->target(['target_page_id' => 11, 'target_page_type' => 'entity'])],
        ]);

        $joined = implode(' | ', $this->validator()->findIssues($decision, [], ['paragraph-3']));

        $this->assertStringContainsString('never both', $joined);
    }

    // =========================================================================
    // Split flow
    // =========================================================================

    public function test_global_plan_and_candidate_batch_schemas_both_carry_patch_targets(): void
    {
        $globalPlan = EnterpriseWikiMaintainerDecisionPrompt::globalPlanSchema()['json_schema']['schema'];
        $batch = EnterpriseWikiMaintainerDecisionPrompt::candidateBatchSchema()['json_schema']['schema'];

        $this->assertContains('patch_targets', $globalPlan['required']);
        $this->assertContains('patch_targets', $batch['required']);
        $this->assertFalse($globalPlan['additionalProperties']);
        $this->assertFalse($batch['additionalProperties']);
    }

    public function test_merger_unions_patch_targets_from_both_phases(): void
    {
        $merged = (new EnterpriseWikiMaintainerDecisionMerger)->merge(
            [
                'source_article' => $this->sourcePage(),
                'source_summary' => $this->sourcePage(),
                'entity_pages' => [],
                'patch_targets' => [$this->target(['target_page_id' => 11, 'target_topic' => 'Terskelverdi'])],
                'no_action_reason' => null,
                'warnings' => [],
            ],
            [
                [
                    'concept_candidates' => [],
                    'concept_pages' => [],
                    // A batch discovers its own candidate's substance change; phase A could not.
                    'patch_targets' => [$this->target(['target_page_id' => 12, 'target_topic' => 'Frist'])],
                ],
            ],
        );

        $this->assertCount(2, $merged['patch_targets']);
        $this->assertSame([11, 12], array_column($merged['patch_targets'], 'target_page_id'));
    }

    public function test_merger_dedupes_a_batch_restating_the_same_page_topic_and_heading(): void
    {
        $merged = $this->mergeTargets(
            [$this->target(['target_page_id' => 11, 'target_topic' => 'Terskelverdi', 'target_heading' => 'Krav'])],
            [$this->target(['target_page_id' => 11, 'target_topic' => '  terskelverdi ', 'target_heading' => 'krav'])],
        );

        $this->assertCount(1, $merged);
    }

    public function test_merger_keeps_two_targets_for_the_same_topic_under_different_headings(): void
    {
        $merged = $this->mergeTargets(
            [$this->target(['target_page_id' => 11, 'target_topic' => 'Terskelverdi', 'target_heading' => 'Krav og terskler'])],
            [$this->target(['target_page_id' => 11, 'target_topic' => 'Terskelverdi', 'target_heading' => 'Krav og terskler for tjenesten'])],
        );

        $this->assertCount(2, $merged, 'a duplicated section on one page needs one target per occurrence');
        $this->assertSame(
            ['Krav og terskler', 'Krav og terskler for tjenesten'],
            array_column($merged, 'target_heading'),
        );
    }

    public function test_merger_dedupes_two_headingless_targets_for_the_same_topic(): void
    {
        $merged = $this->mergeTargets(
            [$this->target(['target_page_id' => 11, 'target_topic' => 'Terskelverdi', 'target_heading' => null])],
            [$this->target(['target_page_id' => 11, 'target_topic' => 'Terskelverdi', 'target_heading' => null])],
        );

        $this->assertCount(1, $merged);
    }

    public function test_merger_output_without_patch_targets_is_an_empty_list(): void
    {
        $merged = (new EnterpriseWikiMaintainerDecisionMerger)->merge(
            [
                'source_article' => $this->sourcePage(),
                'source_summary' => $this->sourcePage(),
                'entity_pages' => [],
                'no_action_reason' => null,
                'warnings' => [],
            ],
            [],
        );

        $this->assertSame([], $merged['patch_targets']);
    }

    // =========================================================================
    // Prompt text — the rules the model is actually told
    // =========================================================================

    public function test_prompt_states_the_create_gate_and_the_granularity_rule(): void
    {
        $rules = implode("\n", EnterpriseWikiMaintainerDecisionAiClient::patchTargetRules());

        $this->assertStringContainsString('patch_targets', $rules);
        $this->assertStringContainsString('A new page is NOT justified by the topic being new', $rules);
        $this->assertStringContainsString('ALLOWED ONLY with relationship "independent_new_topic"', $rules);
        $this->assertStringContainsString('New terminology is not independence', $rules);
        $this->assertStringContainsString('MULTIPLE TARGETS ARE EXPECTED', $rules);
        $this->assertStringContainsString('NEVER put an actionable change into warnings', $rules);
        $this->assertStringContainsString('DOCUMENT PAGES ARE NOT CANONICAL OWNERS', $rules);
        $this->assertStringContainsString('preserve_topics', $rules);
    }

    // =========================================================================
    // preserve_topics is target-local, and absence is never permission to delete
    // =========================================================================

    // =========================================================================
    // superseded_substance means ONE thing in every layer (runs 28 + 29)
    // =========================================================================

    public function test_the_contract_defines_superseded_substance_as_an_exact_substring(): void
    {
        $doc = (string) (new \ReflectionClass(EnterpriseWikiMaintainerDecisionPrompt::class))->getDocComment()
            .$this->patchTargetSchemaDocComment();

        $this->assertStringContainsString('EXACT, VERBATIM SUBSTRING', $doc);
        $this->assertStringContainsString('does NOT have to be a whole sentence', $doc);
        $this->assertStringContainsString('unique within that area', $doc);
    }

    public function test_the_decision_prompt_demands_an_exact_copy_not_a_description(): void
    {
        // Run 29's root contract defect: the decision prompt asked the maintainer to "state" the
        // substance while the validator and engine demanded an exact substring, so the mistake was
        // reintroduced on every single run.
        $rules = implode("\n", EnterpriseWikiMaintainerDecisionAiClient::patchTargetRules());

        $this->assertStringContainsString('COPY AN EXACT SUBSTRING', $rules);
        $this->assertStringContainsString('Do NOT', $rules);
        $this->assertStringContainsString('paraphrase', $rules);
        $this->assertStringContainsString('does not have to be a whole sentence', $rules);
        $this->assertStringContainsString('occurs exactly once', $rules);
        $this->assertStringContainsString('CLAUSE that continues', $rules, 'the observed failure mode must be named');
    }

    public function test_the_contract_wording_stays_domain_free(): void
    {
        $rules = mb_strtolower(implode("\n", EnterpriseWikiMaintainerDecisionAiClient::patchTargetRules()));

        foreach (['aurora', 'itsm', '99,5', '99.5', 'fjellglimt', 'prosent'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $rules, "prompt must not hardcode [{$forbidden}]");
        }
    }

    public function test_prompt_states_preserve_topics_is_target_local(): void
    {
        $rules = implode("\n", EnterpriseWikiMaintainerDecisionAiClient::patchTargetRules());

        $this->assertStringContainsString('preserve_topics: TARGET-LOCAL', $rules);
        $this->assertStringContainsString('Do NOT list unrelated sections elsewhere on the page', $rules);
        $this->assertStringContainsString('outside this target\'s area is preserved by default', $rules);
        $this->assertStringContainsString('never permission to delete it', $rules);
    }

    public function test_prompt_states_the_same_topic_may_repeat_under_different_headings(): void
    {
        $rules = implode("\n", EnterpriseWikiMaintainerDecisionAiClient::patchTargetRules());

        $this->assertStringContainsString('under DIFFERENT headings', $rules);
        $this->assertStringContainsString('each occurrence needs its own target', $rules);
        $this->assertStringContainsString('same topic under the SAME heading is a duplicate', $rules);
    }

    public function test_contract_documents_preserve_topics_as_local_with_implicit_page_wide_default(): void
    {
        // The guarantee 8K-3 will build on has to be written down where the contract lives, not only
        // in the prompt: local list = explicit protection inside the target area; everything beyond
        // the target area = preserved by default.
        $doc = (string) (new \ReflectionClass(EnterpriseWikiMaintainerDecisionPrompt::class))->getDocComment()
            .$this->patchTargetSchemaDocComment();

        $this->assertStringContainsString('TARGET-LOCAL', $doc);
        $this->assertStringContainsString('preserved BY DEFAULT', $doc);
        $this->assertStringContainsString('NEVER permission to delete', $doc);
    }

    public function test_an_amend_target_needs_no_page_wide_preserve_list(): void
    {
        // A target that protects only its own local neighbours must validate: requiring a page-wide
        // enumeration would make every real decision fail.
        $decision = $this->baseDecision([
            'patch_targets' => [$this->target([
                'operation' => 'amend',
                'relationship' => 'topic_extended',
                'superseded_substance' => null,
                'replacement_substance' => 'Ny presisering innenfor samme tema.',
                'preserve_topics' => ['Bare naboen i samme seksjon'],
            ])],
        ]);

        $this->assertSame([], EnterpriseWikiMaintainerDecisionPrompt::validate($decision));
        $this->assertSame([], $this->validator()->findIssues($decision, [], ['paragraph-3']));
    }

    public function test_empty_preserve_topics_is_valid_and_not_a_deletion_licence(): void
    {
        $decision = $this->baseDecision([
            'patch_targets' => [$this->target(['preserve_topics' => []])],
        ]);

        $this->assertSame([], EnterpriseWikiMaintainerDecisionPrompt::validate($decision));
        $this->assertSame([], $this->validator()->findIssues($decision, [], ['paragraph-3']));
    }

    public function test_prompt_rules_are_domain_free(): void
    {
        $rules = mb_strtolower(implode("\n", EnterpriseWikiMaintainerDecisionAiClient::patchTargetRules()));

        // No subject matter, no customer, no threshold from any observed document.
        foreach (['aurora', 'itsm', 'itil', 'fjellglimt', '99,5', '99.5', 'p1-hendelse', 'sla'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $rules, "prompt must not hardcode [{$forbidden}]");
        }
    }

    // =========================================================================
    // Fixtures — deliberately generic
    // =========================================================================

    private function validator(): EnterpriseWikiCanonicalOwnershipValidator
    {
        return new EnterpriseWikiCanonicalOwnershipValidator;
    }

    /** The patch-target schema's own docblock, where the preserve semantics are recorded. */
    private function patchTargetSchemaDocComment(): string
    {
        $method = new \ReflectionMethod(EnterpriseWikiMaintainerDecisionPrompt::class, 'patchTargetSchema');
        $method->setAccessible(true);

        return (string) $method->getDocComment();
    }

    /**
     * Merge one Phase A target list with one batch's target list and return the merged targets.
     *
     * @param  list<array<string, mixed>>  $globalTargets
     * @param  list<array<string, mixed>>  $batchTargets
     * @return list<array<string, mixed>>
     */
    private function mergeTargets(array $globalTargets, array $batchTargets): array
    {
        return (new EnterpriseWikiMaintainerDecisionMerger)->merge(
            [
                'source_article' => $this->sourcePage(),
                'source_summary' => $this->sourcePage(),
                'entity_pages' => [],
                'patch_targets' => $globalTargets,
                'no_action_reason' => null,
                'warnings' => [],
            ],
            [[
                'concept_candidates' => [],
                'concept_pages' => [],
                'patch_targets' => $batchTargets,
            ]],
        )['patch_targets'];
    }

    /** @return string[] */
    private function validateTarget(array $target): array
    {
        return array_values(array_filter(
            EnterpriseWikiMaintainerDecisionPrompt::validate($this->baseDecision(['patch_targets' => [$target]])),
        ));
    }

    /** @return array<string, mixed> */
    private function baseDecision(array $overrides = []): array
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
            'owned_topics' => ['Hva dokumentet selv beslutter'],
            'reference_only_topics' => [],
            'excluded_topics' => [],
            'related_page_guidance' => [],
            'planned_figures' => [],
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function page(array $overrides = []): array
    {
        return array_merge([
            'action' => 'create',
            'page_id' => null,
            'title' => 'Nytt Tema',
            'proposed_slug' => 'nytt-tema',
            'reason' => 'Selvstendig tema.',
            'owned_topics' => ['Temaet selv'],
            'reference_only_topics' => [],
            'excluded_topics' => [],
            'related_page_guidance' => [],
            'planned_figures' => [],
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function candidate(array $overrides = [], bool $dropGranularity = false): array
    {
        $candidate = array_merge([
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
        ], $overrides);

        if ($dropGranularity) {
            unset($candidate['relationship'], $candidate['existing_owner_page_id']);
        }

        return $candidate;
    }

    /** @return array<string, mixed> */
    private function target(array $overrides = []): array
    {
        return array_merge([
            'target_page_id' => 11,
            'target_page_title' => 'Styrende Prosedyre',
            'target_page_type' => 'article',
            'target_topic' => 'Terskelverdi',
            'target_heading' => null,
            'relationship' => 'substance_changed',
            'operation' => 'replace',
            'superseded_substance' => 'Terskelverdien er 99 enheter.',
            'replacement_substance' => 'Terskelverdien er 120 enheter fra og med neste periode.',
            'source_element_keys' => ['paragraph-3'],
            'preserve_topics' => [],
            'reason' => 'Kilden erstatter uttrykkelig terskelverdien.',
        ], $overrides);
    }
}
