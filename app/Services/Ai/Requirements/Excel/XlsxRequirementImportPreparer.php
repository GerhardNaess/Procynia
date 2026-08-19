<?php

namespace App\Services\Ai\Requirements\Excel;

use App\Data\Ai\Requirements\Excel\WorkbookSchemaData;
use App\Exceptions\Ai\XlsxRequirementImportException;
use Illuminate\Support\Facades\Log;

/**
 * Runs the whole Excel path — parse, discover, validate, build — and returns extraction input, or
 * refuses.
 *
 * It refuses rather than degrades, and that is the point. The old behaviour flattened a workbook
 * into one string of text and let requirement extraction do what it could with it; the result was
 * a list of "requirements" with no reliable link to any cell. Falling back to that when structure
 * discovery fails would be worse than importing nothing, because a partial or mis-structured
 * import looks finished. A bid manager who sees 40 requirements has no way to know that 60 were
 * silently dropped below a row cap, or that the wrong column was read as the requirement text.
 *
 * So every failure here throws, before the document is committed, with a message the user can act
 * on. Nothing half-imported reaches "I arbeid".
 *
 * Cost is one AI call per Excel import: structure discovery. Nothing else in this path talks to a
 * model.
 */
class XlsxRequirementImportPreparer
{
    public function __construct(
        private readonly XlsxWorkbookParser $parser,
        private readonly WorkbookStructureDiscoveryService $discoveryService,
        private readonly WorkbookRequirementUnitBuilder $unitBuilder,
        private readonly WorkbookExtractionInputBuilder $extractionInputBuilder,
    ) {}

    /**
     * Purpose: Prepare one .xlsx file for the ordinary requirement extraction pipeline.
     * Inputs: Absolute path to the stored workbook, the original filename (for messages), and the
     *         language its content is in.
     * Returns: {extracted_text, text_elements, unit_count, schema, trace}.
     * Side effects: One OpenAI call (structure discovery). Persists nothing.
     *
     * @return array{extracted_text: string, text_elements: list<array<string, mixed>>, unit_count: int, schema: WorkbookSchemaData, trace: array<string, mixed>}
     *
     * @throws XlsxRequirementImportException whenever the workbook cannot be imported safely
     */
    public function prepare(string $absolutePath, string $originalFilename, string $languageCode = 'no'): array
    {
        if (! WorkbookStructureDiscoveryAiClient::isAvailable()) {
            // No flat-text fallback: without discovery there is no trustworthy structure, and a
            // guess is exactly what this whole path exists to avoid.
            throw XlsxRequirementImportException::aiUnavailable($originalFilename);
        }

        $workbook = $this->parser->parse($absolutePath);

        $truncatedSheets = array_values(array_filter(
            $workbook->sheets,
            static fn ($sheet): bool => $sheet->truncated,
        ));

        if ($truncatedSheets !== []) {
            // Stopped before spending an AI call: a truncated workbook cannot produce a complete
            // requirement set no matter what discovery says about it.
            throw XlsxRequirementImportException::workbookTooLarge($originalFilename, array_map(
                static fn ($sheet): string => $sheet->name,
                $truncatedSheets,
            ));
        }

        $discovery = $this->discoveryService->discover($workbook, $languageCode);

        if (! $discovery['is_valid'] || $discovery['schema'] === null) {
            throw XlsxRequirementImportException::structureNotUnderstood(
                $originalFilename,
                $discovery['trace']['validation']['errors'] ?? [],
            );
        }

        $built = $this->unitBuilder->build($workbook, $discovery['schema']);

        if (! $built['is_complete']) {
            throw XlsxRequirementImportException::incompleteUnits($originalFilename, $built['warnings']);
        }

        if ($built['units'] === []) {
            throw XlsxRequirementImportException::noRequirementsFound($originalFilename);
        }

        $input = $this->extractionInputBuilder->build($built['units']);

        Log::info('[EXCEL_REQUIREMENT_IMPORT] Workbook prepared for requirement extraction.', [
            'document_filename' => $originalFilename,
            'unit_count' => count($built['units']),
            'skipped_count' => count($built['skipped']),
            'extracted_text_chars' => mb_strlen($input['extracted_text'], 'UTF-8'),
            'discovery' => $discovery['trace']['schema'] ?? null,
        ]);

        return [
            'extracted_text' => $input['extracted_text'],
            'text_elements' => $input['text_elements'],
            'unit_count' => count($built['units']),
            'schema' => $discovery['schema'],
            'trace' => $discovery['trace'],
        ];
    }
}
