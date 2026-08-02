<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Services\EnterpriseWiki\EnterpriseWikiMaintainerDecisionPrompt;
use PHPUnit\Framework\TestCase;

class EnterpriseWikiMaintainerDecisionPromptTest extends TestCase
{
    // =========================================================================
    // JSON schema
    // =========================================================================

    public function test_schema_requires_source_article(): void
    {
        $required = $this->schemaRequired();
        $this->assertContains('source_article', $required);
    }

    public function test_schema_requires_source_summary(): void
    {
        $required = $this->schemaRequired();
        $this->assertContains('source_summary', $required);
    }

    public function test_schema_is_strict_json_schema(): void
    {
        $schema = EnterpriseWikiMaintainerDecisionPrompt::jsonSchema();
        $this->assertSame('json_schema', $schema['type']);
        $this->assertTrue($schema['json_schema']['strict']);
        $this->assertFalse($schema['json_schema']['schema']['additionalProperties']);
    }

    public function test_schema_source_article_does_not_have_page_id(): void
    {
        $props = $this->schemaProps()['source_article']['properties'];
        $this->assertArrayNotHasKey('page_id', $props);
    }

    public function test_schema_concept_pages_items_have_nullable_page_id(): void
    {
        $items = $this->schemaProps()['concept_pages']['items'];
        $this->assertContains('page_id', $items['required']);
        $this->assertContains('null', $items['properties']['page_id']['type']);
    }

    public function test_schema_requires_responsibility_fields_on_source_article(): void
    {
        $sourceArticleSchema = $this->schemaProps()['source_article'];

        foreach (['owned_topics', 'reference_only_topics', 'excluded_topics', 'related_page_guidance'] as $field) {
            $this->assertContains($field, $sourceArticleSchema['required']);
            $this->assertArrayHasKey($field, $sourceArticleSchema['properties']);
        }
    }

    public function test_schema_requires_responsibility_fields_on_concept_page_items(): void
    {
        $items = $this->schemaProps()['concept_pages']['items'];

        foreach (['owned_topics', 'reference_only_topics', 'excluded_topics', 'related_page_guidance'] as $field) {
            $this->assertContains($field, $items['required']);
        }
    }

    public function test_schema_related_page_guidance_items_require_page_title_and_relationship(): void
    {
        $relatedPageGuidanceItems = $this->schemaProps()['source_article']['properties']['related_page_guidance']['items'];

        $this->assertSame(['page_title', 'relationship'], $relatedPageGuidanceItems['required']);
    }

    // =========================================================================
    // JSON schema — planned_figures (Wiki run-587 fix: figures extracted/classified/citable but
    // never explicitly planned onto any page)
    // =========================================================================

    public function test_schema_requires_planned_figures_on_source_article(): void
    {
        $sourceArticleSchema = $this->schemaProps()['source_article'];

        $this->assertContains('planned_figures', $sourceArticleSchema['required']);
        $this->assertArrayHasKey('planned_figures', $sourceArticleSchema['properties']);
    }

    public function test_schema_requires_planned_figures_on_concept_page_items(): void
    {
        $items = $this->schemaProps()['concept_pages']['items'];

        $this->assertContains('planned_figures', $items['required']);
        $this->assertArrayHasKey('planned_figures', $items['properties']);
    }

    public function test_schema_planned_figures_items_require_all_six_fields(): void
    {
        $plannedFigureItems = $this->schemaProps()['source_article']['properties']['planned_figures']['items'];

        foreach (['source_element_key', 'classification', 'section_placement', 'purpose', 'required', 'caption_hint'] as $field) {
            $this->assertContains($field, $plannedFigureItems['required']);
            $this->assertArrayHasKey($field, $plannedFigureItems['properties']);
        }
    }

    // =========================================================================
    // JSON schema — concept_candidates (Wiki run-581 fix: explicit, structural per-concept
    // decision so "the AI silently didn't propose a page" becomes a checkable claim)
    // =========================================================================

    public function test_schema_requires_concept_candidates(): void
    {
        $this->assertContains('concept_candidates', $this->schemaRequired());
    }

    public function test_schema_concept_candidate_items_require_all_nine_fields(): void
    {
        $items = $this->schemaProps()['concept_candidates']['items'];

        foreach ([
            'name', 'concept_type', 'independent_reason', 'mentioned_context',
            'existing_page_title', 'decision', 'justification', 'owning_page_title',
            'necessary_for_article',
        ] as $field) {
            $this->assertContains($field, $items['required']);
            $this->assertArrayHasKey($field, $items['properties']);
        }
    }

    public function test_schema_concept_candidate_decision_enum_matches_constant(): void
    {
        $items = $this->schemaProps()['concept_candidates']['items'];

        $this->assertSame(
            EnterpriseWikiMaintainerDecisionPrompt::CONCEPT_CANDIDATE_DECISIONS,
            $items['properties']['decision']['enum'],
        );
    }

    public function test_schema_concept_candidate_existing_and_owning_page_title_are_nullable(): void
    {
        $items = $this->schemaProps()['concept_candidates']['items'];

        $this->assertContains('null', $items['properties']['existing_page_title']['type']);
        $this->assertContains('null', $items['properties']['owning_page_title']['type']);
    }

    // =========================================================================
    // validate() — structure
    // =========================================================================

    public function test_missing_source_article_returns_error(): void
    {
        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate(
            array_merge($this->validDecision(), ['source_article' => null])
        );
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('source_article', $errors[0]);
    }

    public function test_missing_source_summary_returns_error(): void
    {
        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate(
            array_merge($this->validDecision(), ['source_summary' => null])
        );
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('source_summary', $errors[0]);
    }

    public function test_concept_pages_can_be_empty_array(): void
    {
        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate(
            array_merge($this->validDecision(), ['concept_pages' => []])
        );
        $this->assertEmpty($errors);
    }

    public function test_entity_pages_can_be_empty_array(): void
    {
        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate(
            array_merge($this->validDecision(), ['entity_pages' => []])
        );
        $this->assertEmpty($errors);
    }

    public function test_concept_pages_absent_is_not_an_error(): void
    {
        $decision = $this->validDecision();
        unset($decision['concept_pages'], $decision['entity_pages']);

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertEmpty($errors);
    }

    // =========================================================================
    // validate() — page responsibility fields (owned_topics / reference_only_topics /
    // excluded_topics / related_page_guidance) — required in the OpenAI schema (strict-mode
    // constraint) but deliberately optional in the PHP validator, exactly like
    // concept_pages/entity_pages/warnings, so a decision predating this field (or a hand-built
    // fixture) stays valid.
    // =========================================================================

    public function test_responsibility_fields_absent_is_not_an_error(): void
    {
        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($this->validDecision());
        $this->assertEmpty($errors);
    }

    public function test_owned_topics_with_valid_strings_is_accepted(): void
    {
        $decision = $this->validDecision();
        $decision['source_article']['owned_topics'] = ['Definer ITIL som rammeverk.', 'Forklar sentrale prinsipper.'];

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertEmpty($errors);
    }

    public function test_owned_topics_with_non_string_item_is_rejected(): void
    {
        $decision = $this->validDecision();
        $decision['source_article']['owned_topics'] = [123];

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('owned_topics', implode(' ', $errors));
    }

    public function test_owned_topics_with_empty_string_item_is_rejected(): void
    {
        $decision = $this->validDecision();
        $decision['source_article']['owned_topics'] = [''];

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertNotEmpty($errors);
    }

    public function test_reference_only_topics_can_be_an_empty_array(): void
    {
        $decision = $this->validDecision();
        $decision['source_article']['reference_only_topics'] = [];

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertEmpty($errors);
    }

    public function test_reference_only_topics_with_non_array_value_is_rejected(): void
    {
        $decision = $this->validDecision();
        $decision['source_article']['reference_only_topics'] = 'not an array';

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('reference_only_topics', implode(' ', $errors));
    }

    public function test_excluded_topics_can_be_an_empty_array(): void
    {
        $decision = $this->validDecision();
        $decision['source_article']['excluded_topics'] = [];

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertEmpty($errors);
    }

    public function test_excluded_topics_with_valid_strings_is_accepted(): void
    {
        $decision = $this->validDecision();
        $decision['source_article']['excluded_topics'] = [
            'Detaljerte KPI-kataloger.',
            'Full beskrivelse av Problem Management.',
        ];

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertEmpty($errors);
    }

    public function test_excluded_topics_with_non_array_value_is_rejected(): void
    {
        $decision = $this->validDecision();
        $decision['source_article']['excluded_topics'] = 'not an array';

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('excluded_topics', implode(' ', $errors));
    }

    public function test_excluded_topics_with_empty_string_item_is_rejected(): void
    {
        $decision = $this->validDecision();
        $decision['source_article']['excluded_topics'] = [''];

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('excluded_topics', implode(' ', $errors));
    }

    public function test_related_page_guidance_with_valid_entry_is_accepted(): void
    {
        $decision = $this->validDecision();
        $decision['source_article']['related_page_guidance'] = [
            ['page_title' => 'ITIL', 'relationship' => 'Lenk hit for rammeverksforklaring.'],
        ];

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertEmpty($errors);
    }

    public function test_related_page_guidance_missing_page_title_is_rejected(): void
    {
        $decision = $this->validDecision();
        $decision['source_article']['related_page_guidance'] = [
            ['relationship' => 'Lenk hit for rammeverksforklaring.'],
        ];

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('page_title', implode(' ', $errors));
    }

    public function test_related_page_guidance_missing_relationship_is_rejected(): void
    {
        $decision = $this->validDecision();
        $decision['source_article']['related_page_guidance'] = [
            ['page_title' => 'ITIL'],
        ];

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('relationship', implode(' ', $errors));
    }

    public function test_related_page_guidance_non_object_entry_is_rejected(): void
    {
        $decision = $this->validDecision();
        $decision['source_article']['related_page_guidance'] = ['not an object'];

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertNotEmpty($errors);
    }

    public function test_planned_figures_with_valid_entry_is_accepted(): void
    {
        $decision = $this->validDecision();
        $decision['source_article']['planned_figures'] = [
            [
                'source_element_key' => 'img1',
                'classification' => 'diagram',
                'section_placement' => 'Roller',
                'purpose' => 'Viser styringsmodellen.',
                'required' => true,
                'caption_hint' => 'Styringsmodell',
            ],
        ];

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertEmpty($errors);
    }

    public function test_planned_figures_missing_source_element_key_is_rejected(): void
    {
        $decision = $this->validDecision();
        $decision['source_article']['planned_figures'] = [
            [
                'source_element_key' => '',
                'classification' => 'diagram',
                'section_placement' => null,
                'purpose' => 'Viser styringsmodellen.',
                'required' => true,
                'caption_hint' => null,
            ],
        ];

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('source_element_key', implode(' ', $errors));
    }

    public function test_planned_figures_non_boolean_required_is_rejected(): void
    {
        $decision = $this->validDecision();
        $decision['source_article']['planned_figures'] = [
            [
                'source_element_key' => 'img1',
                'classification' => 'diagram',
                'section_placement' => null,
                'purpose' => 'Viser styringsmodellen.',
                'required' => 'yes',
                'caption_hint' => null,
            ],
        ];

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('required', implode(' ', $errors));
    }

    public function test_planned_figures_null_section_placement_and_caption_hint_are_accepted(): void
    {
        $decision = $this->validDecision();
        $decision['source_article']['planned_figures'] = [
            [
                'source_element_key' => 'img1',
                'classification' => 'diagram',
                'section_placement' => null,
                'purpose' => 'Viser styringsmodellen.',
                'required' => false,
                'caption_hint' => null,
            ],
        ];

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertEmpty($errors);
    }

    public function test_planned_figures_non_array_value_is_rejected(): void
    {
        $decision = $this->validDecision();
        $decision['source_article']['planned_figures'] = 'not an array';

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('planned_figures', implode(' ', $errors));
    }

    public function test_planned_figures_absent_is_not_an_error(): void
    {
        $decision = $this->validDecision();
        unset($decision['source_article']['planned_figures']);

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertEmpty($errors);
    }

    public function test_control_character_in_excluded_topics_item_is_rejected(): void
    {
        $decision = $this->validDecision();
        $decision['source_article']['excluded_topics'] = ["Detaljert \x0Fflyt."];

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('control character', implode(' ', $errors));
    }

    public function test_responsibility_fields_on_concept_page_entry_are_validated_too(): void
    {
        $decision = $this->validDecision();
        $decision['concept_pages'] = [[
            'action' => 'create',
            'page_id' => null,
            'title' => 'ITIL',
            'proposed_slug' => 'itil',
            'reason' => 'Overordnet rammeverk.',
            'owned_topics' => ['Definer ITIL som rammeverk for tjenestestyring.'],
            'reference_only_topics' => ['Bruk av prosessillustrasjonen.'],
            'excluded_topics' => ['Detaljert Incident Management-arbeidsflyt.', 'Detaljerte KPI-kataloger.'],
            'related_page_guidance' => [
                ['page_title' => 'Incident Management', 'relationship' => 'Lenk hit for detaljert hendelseshåndtering.'],
            ],
        ]];

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertEmpty($errors);
    }

    // =========================================================================
    // validate() — concept_candidates
    // =========================================================================

    public function test_concept_candidates_absent_is_not_an_error(): void
    {
        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($this->validDecision());
        $this->assertEmpty($errors);
    }

    public function test_concept_candidates_can_be_empty_array(): void
    {
        $decision = $this->validDecision();
        $decision['concept_candidates'] = [];

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertEmpty($errors);
    }

    public function test_valid_concept_candidate_entry_is_accepted(): void
    {
        $decision = $this->validDecision();
        $decision['concept_candidates'] = [$this->validConceptCandidate()];

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertEmpty($errors);
    }

    public function test_concept_candidate_missing_name_is_rejected(): void
    {
        $decision = $this->validDecision();
        $candidate = $this->validConceptCandidate();
        unset($candidate['name']);
        $decision['concept_candidates'] = [$candidate];

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('name', implode(' ', $errors));
    }

    public function test_concept_candidate_missing_justification_is_rejected(): void
    {
        $decision = $this->validDecision();
        $candidate = $this->validConceptCandidate();
        unset($candidate['justification']);
        $decision['concept_candidates'] = [$candidate];

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('justification', implode(' ', $errors));
    }

    public function test_concept_candidate_invalid_decision_value_is_rejected(): void
    {
        $decision = $this->validDecision();
        $candidate = $this->validConceptCandidate();
        $candidate['decision'] = 'maybe';
        $decision['concept_candidates'] = [$candidate];

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('decision', implode(' ', $errors));
    }

    public function test_concept_candidate_null_existing_and_owning_page_title_is_accepted(): void
    {
        $decision = $this->validDecision();
        $candidate = $this->validConceptCandidate();
        $candidate['existing_page_title'] = null;
        $candidate['owning_page_title'] = null;
        $decision['concept_candidates'] = [$candidate];

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertEmpty($errors);
    }

    public function test_concept_candidate_non_boolean_necessary_for_article_is_rejected(): void
    {
        $decision = $this->validDecision();
        $candidate = $this->validConceptCandidate();
        $candidate['necessary_for_article'] = 'yes';
        $decision['concept_candidates'] = [$candidate];

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('necessary_for_article', implode(' ', $errors));
    }

    public function test_parse_normalises_missing_concept_candidates_to_empty_array(): void
    {
        $parsed = EnterpriseWikiMaintainerDecisionPrompt::parse($this->validDecision());
        $this->assertSame([], $parsed['concept_candidates']);
    }

    public function test_parse_preserves_concept_candidates_when_present(): void
    {
        $decision = $this->validDecision();
        $decision['concept_candidates'] = [$this->validConceptCandidate()];

        $parsed = EnterpriseWikiMaintainerDecisionPrompt::parse($decision);
        $this->assertSame('ITIL Incident Management', $parsed['concept_candidates'][0]['name']);
    }

    // =========================================================================
    // validate() — actions
    // =========================================================================

    public function test_create_action_requires_title_and_proposed_slug(): void
    {
        $decision = $this->validDecision();
        $decision['source_article'] = ['action' => 'create', 'reason' => 'ok'];

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $errorText = implode(' ', $errors);
        $this->assertStringContainsString('title', $errorText);
        $this->assertStringContainsString('proposed_slug', $errorText);
    }

    public function test_invalid_action_value_returns_error(): void
    {
        $decision = $this->validDecision();
        $decision['source_article']['action'] = 'delete';

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('action', $errors[0]);
    }

    // =========================================================================
    // validate() — update + page_id
    // =========================================================================

    public function test_update_action_on_shared_page_with_valid_page_id_is_valid(): void
    {
        $decision = $this->validDecision();
        $decision['concept_pages'] = [
            [
                'action' => 'update',
                'page_id' => 42,
                'title' => 'Masterdatastyring',
                'proposed_slug' => 'masterdatastyring',
                'reason' => 'Expanding existing concept page.',
            ],
        ];

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertEmpty($errors);
    }

    public function test_update_action_without_page_id_returns_error(): void
    {
        $decision = $this->validDecision();
        $decision['concept_pages'] = [
            [
                'action' => 'update',
                'page_id' => null,
                'title' => 'Masterdatastyring',
                'proposed_slug' => 'masterdatastyring',
                'reason' => 'Should fail — update needs a page_id.',
            ],
        ];

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('page_id', implode(' ', $errors));
    }

    public function test_create_action_with_null_page_id_is_valid(): void
    {
        $decision = $this->validDecision();
        $decision['entity_pages'] = [
            [
                'action' => 'create',
                'page_id' => null,
                'title' => 'Statsbygg',
                'proposed_slug' => 'statsbygg',
                'reason' => 'New entity page.',
            ],
        ];

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertEmpty($errors);
    }

    // =========================================================================
    // validate() — file extension rules
    // =========================================================================

    public function test_file_extension_in_proposed_slug_is_rejected(): void
    {
        $decision = $this->validDecision();
        $decision['source_article']['proposed_slug'] = 'selskapsinfo-pdf';

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('file extension', implode(' ', $errors));
    }

    public function test_dot_extension_in_proposed_slug_is_rejected(): void
    {
        $decision = $this->validDecision();
        $decision['source_article']['proposed_slug'] = 'selskapsinfo.pdf';

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('file extension', implode(' ', $errors));
    }

    public function test_file_extension_in_title_is_rejected(): void
    {
        $decision = $this->validDecision();
        $decision['source_article']['title'] = 'Masterdata Prosjekt.docx';

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('file extension', implode(' ', $errors));
    }

    public function test_title_without_extension_is_accepted(): void
    {
        $decision = $this->validDecision();
        $decision['source_article']['title'] = 'Masterdata Prosjekt';

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertEmpty($errors);
    }

    // =========================================================================
    // validate() — control-character / invalid-UTF-8 rejection (Wiki run-34 fix:
    // page 187's title was persisted from a maintainer decision containing a raw control byte
    // and a malformed Unicode escape where a Norwegian diacritic should have been)
    // =========================================================================

    public function test_title_with_norwegian_diacritics_is_accepted(): void
    {
        $decision = $this->validDecision();
        $decision['source_article']['title'] = 'Rød/Gul/Grønn-klassifiseringsmodell for applikasjoner';

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertEmpty($errors);
    }

    public function test_title_with_raw_control_character_is_rejected(): void
    {
        $decision = $this->validDecision();
        $decision['source_article']['title'] = "R\x0Fd/Gul/Gr";

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('control character', implode(' ', $errors));
    }

    public function test_proposed_slug_with_control_character_is_rejected(): void
    {
        $decision = $this->validDecision();
        $decision['source_article']['proposed_slug'] = "rod-gul-gr\x0Fnn";

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('control character', implode(' ', $errors));
    }

    public function test_reason_with_invalid_utf8_is_rejected(): void
    {
        $decision = $this->validDecision();
        // A lone continuation byte (0x80) is not valid standalone UTF-8.
        $decision['source_article']['reason'] = "Invalid \x80 byte sequence.";

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('not valid UTF-8', implode(' ', $errors));
    }

    public function test_control_character_in_concept_page_title_is_rejected(): void
    {
        $decision = $this->validDecision();
        $decision['concept_pages'] = [[
            'action' => 'create',
            'page_id' => null,
            'title' => "Tilgangsstyring \x0Fperiodisk",
            'proposed_slug' => 'tilgangsstyring-periodisk',
            'reason' => 'Concept page for periodic access review.',
        ]];

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('control character', implode(' ', $errors));
    }

    public function test_ordinary_whitespace_and_newlines_in_reason_are_accepted(): void
    {
        $decision = $this->validDecision();
        $decision['source_article']['reason'] = "Line one.\nLine two.\tTabbed.";

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validate($decision);
        $this->assertEmpty($errors);
    }

    // =========================================================================
    // parse()
    // =========================================================================

    public function test_valid_decision_parses_without_exception(): void
    {
        $parsed = EnterpriseWikiMaintainerDecisionPrompt::parse($this->validDecision());
        $this->assertArrayHasKey('source_article', $parsed);
        $this->assertArrayHasKey('source_summary', $parsed);
    }

    public function test_parse_normalises_missing_optional_keys(): void
    {
        $decision = $this->validDecision();
        unset($decision['concept_pages'], $decision['entity_pages'], $decision['warnings'], $decision['no_action_reason']);

        $parsed = EnterpriseWikiMaintainerDecisionPrompt::parse($decision);
        $this->assertSame([], $parsed['concept_pages']);
        $this->assertSame([], $parsed['entity_pages']);
        $this->assertSame([], $parsed['warnings']);
        $this->assertNull($parsed['no_action_reason']);
    }

    public function test_parse_preserves_responsibility_fields_when_present(): void
    {
        $decision = $this->validDecision();
        $decision['source_article']['owned_topics'] = ['Definer ITIL som rammeverk.'];
        $decision['source_article']['reference_only_topics'] = ['Bruk av illustrasjonen.'];
        $decision['source_article']['excluded_topics'] = ['Detaljert Incident Management-flyt.'];
        $decision['source_article']['related_page_guidance'] = [
            ['page_title' => 'Incident Management', 'relationship' => 'Lenk hit.'],
        ];

        $parsed = EnterpriseWikiMaintainerDecisionPrompt::parse($decision);

        $this->assertSame(['Definer ITIL som rammeverk.'], $parsed['source_article']['owned_topics']);
        $this->assertSame(['Bruk av illustrasjonen.'], $parsed['source_article']['reference_only_topics']);
        $this->assertSame(['Detaljert Incident Management-flyt.'], $parsed['source_article']['excluded_topics']);
        $this->assertSame('Incident Management', $parsed['source_article']['related_page_guidance'][0]['page_title']);
    }

    public function test_parse_throws_on_invalid_decision(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid maintainer decision/');

        EnterpriseWikiMaintainerDecisionPrompt::parse(['source_article' => null]);
    }

    // =========================================================================
    // No network calls (structural guarantee — no HTTP client is touched)
    // =========================================================================

    public function test_json_schema_and_validate_make_no_network_calls(): void
    {
        // If any network call were made, the test would hang or throw.
        EnterpriseWikiMaintainerDecisionPrompt::jsonSchema();
        EnterpriseWikiMaintainerDecisionPrompt::validate($this->validDecision());
        $this->addToAssertionCount(1);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function validDecision(): array
    {
        return [
            'source_article' => [
                'action' => 'create',
                'title' => 'Masterdata Prosjekt',
                'proposed_slug' => 'masterdata-prosjekt',
                'reason' => 'Source document introduces project scope not yet in the wiki.',
            ],
            'source_summary' => [
                'action' => 'create',
                'title' => 'Sammendrag: Masterdata Prosjekt',
                'proposed_slug' => 'sammendrag-masterdata-prosjekt',
                'reason' => 'Companion summary for the source article.',
            ],
            'concept_pages' => [],
            'entity_pages' => [],
            'no_action_reason' => null,
            'warnings' => [],
        ];
    }

    // =========================================================================
    // Split flow — Phase A: globalPlanSchema() / validateGlobalPlan() / parseGlobalPlan()
    // =========================================================================

    public function test_global_plan_schema_is_strict_json_schema(): void
    {
        $schema = EnterpriseWikiMaintainerDecisionPrompt::globalPlanSchema();

        $this->assertSame('json_schema', $schema['type']);
        $this->assertTrue($schema['json_schema']['strict']);
        $this->assertFalse($schema['json_schema']['schema']['additionalProperties']);
    }

    public function test_global_plan_schema_requires_source_article_summary_entity_pages_and_mentions(): void
    {
        $required = EnterpriseWikiMaintainerDecisionPrompt::globalPlanSchema()['json_schema']['schema']['required'];

        foreach (['source_article', 'source_summary', 'entity_pages', 'concept_candidate_mentions'] as $field) {
            $this->assertContains($field, $required);
        }
    }

    public function test_global_plan_schema_does_not_include_concept_pages_or_full_candidates(): void
    {
        $props = EnterpriseWikiMaintainerDecisionPrompt::globalPlanSchema()['json_schema']['schema']['properties'];

        $this->assertArrayNotHasKey('concept_pages', $props);
        $this->assertArrayNotHasKey('concept_candidates', $props);
    }

    public function test_global_plan_schema_mention_items_are_minimal(): void
    {
        $props = EnterpriseWikiMaintainerDecisionPrompt::globalPlanSchema()['json_schema']['schema']['properties'];
        $mentionSchema = $props['concept_candidate_mentions']['items'];

        $this->assertSame(['name', 'concept_type', 'mentioned_context'], $mentionSchema['required']);
        $this->assertArrayNotHasKey('decision', $mentionSchema['properties']);
        $this->assertArrayNotHasKey('justification', $mentionSchema['properties']);
    }

    public function test_global_plan_schema_reuses_the_same_source_page_shape_as_the_full_schema(): void
    {
        $fullProps = $this->schemaProps();
        $globalProps = EnterpriseWikiMaintainerDecisionPrompt::globalPlanSchema()['json_schema']['schema']['properties'];

        $this->assertSame($fullProps['source_article'], $globalProps['source_article']);
        $this->assertSame($fullProps['entity_pages']['items'], $globalProps['entity_pages']['items']);
    }

    public function test_valid_global_plan_parses_without_exception(): void
    {
        $parsed = EnterpriseWikiMaintainerDecisionPrompt::parseGlobalPlan($this->validGlobalPlan());

        $this->assertArrayHasKey('source_article', $parsed);
        $this->assertArrayHasKey('concept_candidate_mentions', $parsed);
    }

    public function test_global_plan_missing_source_article_is_rejected(): void
    {
        $plan = $this->validGlobalPlan();
        unset($plan['source_article']);

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validateGlobalPlan($plan);

        $this->assertNotEmpty($errors);
    }

    public function test_global_plan_normalises_missing_optional_keys(): void
    {
        $plan = $this->validGlobalPlan();
        unset($plan['entity_pages'], $plan['concept_candidate_mentions'], $plan['warnings'], $plan['no_action_reason']);

        $parsed = EnterpriseWikiMaintainerDecisionPrompt::parseGlobalPlan($plan);

        $this->assertSame([], $parsed['entity_pages']);
        $this->assertSame([], $parsed['concept_candidate_mentions']);
        $this->assertSame([], $parsed['warnings']);
        $this->assertNull($parsed['no_action_reason']);
    }

    public function test_global_plan_mention_missing_name_is_rejected(): void
    {
        $plan = $this->validGlobalPlan();
        $plan['concept_candidate_mentions'] = [['concept_type' => 'process', 'mentioned_context' => 'section 2']];

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validateGlobalPlan($plan);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('name', implode(' ', $errors));
    }

    public function test_parse_global_plan_throws_on_invalid_plan(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid maintainer decision global plan/');

        EnterpriseWikiMaintainerDecisionPrompt::parseGlobalPlan(['source_article' => null]);
    }

    // =========================================================================
    // Split flow — Phase B: candidateBatchSchema() / validateCandidateBatch() / parseCandidateBatch()
    // =========================================================================

    public function test_candidate_batch_schema_is_strict_json_schema(): void
    {
        $schema = EnterpriseWikiMaintainerDecisionPrompt::candidateBatchSchema();

        $this->assertSame('json_schema', $schema['type']);
        $this->assertTrue($schema['json_schema']['strict']);
        $this->assertFalse($schema['json_schema']['schema']['additionalProperties']);
    }

    public function test_candidate_batch_schema_requires_concept_candidates_and_concept_pages_only(): void
    {
        $schema = EnterpriseWikiMaintainerDecisionPrompt::candidateBatchSchema()['json_schema']['schema'];

        $this->assertSame(['concept_candidates', 'concept_pages'], $schema['required']);
        $this->assertSame(['concept_candidates', 'concept_pages'], array_keys($schema['properties']));
    }

    public function test_candidate_batch_schema_reuses_the_exact_same_fragments_as_the_full_schema(): void
    {
        $fullProps = $this->schemaProps();
        $batchProps = EnterpriseWikiMaintainerDecisionPrompt::candidateBatchSchema()['json_schema']['schema']['properties'];

        $this->assertSame($fullProps['concept_candidates'], $batchProps['concept_candidates']);
        $this->assertSame($fullProps['concept_pages'], $batchProps['concept_pages']);
    }

    public function test_valid_candidate_batch_parses_without_exception(): void
    {
        $batch = [
            'concept_candidates' => [$this->validConceptCandidate()],
            'concept_pages' => [],
        ];

        $parsed = EnterpriseWikiMaintainerDecisionPrompt::parseCandidateBatch($batch);

        $this->assertCount(1, $parsed['concept_candidates']);
        $this->assertSame([], $parsed['concept_pages']);
    }

    public function test_candidate_batch_missing_concept_candidates_is_rejected(): void
    {
        $errors = EnterpriseWikiMaintainerDecisionPrompt::validateCandidateBatch(['concept_pages' => []]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('concept_candidates', implode(' ', $errors));
    }

    public function test_candidate_batch_missing_concept_pages_is_rejected(): void
    {
        $errors = EnterpriseWikiMaintainerDecisionPrompt::validateCandidateBatch(['concept_candidates' => []]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('concept_pages', implode(' ', $errors));
    }

    public function test_candidate_batch_invalid_candidate_entry_is_rejected(): void
    {
        $batch = [
            'concept_candidates' => [['name' => 'X']],
            'concept_pages' => [],
        ];

        $errors = EnterpriseWikiMaintainerDecisionPrompt::validateCandidateBatch($batch);

        $this->assertNotEmpty($errors);
    }

    public function test_parse_candidate_batch_throws_on_invalid_batch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid maintainer decision candidate batch/');

        EnterpriseWikiMaintainerDecisionPrompt::parseCandidateBatch([]);
    }

    private function validGlobalPlan(): array
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
            'entity_pages' => [],
            'concept_candidate_mentions' => [
                ['name' => 'ITIL Incident Management', 'concept_type' => 'process', 'mentioned_context' => 'section 2'],
            ],
            'no_action_reason' => null,
            'warnings' => [],
        ];
    }

    private function validConceptCandidate(): array
    {
        return [
            'name' => 'ITIL Incident Management',
            'concept_type' => 'framework process',
            'independent_reason' => 'A named ITIL process referenced independently of the illustration.',
            'mentioned_context' => 'Named in the document body and in the planned article structure.',
            'existing_page_title' => null,
            'decision' => 'create',
            'justification' => 'Central to understanding the article; no existing page covers it.',
            'owning_page_title' => null,
            'necessary_for_article' => true,
        ];
    }

    private function schemaRequired(): array
    {
        return EnterpriseWikiMaintainerDecisionPrompt::jsonSchema()['json_schema']['schema']['required'];
    }

    private function schemaProps(): array
    {
        return EnterpriseWikiMaintainerDecisionPrompt::jsonSchema()['json_schema']['schema']['properties'];
    }
}
