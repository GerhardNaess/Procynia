<?php

namespace App\Services\Ai\Requirements\Excel;

use App\Data\Ai\Requirements\Excel\WorkbookRequirementUnitData;
use App\Data\Ai\Requirements\RequirementExtractionCandidateData;
use App\Data\Ai\Requirements\RequirementExtractionResultData;
use App\Models\SavedNoticeAiDocument;

/**
 * Answers one question for the extraction pipeline: does this document already carry requirements
 * that were determined deterministically, rather than text a model still has to interpret?
 *
 * A workbook does. By the time an .xlsx document row exists, structure discovery has run, a schema
 * has been validated against the real file, and the unit builder has formed the logical
 * requirements — each already persisted on the document as a structured element with its own sheet
 * range, identifier, qualification and comment. There is nothing left to discover, and running
 * discovery anyway is what dropped five of nineteen requirements on a realistic file.
 *
 * The signal is the elements' own type, not the filename: a document whose structured elements are
 * `sheet_range` carries finished units. That keeps the pipeline's question generic — "are these
 * already requirements?" — rather than making it ask "is this Excel?".
 *
 * Rebuilding the unit from the persisted element rather than re-parsing the workbook matters
 * because extraction runs on a queue, long after the upload request and its parsed workbook are
 * gone. Everything needed is already on the document.
 */
class WorkbookDeterministicCandidateResolver
{
    public function __construct(
        private readonly WorkbookRequirementCandidateMapper $mapper,
    ) {}

    /**
     * Purpose: Build the finished candidates a document already carries, if it carries any.
     * Inputs: The persisted AI document.
     * Returns: One candidate per logical requirement, or null when this document has none — in
     *          which case the caller falls back to ordinary AI extraction.
     * Side effects: None. No AI, no text matching, no workbook re-parse.
     *
     * @return list<RequirementExtractionCandidateData>|null
     */
    public function resolve(SavedNoticeAiDocument $document): ?array
    {
        $elements = array_values(array_filter(
            (array) ($document->structured_text_elements ?? []),
            static fn ($element): bool => is_array($element)
                && ($element['element_type'] ?? null) === WorkbookExtractionInputBuilder::ELEMENT_TYPE,
        ));

        if ($elements === []) {
            return null;
        }

        $units = array_map(fn (array $element): WorkbookRequirementUnitData => $this->hydrateUnit($element), $elements);

        return $this->mapper->fromUnits($units, (int) $document->id);
    }

    /**
     * Purpose: The finished candidates for one extraction chunk, in the result shape the run
     *          service expects — or null when this document has none and ordinary AI extraction
     *          must run instead.
     * Inputs: The chunk-scoped document clone, the real document (which holds the structured
     *         elements), the run id, and the chunk's character range within extracted_text.
     * Returns: A result whose openAiCallCount is 0, because none was made.
     * Side effects: None.
     *
     * Elements are attributed to a chunk by the same character range the AI path uses, so a
     * multi-chunk workbook still gets each requirement exactly once.
     */
    public function resultForChunk(
        SavedNoticeAiDocument $chunkDocument,
        SavedNoticeAiDocument $document,
        string $runId,
        int $chunkStart,
        int $chunkEnd,
    ): ?RequirementExtractionResultData {
        $all = $this->resolve($document);

        if ($all === null) {
            return null;
        }

        $elements = $document->structuredTextElementsInRange($chunkStart, $chunkEnd);
        $keysInChunk = array_flip(array_values(array_filter(array_map(
            static fn (array $element): string => (string) ($element['element_key'] ?? ''),
            $elements,
        ))));

        $candidates = array_values(array_filter(
            $all,
            static fn (RequirementExtractionCandidateData $candidate): bool => isset($keysInChunk[(string) $candidate->sourceElementKey]),
        ));

        $count = count($candidates);

        return new RequirementExtractionResultData(
            ok: true,
            partial: false,
            savedNoticeId: (int) $document->saved_notice_id,
            savedNoticeAiDocumentId: (int) $document->id,
            runId: $runId,
            documentTitle: (string) $document->original_filename,
            documentFilename: (string) $document->original_filename,
            model: 'deterministic',
            relevanceModel: 'deterministic',
            extractionModel: 'deterministic',
            segmentCount: $count,
            relevantSegmentCount: $count,
            relevanceCallCount: 0,
            extractionCallCount: 0,
            openAiCallCount: 0,
            segments: [],
            relevanceResults: [],
            extractionResults: [],
            candidates: $candidates,
            metadata: [
                'parse_strategy' => 'deterministic_units',
                'candidate_count' => $count,
                'raw_candidate_count' => $count,
                'mapped_candidate_count' => $count,
                'deduped_candidate_count' => $count,
                'openai_call_count' => 0,
            ],
        );
    }

    /**
     * Rebuild the logical requirement from what the document persisted for it.
     *
     * The element's own key is document-scoped (`doc7-sheet:0:range:A2:F2`); the workbook-local key
     * is kept alongside it in the metadata, and that is the one the unit carries — the mapper
     * re-applies the document prefix, so identity stays derived in exactly one place.
     *
     * @param  array<string, mixed>  $element
     */
    private function hydrateUnit(array $element): WorkbookRequirementUnitData
    {
        $meta = is_array($element['source_metadata'] ?? null) ? $element['source_metadata'] : [];

        return new WorkbookRequirementUnitData(
            sheetIndex: (int) ($meta['source_sheet_index'] ?? 0),
            sheetName: (string) ($meta['source_sheet_name'] ?? ''),
            startRow: (int) ($meta['source_start_row'] ?? 0),
            endRow: (int) ($meta['source_end_row'] ?? 0),
            sourceRange: (string) ($meta['source_range'] ?? ''),
            humanSourceRef: (string) ($meta['source_label'] ?? ''),
            sourceElementType: WorkbookExtractionInputBuilder::ELEMENT_TYPE,
            sourceElementKey: (string) ($meta['source_element_key'] ?? ''),
            requirementText: (string) ($element['text'] ?? ''),
            requirementId: $this->nullableString($element['number'] ?? $meta['source_requirement_id'] ?? null),
            qualification: $this->nullableString($meta['source_qualification'] ?? null),
            weighting: $this->nullableString($meta['source_weighting'] ?? null),
            comment: $this->nullableString($meta['source_comment'] ?? null),
            sectionContext: $this->nullableString($element['section_title'] ?? $meta['source_section_title'] ?? null),
            sectionRowNumber: isset($meta['source_section_row']) ? (int) $meta['source_section_row'] : null,
        );
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
