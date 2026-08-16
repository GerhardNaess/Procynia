<?php

namespace App\Services\Ai\Requirements\Excel;

use App\Data\Ai\Requirements\Excel\WorkbookRequirementUnitData;

/**
 * Renders logical Excel requirements into the two things the existing extraction pipeline already
 * reads: a document text, and structured text elements positioned inside it.
 *
 * This is the whole Excel adapter. It exists because the pipeline recovers provenance by matching a
 * candidate's text against `structured_text_elements` and then copying that element's own
 * `element_type` and `element_key` onto the requirement — see
 * RequirementExtractionCandidateData::withResolvedTextElement(). DOCX puts `paragraph` and
 * `list_item` through that path; Excel puts `sheet_range` through the same one. No change to the
 * extractor, no Excel branch inside it, no second pipeline.
 *
 * Two decisions worth stating plainly:
 *
 * - The supplier's own answer column is deliberately NOT written into the document text. It is the
 *   supplier's draft response, not something the customer asked for, and a model reading it inside
 *   a requirement block would happily extract it as one. It stays on the element as metadata.
 * - `structured_tables` is left empty. DocxTableData models a Word table (header labels, row keys)
 *   and everything reading it stamps `source_element_type = 'table_row'`. Forcing sheets into it
 *   would make Excel provenance claim to be something it is not.
 */
class WorkbookExtractionInputBuilder
{
    public const ELEMENT_TYPE = 'sheet_range';

    /**
     * Purpose: Turn logical Excel requirements into extraction input.
     * Inputs: The units built from a validated schema, in document order.
     * Returns: {extracted_text, text_elements} — ready for SavedNoticeAiDocument.
     * Side effects: None.
     *
     * @param  list<WorkbookRequirementUnitData>  $units
     * @return array{extracted_text: string, text_elements: list<array<string, mixed>>}
     */
    public function build(array $units): array
    {
        $text = '';
        $elements = [];

        foreach ($units as $unit) {
            $header = $this->headerLine($unit);
            $context = $this->contextLines($unit);

            // The requirement wording must appear verbatim and by itself, because that exact string
            // is what the pipeline matches a candidate back to. Header and context sit around it,
            // never inside it.
            $block = $header."\n".$unit->requirementText;
            $requirementOffset = mb_strlen($text, 'UTF-8') + mb_strlen($header, 'UTF-8') + 1;

            if ($context !== '') {
                $block .= "\n".$context;
            }

            $text .= $block."\n\n";

            $elements[] = [
                'element_key' => $unit->sourceElementKey,
                'element_type' => self::ELEMENT_TYPE,
                'text' => $unit->requirementText,
                'number' => $unit->requirementId,
                'section_number' => null,
                'section_title' => $unit->sectionContext,
                // Positions the element inside extracted_text, which is how the extractor decides
                // which window an element belongs to.
                'char_start' => $requirementOffset,
                // Carried through to the requirement's source_reference (see
                // RequirementExtractionCandidateData::withResolvedTextElement()), so the sheet and
                // range a requirement came from survive all the way to the UI.
                'source_metadata' => $unit->toSourceReference(),
            ];
        }

        return [
            'extracted_text' => rtrim($text)."\n",
            'text_elements' => $elements,
        ];
    }

    /**
     * A stable, human-readable heading so a reader (and the model) can see which sheet and range a
     * block came from. Identity still comes from the element, never from parsing this line back.
     */
    private function headerLine(WorkbookRequirementUnitData $unit): string
    {
        return $unit->requirementId !== null
            ? sprintf('[%s — %s]', $unit->requirementId, $unit->humanSourceRef)
            : sprintf('[%s]', $unit->humanSourceRef);
    }

    /**
     * Metadata the customer stated about the requirement — qualification, weighting, section,
     * comment. Kept as labelled lines after the wording so the model can use them as context
     * without them being mistaken for the requirement itself.
     */
    private function contextLines(WorkbookRequirementUnitData $unit): string
    {
        $lines = [];

        if ($unit->sectionContext !== null) {
            $lines[] = 'Seksjon: '.$unit->sectionContext;
        }

        if ($unit->qualification !== null) {
            $lines[] = 'Kvalifisering: '.$unit->qualification;
        }

        if ($unit->weighting !== null) {
            $lines[] = 'Vekt: '.$unit->weighting;
        }

        if ($unit->comment !== null) {
            $lines[] = 'Kommentar: '.$unit->comment;
        }

        foreach ($unit->otherFields as $columnLetter => $value) {
            $lines[] = sprintf('Kolonne %s: %s', $columnLetter, $value);
        }

        return implode("\n", $lines);
    }
}
