<?php

namespace App\Data\Ai\Requirements\Excel;

use JsonSerializable;

/**
 * One logical requirement as the workbook expresses it — which may be one row, several rows held
 * together by a merged cell, or a row that inherits its meaning from a section heading above it.
 *
 * The metadata stays in separate fields on purpose. It would be less code to fold the identifier,
 * the qualification, the weighting and the comment into one blob of text and let the extraction
 * prompt sort it out, but then a "Skal" that meant *this requirement is absolute* becomes
 * indistinguishable from the word "skal" occurring in a sentence, and the weighting column becomes
 * a number floating in prose. Structure that survives this far is structure the next phase can
 * still choose to use — or ignore. Structure flattened here is gone.
 *
 * `sourceElementKey` follows the phase-1 provenance contract exactly (sheet:0:range:B17:G18) and is
 * derived from position alone. Two rows saying the same thing get different keys; rewording a cell
 * does not move a key.
 */
final readonly class WorkbookRequirementUnitData implements JsonSerializable
{
    /**
     * @param  list<string>  $requirementTextParts  the contributing cell values, in schema order
     * @param  array<string, string>  $otherFields  column letter => value for roles with no dedicated field
     */
    public function __construct(
        public int $sheetIndex,
        public string $sheetName,
        public int $startRow,
        public int $endRow,
        public string $sourceRange,
        public string $humanSourceRef,
        public string $sourceElementType,
        public string $sourceElementKey,
        public string $requirementText,
        public array $requirementTextParts = [],
        public ?string $requirementId = null,
        public ?string $qualification = null,
        public ?string $weighting = null,
        public ?string $responseField = null,
        public ?string $comment = null,
        public ?string $sectionContext = null,
        public ?int $sectionRowNumber = null,
        public array $otherFields = [],
    ) {}

    /**
     * The provenance payload in the shape the existing requirement pipeline already stores under
     * `source_reference` for DOCX table rows and paragraphs — same keys, Excel values.
     *
     * @return array<string, mixed>
     */
    public function toSourceReference(): array
    {
        return array_filter([
            'source_element_type' => $this->sourceElementType,
            'source_element_key' => $this->sourceElementKey,
            'source_label' => $this->humanSourceRef,
            'source_sheet_index' => $this->sheetIndex,
            'source_sheet_name' => $this->sheetName,
            'source_range' => $this->sourceRange,
            'source_start_row' => $this->startRow,
            'source_end_row' => $this->endRow,
            'source_section_title' => $this->sectionContext,
            'source_section_row' => $this->sectionRowNumber,
            'source_qualification' => $this->qualification,
            'source_weighting' => $this->weighting,
            // Carried so the unit can be rebuilt verbatim from the persisted document when the
            // extraction run picks it up on the queue, without a second parse of the workbook.
            'source_comment' => $this->comment,
            'source_requirement_id' => $this->requirementId,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    public function toArray(): array
    {
        return [
            'sheet_index' => $this->sheetIndex,
            'sheet_name' => $this->sheetName,
            'start_row' => $this->startRow,
            'end_row' => $this->endRow,
            'source_range' => $this->sourceRange,
            'human_source_ref' => $this->humanSourceRef,
            'source_element_type' => $this->sourceElementType,
            'source_element_key' => $this->sourceElementKey,
            'requirement_text' => $this->requirementText,
            'requirement_text_parts' => $this->requirementTextParts,
            'requirement_id' => $this->requirementId,
            'qualification' => $this->qualification,
            'weighting' => $this->weighting,
            'response_field' => $this->responseField,
            'comment' => $this->comment,
            'section_context' => $this->sectionContext,
            'section_row_number' => $this->sectionRowNumber,
            'other_fields' => $this->otherFields,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
