<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Services\EnterpriseWiki\EnterpriseWikiPlannedSectionEvidenceResolver;
use Tests\TestCase;

class EnterpriseWikiPlannedSectionEvidenceResolverTest extends TestCase
{
    public function test_binds_each_planned_section_to_its_own_matching_source_evidence(): void
    {
        $sections = app(EnterpriseWikiPlannedSectionEvidenceResolver::class)->resolve([
            'Alfa terskel',
            'Beta gjennomgang',
            'Gamma rapportering',
        ], [
            ['source_element_key' => 'a', 'source_element_type' => 'paragraph', 'reference_text' => 'Alfa terskel er tre hendelser.'],
            ['source_element_key' => 'b', 'source_element_type' => 'paragraph', 'reference_text' => 'Beta gjennomgang gjennomføres annenhver uke.'],
            ['source_element_key' => 'c', 'source_element_type' => 'paragraph', 'reference_text' => 'Gamma rapportering skjer månedlig.'],
        ]);

        $this->assertSame(['a'], $sections[0]['source_element_keys']);
        $this->assertSame(['b'], $sections[1]['source_element_keys']);
        $this->assertSame(['c'], $sections[2]['source_element_keys']);
        $this->assertSame([], app(EnterpriseWikiPlannedSectionEvidenceResolver::class)->topicsWithoutEvidence($sections));
    }

    public function test_marks_a_section_without_matching_evidence_as_missing(): void
    {
        $resolver = app(EnterpriseWikiPlannedSectionEvidenceResolver::class);
        $sections = $resolver->resolve(['Uten dokumentasjon'], [
            ['source_element_key' => 'a', 'source_element_type' => 'paragraph', 'reference_text' => 'Kun dokumenterte Alfa-terkler.'],
        ]);

        $this->assertSame(0, $sections[0]['source_element_count']);
        $this->assertSame(['Uten dokumentasjon'], $resolver->topicsWithoutEvidence($sections));
    }

    public function test_binds_document_metadata_sections_to_explicit_document_control_elements(): void
    {
        $sections = app(EnterpriseWikiPlannedSectionEvidenceResolver::class)->resolve([
            'Document identity, purpose and validity',
        ], [
            ['source_element_key' => 'metadata-id', 'source_element_type' => 'table_row', 'reference_text' => 'Document ID: FG-OPS-014 | Version: 1.0'],
            ['source_element_key' => 'metadata-validity', 'source_element_type' => 'table_row', 'reference_text' => 'Document ID: FG-OPS-014 | Valid from: 15 April 2026'],
            ['source_element_key' => 'purpose', 'source_element_type' => 'paragraph', 'section_title' => '1. Purpose', 'reference_text' => 'The instruction defines local operating routines.'],
        ]);

        $this->assertSame(['metadata-id', 'metadata-validity', 'purpose'], $sections[0]['source_element_keys']);
        $this->assertSame([], app(EnterpriseWikiPlannedSectionEvidenceResolver::class)->topicsWithoutEvidence($sections));
    }
}
