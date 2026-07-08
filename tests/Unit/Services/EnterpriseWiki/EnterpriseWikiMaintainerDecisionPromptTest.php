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
                'action'        => 'update',
                'page_id'       => 42,
                'title'         => 'Masterdatastyring',
                'proposed_slug' => 'masterdatastyring',
                'reason'        => 'Expanding existing concept page.',
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
                'action'        => 'update',
                'page_id'       => null,
                'title'         => 'Masterdatastyring',
                'proposed_slug' => 'masterdatastyring',
                'reason'        => 'Should fail — update needs a page_id.',
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
                'action'        => 'create',
                'page_id'       => null,
                'title'         => 'Statsbygg',
                'proposed_slug' => 'statsbygg',
                'reason'        => 'New entity page.',
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
                'action'        => 'create',
                'title'         => 'Masterdata Prosjekt',
                'proposed_slug' => 'masterdata-prosjekt',
                'reason'        => 'Source document introduces project scope not yet in the wiki.',
            ],
            'source_summary' => [
                'action'        => 'create',
                'title'         => 'Sammendrag: Masterdata Prosjekt',
                'proposed_slug' => 'sammendrag-masterdata-prosjekt',
                'reason'        => 'Companion summary for the source article.',
            ],
            'concept_pages'    => [],
            'entity_pages'     => [],
            'no_action_reason' => null,
            'warnings'         => [],
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
