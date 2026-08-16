<?php

namespace Tests\Unit\Services\Ai\Requirements;

use App\Data\Ai\Requirements\Excel\WorkbookRequirementUnitData;
use App\Models\SavedNoticeAiRequirement;
use App\Services\Ai\Requirements\Excel\WorkbookRequirementCandidateMapper;
use Tests\TestCase;

/**
 * A validated Excel unit IS the contract that something is a requirement. These tests pin that no
 * qualification — "Bør", "Nei", or none at all — can change that, and that provenance is carried
 * rather than recovered.
 */
class WorkbookRequirementCandidateMapperTest extends TestCase
{
    private function unit(array $overrides = []): WorkbookRequirementUnitData
    {
        return new WorkbookRequirementUnitData(
            sheetIndex: $overrides['sheetIndex'] ?? 1,
            sheetName: $overrides['sheetName'] ?? 'Kravmatrise',
            startRow: $overrides['startRow'] ?? 5,
            endRow: $overrides['endRow'] ?? 5,
            sourceRange: $overrides['sourceRange'] ?? 'A5:H5',
            humanSourceRef: $overrides['humanSourceRef'] ?? 'Kravmatrise!A5:H5',
            sourceElementType: 'sheet_range',
            sourceElementKey: $overrides['sourceElementKey'] ?? 'sheet:1:range:A5:H5',
            requirementText: $overrides['requirementText'] ?? 'Leverandøren skal etablere en etableringsplan.',
            requirementId: $overrides['requirementId'] ?? 'GEN-01',
            qualification: $overrides['qualification'] ?? 'Skal',
            weighting: $overrides['weighting'] ?? '8',
            comment: $overrides['comment'] ?? 'Planen skal beskrive ansvar og milepæler.',
            sectionContext: $overrides['sectionContext'] ?? '1. Generelle leveransekrav',
        );
    }

    private function map(array $overrides = [])
    {
        return app(WorkbookRequirementCandidateMapper::class)->fromUnit($this->unit($overrides), 7, 0);
    }

    public function test_one_unit_becomes_one_candidate_carrying_its_text_and_identifier(): void
    {
        $candidate = $this->map();

        $this->assertSame('Leverandøren skal etablere en etableringsplan.', $candidate->originalText);
        $this->assertSame('Leverandøren skal etablere en etableringsplan.', $candidate->normalizedText);
        $this->assertSame('GEN-01', $candidate->requirementIdentifier);
        $this->assertSame('Planen skal beskrive ansvar og milepæler.', $candidate->comment);
        $this->assertSame(SavedNoticeAiRequirement::EXTRACTION_METHOD_RULE_BASED, $candidate->extractionMethod);
    }

    public function test_every_unit_is_a_requirement_regardless_of_its_qualification(): void
    {
        // The regression this guards: the shared extraction AI dropped exactly these, silently.
        foreach (['Skal', 'Bør', 'Ja', 'Nei', null] as $qualification) {
            $candidate = $this->map(['qualification' => $qualification]);

            $this->assertTrue($candidate->isRequirement, sprintf('qualification [%s] must still be a requirement', $qualification ?? 'null'));
            $this->assertSame(1.0, $candidate->confidence);
        }
    }

    public function test_the_mapper_contains_no_qualification_logic_at_all(): void
    {
        $source = file_get_contents(base_path('app/Services/Ai/Requirements/Excel/WorkbookRequirementCandidateMapper.php'));

        // Not a keyword list, not an obligation rule: the qualification is provenance, never a gate.
        foreach (['Skal', 'Bør', 'mandatory', 'optional'] as $forbidden) {
            $this->assertStringNotContainsString("'{$forbidden}'", $source);
        }
    }

    public function test_multiline_requirement_text_is_normalized_like_the_ai_path(): void
    {
        $candidate = $this->map(['requirementText' => "Kort tittel\n\nUtfyllende krav   med   mellomrom."]);

        $this->assertSame("Kort tittel\n\nUtfyllende krav   med   mellomrom.", $candidate->originalText);
        $this->assertSame('Kort tittel Utfyllende krav med mellomrom.', $candidate->normalizedText);
    }

    public function test_provenance_is_carried_directly_and_document_scoped(): void
    {
        $candidate = $this->map();

        $this->assertSame('doc7-sheet:1:range:A5:H5', $candidate->sourceElementKey);
        $this->assertSame('sheet_range', $candidate->sourceReference['source_element_type']);
        $this->assertSame('excel_unit', $candidate->sourceReference['source_element_key_origin']);
        $this->assertSame('Kravmatrise!A5:H5', $candidate->sourceReference['source_metadata']['source_label']);
        $this->assertSame('Skal', $candidate->sourceReference['source_metadata']['source_qualification']);
        $this->assertSame('8', $candidate->sourceReference['source_metadata']['source_weighting']);
        $this->assertSame('1. Generelle leveransekrav', $candidate->sourceReference['source_section_title']);
        // Never resolved by matching text afterwards.
        $this->assertNull($candidate->sourceRowKey);
    }

    public function test_identical_text_on_two_ranges_yields_two_candidates_with_different_keys(): void
    {
        $mapper = app(WorkbookRequirementCandidateMapper::class);
        $candidates = $mapper->fromUnits([
            $this->unit(['sourceElementKey' => 'sheet:1:range:A5:H5', 'requirementText' => 'Samme tekst.']),
            $this->unit(['sourceElementKey' => 'sheet:1:range:A6:H6', 'requirementText' => 'Samme tekst.']),
        ], 7);

        $this->assertCount(2, $candidates);
        $this->assertSame($candidates[0]->normalizedText, $candidates[1]->normalizedText);
        $this->assertNotSame($candidates[0]->sourceElementKey, $candidates[1]->sourceElementKey);
        $this->assertSame([0, 1], array_map(static fn ($c): int => $c->sourceBlockIndex, $candidates));
    }

    public function test_the_mapper_makes_no_ai_call(): void
    {
        $source = file_get_contents(base_path('app/Services/Ai/Requirements/Excel/WorkbookRequirementCandidateMapper.php'));

        foreach (['OpenAiClient', 'createResponse', 'AiClient', 'matchTextElement'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }
}
