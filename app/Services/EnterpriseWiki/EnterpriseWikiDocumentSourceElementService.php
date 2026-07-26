<?php

namespace App\Services\EnterpriseWiki;

use App\Data\Ai\Requirements\DocxTableData;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiSourceReference;
use App\Services\DocumentTextExtractor;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EnterpriseWikiDocumentSourceElementService
{
    public function __construct(
        private readonly DocumentTextExtractor $documentTextExtractor,
    ) {}

    /**
     * @return array{
     *     supports_structured_elements: bool,
     *     manual_source_allowed: bool,
     *     manual_source_reason: ?string,
     *     elements: list<array<string, mixed>>,
     * }
     */
    public function inspect(EnterpriseWikiDocument $document): array
    {
        $elements = $this->elementsForDocument($document);
        $hasStructuredElements = $elements !== [];

        return [
            'supports_structured_elements' => $hasStructuredElements,
            'manual_source_allowed' => ! $hasStructuredElements,
            'manual_source_reason' => $hasStructuredElements
                ? __('procynia.wiki.claim_source_manual_source_reason_structured')
                : __('procynia.wiki.claim_source_manual_source_reason_unstructured'),
            'elements' => $elements,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolveSelection(
        EnterpriseWikiDocument $document,
        ?string $sourceElementKey,
        ?string $sourceElementType,
        ?string $sourceRowKey = null,
    ): ?array {
        $sourceElementKey = $this->normalizeNullableString($sourceElementKey);
        $sourceElementType = $this->normalizeNullableString($sourceElementType);
        $sourceRowKey = $this->normalizeNullableString($sourceRowKey);

        if ($sourceElementKey === null || $sourceElementType === null) {
            return null;
        }

        foreach ($this->elementsForDocument($document) as $element) {
            if (($element['source_element_key'] ?? null) !== $sourceElementKey) {
                continue;
            }

            if (($element['source_element_type'] ?? null) !== $sourceElementType) {
                continue;
            }

            if (($element['source_element_type'] ?? null) === EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_TABLE_ROW) {
                if ($sourceRowKey === null || ($element['source_row_key'] ?? null) !== $sourceRowKey) {
                    continue;
                }
            }

            return $element;
        }

        return null;
    }

    /**
     * The raw, structured tables parsed from this document — same parse call and same
     * (non-document-scoped, table-local) source_row_key convention as elementsForDocument()'s
     * table_row elements below, so a row's key is identical whichever accessor produced it.
     * Used by EnterpriseWikiTableBlockBuilder to render a genuine table block/claims for
     * whichever table(s) a generated page's blocks actually cited — never a new, parallel
     * identity scheme.
     *
     * @return list<DocxTableData>
     */
    public function tablesForDocument(EnterpriseWikiDocument $document): array
    {
        $path = $this->resolveDocumentPath($document);

        if ($path === null || ! Str::endsWith(Str::lower($path), '.docx')) {
            return [];
        }

        $payload = $this->documentTextExtractor->extractDocxTextAndTables($path);

        return array_values(array_filter(
            $payload['tables'] ?? [],
            static fn (mixed $table): bool => $table instanceof DocxTableData,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function elementsForDocument(EnterpriseWikiDocument $document): array
    {
        $path = $this->resolveDocumentPath($document);

        if ($path === null || ! Str::endsWith(Str::lower($path), '.docx')) {
            return [];
        }

        $payload = $this->documentTextExtractor->extractDocxTextAndTables($path);
        $elements = [];

        foreach (($payload['text_elements'] ?? []) as $element) {
            if (! is_array($element)) {
                continue;
            }

            $text = trim((string) ($element['text'] ?? ''));
            $elementKey = $this->normalizeNullableString($element['element_key'] ?? null);
            $elementType = $this->normalizeNullableString($element['element_type'] ?? null);

            if ($text === '' || $elementKey === null || $elementType === null) {
                continue;
            }

            $elements[] = [
                'source_element_key' => $elementKey,
                'source_element_type' => $elementType,
                'source_row_key' => null,
                'section_number' => $this->normalizeNullableString($element['section_number'] ?? null),
                'section_title' => $this->normalizeNullableString($element['section_title'] ?? null),
                'page_reference' => $this->buildPageReference(
                    $this->normalizeNullableString($element['section_number'] ?? null),
                    $this->normalizeNullableString($element['section_title'] ?? null),
                    $elementType,
                    null,
                    null,
                ),
                'reference_text' => $text,
                'display_text' => Str::limit($text, 280),
                'sort_order' => (int) ($element['char_start'] ?? 0),
            ];
        }

        foreach (($payload['tables'] ?? []) as $table) {
            if (! $table instanceof DocxTableData) {
                continue;
            }

            foreach ($table->rows as $row) {
                $rowText = $this->renderTableRowText($row->cells);
                $sourceRowKey = $this->normalizeNullableString($row->sourceRowKey);

                if ($rowText === '' || $sourceRowKey === null) {
                    continue;
                }

                $elements[] = [
                    'source_element_key' => $sourceRowKey,
                    'source_element_type' => EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_TABLE_ROW,
                    'source_row_key' => $sourceRowKey,
                    'section_number' => $this->normalizeNullableString($row->sectionNumber ?? null),
                    'section_title' => $this->normalizeNullableString($row->sectionTitle ?? null),
                    'page_reference' => $this->buildPageReference(
                        $this->normalizeNullableString($row->sectionNumber ?? null),
                        $this->normalizeNullableString($row->sectionTitle ?? null),
                        EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_TABLE_ROW,
                        (int) ($table->tableIndex ?? 0),
                        (int) ($row->rowIndex ?? 0),
                    ),
                    'reference_text' => $rowText,
                    'display_text' => Str::limit($rowText, 280),
                    'sort_order' => (int) ($row->charStart ?? 0),
                ];
            }
        }

        usort($elements, static fn (array $left, array $right): int => ($left['sort_order'] ?? 0) <=> ($right['sort_order'] ?? 0));

        return array_map(static function (array $element): array {
            unset($element['sort_order']);

            return $element;
        }, $elements);
    }

    /**
     * @param  array<int, mixed>  $cells
     */
    private function renderTableRowText(array $cells): string
    {
        $parts = [];

        foreach ($cells as $cell) {
            if (! is_object($cell)) {
                continue;
            }

            $header = trim((string) ($cell->originalHeader ?? ''));
            $value = trim((string) ($cell->value ?? ''));

            if ($header === '' && $value === '') {
                continue;
            }

            $parts[] = $header !== '' ? sprintf('%s: %s', $header, $value) : $value;
        }

        return trim(implode(' | ', array_filter($parts, static fn (string $part): bool => trim($part) !== '')));
    }

    private function buildPageReference(?string $sectionNumber, ?string $sectionTitle, string $elementType, ?int $tableIndex, ?int $rowIndex): string
    {
        if ($elementType === EnterpriseWikiSourceReference::SOURCE_ELEMENT_TYPE_TABLE_ROW) {
            $parts = [];

            if ($sectionNumber !== null && $sectionNumber !== '') {
                $parts[] = 'Avsnitt '.$sectionNumber;
            } elseif ($sectionTitle !== null && $sectionTitle !== '') {
                $parts[] = $sectionTitle;
            }

            $parts[] = 'Tabell '.(($tableIndex ?? 0) + 1).', rad '.(($rowIndex ?? 0) + 1);

            return implode(' · ', $parts);
        }

        if ($sectionNumber !== null && $sectionNumber !== '') {
            return $sectionTitle !== null && $sectionTitle !== ''
                ? 'Avsnitt '.$sectionNumber.' · '.$sectionTitle
                : 'Avsnitt '.$sectionNumber;
        }

        if ($sectionTitle !== null && $sectionTitle !== '') {
            return $sectionTitle;
        }

        return 'Løpende tekst';
    }

    private function resolveDocumentPath(EnterpriseWikiDocument $document): ?string
    {
        if (! is_string($document->file_path) || trim($document->file_path) === '') {
            return null;
        }

        $disk = Storage::disk('local');

        return $disk->exists($document->file_path)
            ? $disk->path($document->file_path)
            : null;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
