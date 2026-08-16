<?php

namespace App\Services\Ai\Requirements\Excel;

use App\Data\Ai\Requirements\Excel\WorkbookRequirementUnitData;
use App\Data\Ai\Requirements\RequirementExtractionCandidateData;
use App\Models\SavedNoticeAiRequirement;

/**
 * Turns a logical Excel requirement into an extraction candidate, deterministically.
 *
 * The whole point is that nothing here re-decides whether the unit is a requirement. Structure
 * discovery already worked out how the workbook expresses requirements, a human-designed schema was
 * validated against the real file, and the deterministic builder formed the units. Asking a model to
 * rediscover them afterwards cost us five of nineteen requirements on a realistic file — all of them
 * non-mandatory — and stripped the provenance off five more it kept but reworded. A validated
 * WorkbookRequirementUnitData IS the contract that this is a requirement.
 *
 * So `isRequirement` is true by construction, and there is deliberately no rule anywhere in this
 * class that reads the qualification. A "Bør", a "Nei" and a blank qualification all become
 * candidates on exactly the same terms as a "Skal"; the qualification is carried as provenance for a
 * human to judge, never as a gate.
 *
 * `extractionMethod` is rule_based, which is simply true: no AI produced these candidates.
 */
class WorkbookRequirementCandidateMapper
{
    /**
     * Purpose: Map one logical Excel requirement to an extraction candidate.
     * Inputs: The unit, the owning document id, and its position in the document.
     * Returns: A candidate ready for the existing validation/persistence pipeline.
     * Side effects: None. No AI, no text matching.
     */
    public function fromUnit(WorkbookRequirementUnitData $unit, int $documentId, int $index): RequirementExtractionCandidateData
    {
        $text = trim($unit->requirementText);

        return new RequirementExtractionCandidateData(
            sourceDocumentId: $documentId,
            // Block identity mirrors the DOCX convention (document-scoped, stable), but is derived
            // from the cell range rather than from reading order, so it survives re-import.
            sourceBlockId: sprintf('doc%d-%s', $documentId, $unit->sourceElementKey),
            sourceBlockIndex: $index,
            requirementIdentifier: $unit->requirementId,
            parentReference: $unit->sectionContext,
            requirementType: SavedNoticeAiRequirement::REQUIREMENT_TYPE_UNSPECIFIED,
            // Not derived from the qualification: mapping "Bør" onto an obligation vocabulary would
            // be a semantic judgement, and the raw customer wording is kept in source_reference
            // instead so nothing is lost or reinterpreted.
            obligationType: 'unspecified',
            extractionMethod: SavedNoticeAiRequirement::EXTRACTION_METHOD_RULE_BASED,
            originalText: $text,
            // Same whitespace normalization the AI path applies to its own candidates.
            normalizedText: trim(preg_replace('/\s+/u', ' ', $text) ?? $text),
            comment: $unit->comment,
            evaluationNotes: null,
            responseExpectation: null,
            expectedEvidence: [],
            keywords: [],
            domain: [],
            relatedReferences: [],
            sourceReference: array_merge($unit->toSourceReference(), [
                'source_element_key_origin' => 'excel_unit',
                'source_section_title' => $unit->sectionContext,
                // Nested under the same key the pipeline's whitelist already passes through, so the
                // sheet, range and human label reach the persisted requirement unchanged.
                'source_metadata' => $unit->toSourceReference(),
            ]),
            interpretationRisk: null,
            // The unit is the contract. No model gets to overturn it.
            isRequirement: true,
            confidence: 1.0,
            warnings: [],
            sourceRowKey: null,
            // Carried straight from the unit — never recovered afterwards by matching text, which
            // is what broke when the model reworded a requirement.
            sourceElementKey: sprintf('doc%d-%s', $documentId, $unit->sourceElementKey),
        );
    }

    /**
     * Purpose: Map a whole workbook's units in document order.
     * Inputs: The units and the owning document id.
     * Returns: One candidate per unit — never fewer.
     *
     * @param  list<WorkbookRequirementUnitData>  $units
     * @return list<RequirementExtractionCandidateData>
     */
    public function fromUnits(array $units, int $documentId): array
    {
        $candidates = [];

        foreach (array_values($units) as $index => $unit) {
            $candidates[] = $this->fromUnit($unit, $documentId, $index);
        }

        return $candidates;
    }
}
