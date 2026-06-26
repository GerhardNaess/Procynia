<?php

namespace App\Console\Commands;

use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemVersion;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

#[Signature('knowledge:legacy-audit')]
#[Description('Audit legacy knowledge item fields before physical cleanup.')]
class AuditKnowledgeLegacyFields extends Command
{
    /**
     * Purpose:
     * Audit legacy knowledge item mirrors against the current version state.
     *
     * Inputs:
     * None.
     *
     * Returns:
     * int
     *
     * Side effects:
     * None. This command is read-only.
     */
    public function handle(): int
    {
        $hasContentType = $this->hasContentTypeColumn();
        $hasIsActive = $this->hasIsActiveColumn();
        $hasOriginalFilename = $this->hasOriginalFilenameColumn();
        $hasStoragePath = $this->hasStoragePathColumn();
        $hasMimeType = $this->hasMimeTypeColumn();
        $hasFileSizeBytes = $this->hasFileSizeBytesColumn();
        $hasExtractionStatus = $this->hasExtractionStatusColumn();
        $hasExtractionError = $this->hasExtractionErrorColumn();
        $hasExtractedText = $this->hasExtractedTextColumn();

        $summary = [
            'total_knowledge_items' => KnowledgeItem::query()->count(),
            'total_knowledge_item_versions' => KnowledgeItemVersion::query()->count(),
            'items_without_current_version' => 0,
            'items_with_multiple_current_versions' => 0,
            'current_versions_without_parent_item' => 0,
            'current_version_missing_storage_path' => 0,
            'current_version_missing_original_filename' => 0,
            'current_version_missing_mime_type' => 0,
            'current_version_missing_file_size_bytes' => 0,
            'storage_path_mismatches' => 0,
            'original_filename_mismatches' => 0,
            'mime_type_mismatches' => 0,
            'file_size_bytes_mismatches' => 0,
            'current_version_missing_extraction_status' => 0,
            'current_version_failed_extraction_status' => 0,
            'current_version_failed_without_extraction_error' => 0,
            'current_version_missing_extraction_error_on_success' => 0,
            'current_version_missing_extracted_text' => 0,
            'current_version_missing_extracted_text_on_success' => 0,
            'legacy_extraction_status_mismatches' => 0,
            'legacy_extraction_error_mismatches' => 0,
            'legacy_extracted_text_mismatches' => 0,
            'content_fallback_candidates' => 0,
            'content_type_mismatches' => 0,
            'is_active_mismatches' => 0,
        ];

        $examples = [
            'blocking' => [],
            'review' => [],
            'expected' => [],
        ];

        $selectColumns = [
            'id',
            'document_type',
            'document_status',
            'content',
        ];

        if ($hasExtractionStatus) {
            $selectColumns[] = 'extraction_status';
        }

        if ($hasExtractionError) {
            $selectColumns[] = 'extraction_error';
        }

        if ($hasExtractedText) {
            $selectColumns[] = 'extracted_text';
        }

        if ($hasOriginalFilename) {
            $selectColumns[] = 'original_filename';
        }

        if ($hasStoragePath) {
            $selectColumns[] = 'storage_path';
        }

        if ($hasMimeType) {
            $selectColumns[] = 'mime_type';
        }

        if ($hasFileSizeBytes) {
            $selectColumns[] = 'file_size_bytes';
        }

        if ($hasContentType) {
            $selectColumns[] = 'content_type';
        }

        if ($hasIsActive) {
            $selectColumns[] = 'is_active';
        }

        KnowledgeItem::query()
            ->select($selectColumns)
            ->with([
                'currentVersion' => function ($query): void {
                    $query->select([
                        'knowledge_item_versions.id',
                        'knowledge_item_versions.knowledge_item_id',
                        'knowledge_item_versions.version_no',
                        'knowledge_item_versions.is_current',
                        'knowledge_item_versions.storage_path',
                        'knowledge_item_versions.original_filename',
                        'knowledge_item_versions.mime_type',
                        'knowledge_item_versions.file_size_bytes',
                        'knowledge_item_versions.extracted_text',
                        'knowledge_item_versions.extraction_status',
                        'knowledge_item_versions.extraction_error',
                    ]);
                },
            ])
            ->withCount([
                'versions as current_versions_count' => function ($query): void {
                    $query->where('is_current', true);
                },
            ])
            ->orderBy('id')
            ->chunkById(250, function ($items) use (&$summary, &$examples, $hasOriginalFilename, $hasStoragePath, $hasMimeType, $hasFileSizeBytes, $hasContentType, $hasIsActive, $hasExtractionStatus, $hasExtractionError, $hasExtractedText): void {
                foreach ($items as $item) {
                    if ((int) $item->current_versions_count === 0) {
                        $summary['items_without_current_version']++;
                        $this->appendExample($examples['blocking'], $item->id);
                    }

                    if ((int) $item->current_versions_count > 1) {
                        $summary['items_with_multiple_current_versions']++;
                        $this->appendExample($examples['blocking'], $item->id);
                    }

                    $currentVersion = $item->currentVersion;

                    if ($currentVersion === null) {
                        continue;
                    }

                    if ($currentVersion->knowledge_item_id === null) {
                        $summary['current_versions_without_parent_item']++;
                        $this->appendExample($examples['blocking'], $currentVersion->id);
                    }

                    if ($this->isBlank($currentVersion->storage_path)) {
                        $summary['current_version_missing_storage_path']++;
                        $this->appendExample($examples['blocking'], $item->id);
                    }

                    if ($this->isBlank($currentVersion->original_filename)) {
                        $summary['current_version_missing_original_filename']++;
                        $this->appendExample($examples['blocking'], $item->id);
                    }

                    if ($this->isBlank($currentVersion->mime_type)) {
                        $summary['current_version_missing_mime_type']++;
                        $this->appendExample($examples['blocking'], $item->id);
                    }

                    if ($currentVersion->file_size_bytes === null) {
                        $summary['current_version_missing_file_size_bytes']++;
                        $this->appendExample($examples['blocking'], $item->id);
                    }

                    if ($hasStoragePath && $this->hasStringMismatch($currentVersion->storage_path, $item->storage_path)) {
                        $summary['storage_path_mismatches']++;
                        $this->appendExample($examples['review'], $item->id);
                    }

                    if ($hasOriginalFilename && $this->hasStringMismatch($currentVersion->original_filename, $item->original_filename)) {
                        $summary['original_filename_mismatches']++;
                        $this->appendExample($examples['review'], $item->id);
                    }

                    if ($hasMimeType && $this->hasStringMismatch($currentVersion->mime_type, $item->mime_type)) {
                        $summary['mime_type_mismatches']++;
                        $this->appendExample($examples['review'], $item->id);
                    }

                    if ($hasFileSizeBytes && $this->hasIntMismatch($currentVersion->file_size_bytes, $item->file_size_bytes)) {
                        $summary['file_size_bytes_mismatches']++;
                        $this->appendExample($examples['review'], $item->id);
                    }

                    if ($this->isBlank($currentVersion->extraction_status)) {
                        $summary['current_version_missing_extraction_status']++;
                        $this->appendExample($examples['blocking'], $item->id);
                    }

                    if ($currentVersion->extraction_status === KnowledgeItem::EXTRACTION_STATUS_FAILED) {
                        $summary['current_version_failed_extraction_status']++;
                        $this->appendExample($examples['review'], $item->id);
                    }

                    if ($this->isBlank($currentVersion->extraction_error)) {
                        if ($currentVersion->extraction_status === KnowledgeItem::EXTRACTION_STATUS_FAILED) {
                            $summary['current_version_failed_without_extraction_error']++;
                            $this->appendExample($examples['review'], $item->id);
                        } else {
                            $summary['current_version_missing_extraction_error_on_success']++;
                            $this->appendExample($examples['expected'], $item->id);
                        }
                    }

                    if ($this->isBlank($currentVersion->extracted_text)) {
                        $summary['current_version_missing_extracted_text']++;

                        if ($currentVersion->extraction_status === KnowledgeItem::EXTRACTION_STATUS_COMPLETED) {
                            $summary['current_version_missing_extracted_text_on_success']++;
                            $this->appendExample($examples['review'], $item->id);
                        } elseif ($currentVersion->extraction_status === KnowledgeItem::EXTRACTION_STATUS_FAILED) {
                            $this->appendExample($examples['expected'], $item->id);
                        } else {
                            $this->appendExample($examples['blocking'], $item->id);
                        }
                    }

                    if ($hasExtractionStatus && $this->hasStringMismatch($currentVersion->extraction_status, $item->extraction_status)) {
                        $summary['legacy_extraction_status_mismatches']++;
                        $this->appendExample($examples['review'], $item->id);
                    }

                    if ($hasExtractionError && $this->hasStringMismatch($currentVersion->extraction_error, $item->extraction_error)) {
                        $summary['legacy_extraction_error_mismatches']++;
                        $this->appendExample($examples['review'], $item->id);
                    }

                    if ($hasExtractedText && $this->hasStringMismatch($currentVersion->extracted_text, $item->extracted_text)) {
                        $summary['legacy_extracted_text_mismatches']++;
                        $this->appendExample($examples['review'], $item->id);
                    }

                    if (
                        $hasExtractedText
                        && $this->isBlank($currentVersion->extracted_text)
                        && $this->isBlank($item->extracted_text)
                        && ! $this->isBlank($item->content)
                    ) {
                        $summary['content_fallback_candidates']++;
                        $this->appendExample($examples['expected'], $item->id);
                    }

                    if ($hasContentType) {
                        if ($this->hasStringMismatch($item->content_type, $item->document_type)) {
                            $summary['content_type_mismatches']++;
                            $this->appendExample($examples['expected'], $item->id);
                        }
                    }

                    if ($hasIsActive) {
                        if ($this->hasBoolMismatch($item->is_active, $item->document_status === KnowledgeItem::DOCUMENT_STATUS_ACTIVE)) {
                            $summary['is_active_mismatches']++;
                            $this->appendExample($examples['expected'], $item->id);
                        }
                    }
                }
            });

        $status = $this->recommendationStatus($summary);
        $reason = $this->recommendationReason($summary, $status);

        $this->line('Knowledge legacy audit');
        $this->newLine();
        $this->line('Summary');
        $this->line(sprintf('- total_knowledge_items=%d', $summary['total_knowledge_items']));
        $this->line(sprintf('- total_knowledge_item_versions=%d', $summary['total_knowledge_item_versions']));
        $this->line(sprintf('- items_without_current_version=%d', $summary['items_without_current_version']));
        $this->line(sprintf('- items_with_multiple_current_versions=%d', $summary['items_with_multiple_current_versions']));
        $this->line(sprintf('- current_versions_without_parent_item=%d', $summary['current_versions_without_parent_item']));
        $this->newLine();
        $this->line('Current version integrity');
        $this->line(sprintf('- current_version_missing_storage_path=%d', $summary['current_version_missing_storage_path']));
        $this->line(sprintf('- current_version_missing_original_filename=%d', $summary['current_version_missing_original_filename']));
        $this->line(sprintf('- current_version_missing_mime_type=%d', $summary['current_version_missing_mime_type']));
        $this->line(sprintf('- current_version_missing_file_size_bytes=%d', $summary['current_version_missing_file_size_bytes']));
        $this->line(sprintf('- current_version_missing_extraction_status=%d', $summary['current_version_missing_extraction_status']));
        $this->line(sprintf('- current_version_failed_extraction_status=%d', $summary['current_version_failed_extraction_status']));
        $this->line(sprintf('- current_version_failed_without_extraction_error=%d', $summary['current_version_failed_without_extraction_error']));
        $this->line(sprintf('- current_version_missing_extraction_error_on_success=%d', $summary['current_version_missing_extraction_error_on_success']));
        $this->line(sprintf('- current_version_missing_extracted_text=%d', $summary['current_version_missing_extracted_text']));
        $this->line(sprintf('- current_version_missing_extracted_text_on_success=%d', $summary['current_version_missing_extracted_text_on_success']));
        $this->newLine();
        $this->line('Blocking findings');
        $this->line($this->formatFindingLine(
            'items_without_current_version',
            $summary['items_without_current_version'],
            $examples['blocking'],
        ));
        $this->line($this->formatFindingLine(
            'items_with_multiple_current_versions',
            $summary['items_with_multiple_current_versions'],
            $examples['blocking'],
        ));
        $this->line($this->formatFindingLine(
            'current_versions_without_parent_item',
            $summary['current_versions_without_parent_item'],
            $examples['blocking'],
        ));
        $this->line($this->formatFindingLine(
            'current_version_missing_storage_path',
            $summary['current_version_missing_storage_path'],
            $examples['blocking'],
        ));
        $this->line($this->formatFindingLine(
            'current_version_missing_original_filename',
            $summary['current_version_missing_original_filename'],
            $examples['blocking'],
        ));
        $this->line($this->formatFindingLine(
            'current_version_missing_mime_type',
            $summary['current_version_missing_mime_type'],
            $examples['blocking'],
        ));
        $this->line($this->formatFindingLine(
            'current_version_missing_file_size_bytes',
            $summary['current_version_missing_file_size_bytes'],
            $examples['blocking'],
        ));
        $this->line($this->lineBreakIfNoFindings($this->hasFindings([
            $summary['items_without_current_version'],
            $summary['items_with_multiple_current_versions'],
            $summary['current_versions_without_parent_item'],
            $summary['current_version_missing_storage_path'],
            $summary['current_version_missing_original_filename'],
            $summary['current_version_missing_mime_type'],
            $summary['current_version_missing_file_size_bytes'],
        ])));
        $this->newLine();
        $this->line('Review findings');
        if ($hasOriginalFilename) {
            $this->line($this->formatFindingLine('original_filename_mismatches', $summary['original_filename_mismatches'], $examples['review']));
        } else {
            $this->line('- original_filename column: absent, skipped');
        }
        if ($hasStoragePath) {
            $this->line($this->formatFindingLine('storage_path_mismatches', $summary['storage_path_mismatches'], $examples['review']));
        } else {
            $this->line('- storage_path column: absent, skipped');
        }
        if ($hasMimeType) {
            $this->line($this->formatFindingLine('mime_type_mismatches', $summary['mime_type_mismatches'], $examples['review']));
        } else {
            $this->line('- mime_type column: absent, skipped');
        }
        if ($hasFileSizeBytes) {
            $this->line($this->formatFindingLine('file_size_bytes_mismatches', $summary['file_size_bytes_mismatches'], $examples['review']));
        } else {
            $this->line('- file_size_bytes column: absent, skipped');
        }
        $this->line($this->formatFindingLine(
            'current_version_failed_extraction_status',
            $summary['current_version_failed_extraction_status'],
            $examples['review'],
        ));
        $this->line($this->formatFindingLine(
            'current_version_failed_without_extraction_error',
            $summary['current_version_failed_without_extraction_error'],
            $examples['review'],
        ));
        $this->line($this->formatFindingLine(
            'current_version_missing_extracted_text_on_success',
            $summary['current_version_missing_extracted_text_on_success'],
            $examples['review'],
        ));
        if ($hasExtractionStatus) {
            $this->line($this->formatFindingLine('legacy_extraction_status_mismatches', $summary['legacy_extraction_status_mismatches'], $examples['review']));
        } else {
            $this->line('- legacy_extraction_status_mismatches: extraction_status column absent, skipped');
        }
        if ($hasExtractionError) {
            $this->line($this->formatFindingLine('legacy_extraction_error_mismatches', $summary['legacy_extraction_error_mismatches'], $examples['review']));
        } else {
            $this->line('- legacy_extraction_error_mismatches: extraction_error column absent, skipped');
        }
        if ($hasExtractedText) {
            $this->line($this->formatFindingLine('legacy_extracted_text_mismatches', $summary['legacy_extracted_text_mismatches'], $examples['review']));
        } else {
            $this->line('- legacy_extracted_text_mismatches: extracted_text column absent, skipped');
        }
        $this->line($this->lineBreakIfNoFindings($this->hasFindings([
            $summary['storage_path_mismatches'],
            $summary['original_filename_mismatches'],
            $summary['mime_type_mismatches'],
            $summary['file_size_bytes_mismatches'],
            $summary['current_version_failed_extraction_status'],
            $summary['current_version_failed_without_extraction_error'],
            $summary['current_version_missing_extracted_text_on_success'],
            $summary['legacy_extraction_status_mismatches'],
            $summary['legacy_extraction_error_mismatches'],
            $summary['legacy_extracted_text_mismatches'],
        ])));
        $this->newLine();
        $this->line('Expected legacy findings');
        $this->line($this->formatFindingLine(
            'current_version_missing_extraction_error_on_success',
            $summary['current_version_missing_extraction_error_on_success'],
            $examples['expected'],
        ));
        $this->line($this->formatFindingLine(
            'current_version_missing_extracted_text',
            $summary['current_version_missing_extracted_text'],
            $examples['expected'],
        ));
        $this->line($this->formatFindingLine(
            'content_fallback_candidates',
            $summary['content_fallback_candidates'],
            $examples['expected'],
        ));
        if ($hasContentType) {
            $this->line('- content_type column: present');
            $this->line($this->formatFindingLine(
                'content_type_vs_document_type_mismatches',
                $summary['content_type_mismatches'],
                $examples['expected'],
            ));
        } else {
            $this->line('- content_type column: absent, skipped');
        }
        if ($hasIsActive) {
            $this->line('- is_active column: present');
            $this->line($this->formatFindingLine(
                'is_active_vs_document_status_mismatches',
                $summary['is_active_mismatches'],
                $examples['expected'],
            ));
        } else {
            $this->line('- is_active column: absent, skipped');
        }
        if ($hasContentType || $hasIsActive) {
            $this->line('- content_type and is_active are no longer actively synchronized; mismatches are expected legacy findings.');
        }
        $this->line($this->lineBreakIfNoFindings($this->hasFindings([
            $summary['current_version_missing_extraction_error_on_success'],
            $summary['current_version_missing_extracted_text'],
            $summary['content_fallback_candidates'],
            $summary['content_type_mismatches'],
            $summary['is_active_mismatches'],
        ])));
        $this->newLine();
        $this->line('File identity mirrors');
        if ($hasOriginalFilename) {
            $this->line(sprintf('- original_filename_mismatches=%d', $summary['original_filename_mismatches']));
        } else {
            $this->line('- original_filename column: absent, skipped');
        }
        if ($hasStoragePath) {
            $this->line(sprintf('- storage_path_mismatches=%d', $summary['storage_path_mismatches']));
        } else {
            $this->line('- storage_path column: absent, skipped');
        }
        if ($hasMimeType) {
            $this->line(sprintf('- mime_type_mismatches=%d', $summary['mime_type_mismatches']));
        } else {
            $this->line('- mime_type column: absent, skipped');
        }
        if ($hasFileSizeBytes) {
            $this->line(sprintf('- file_size_bytes_mismatches=%d', $summary['file_size_bytes_mismatches']));
        } else {
            $this->line('- file_size_bytes column: absent, skipped');
        }
        $this->newLine();
        $this->line('Extraction mirrors');
        if ($hasExtractionStatus) {
            $this->line(sprintf('- legacy_extraction_status_mismatches=%d', $summary['legacy_extraction_status_mismatches']));
        } else {
            $this->line('- extraction_status column: absent, skipped');
        }
        if ($hasExtractionError) {
            $this->line(sprintf('- legacy_extraction_error_mismatches=%d', $summary['legacy_extraction_error_mismatches']));
        } else {
            $this->line('- extraction_error column: absent, skipped');
        }
        if ($hasExtractedText) {
            $this->line(sprintf('- legacy_extracted_text_mismatches=%d', $summary['legacy_extracted_text_mismatches']));
        } else {
            $this->line('- extracted_text column: absent, skipped');
        }
        $this->newLine();
        $this->line('Legacy compatibility mirrors');
        if ($hasContentType) {
            $this->line(sprintf('- content_type_vs_document_type_mismatches=%d', $summary['content_type_mismatches']));
        } else {
            $this->line('- content_type column: absent, skipped');
        }
        if ($hasIsActive) {
            $this->line(sprintf('- is_active_vs_document_status_mismatches=%d', $summary['is_active_mismatches']));
        } else {
            $this->line('- is_active column: absent, skipped');
        }
        $this->newLine();
        $this->line('Content fallback');
        $this->line(sprintf('- content_fallback_candidates=%d', $summary['content_fallback_candidates']));
        $this->newLine();
        $this->line('Recommendation');
        $this->line(sprintf('- reason=%s', $reason));
        $this->line($status);

        return self::SUCCESS;
    }

    /**
     * @param array<int, int> $values
     */
    private function hasFindings(array $values): bool
    {
        foreach ($values as $value) {
            if ($value > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, int> $bucket
     */
    private function appendExample(array &$bucket, int $id, int $limit = 10): void
    {
        if (count($bucket) >= $limit) {
            return;
        }

        if (! in_array($id, $bucket, true)) {
            $bucket[] = $id;
        }
    }

    /**
     * @param array<int, int> $examples
     */
    private function formatFindingLine(string $label, int $count, array $examples): string
    {
        if ($count === 0) {
            return sprintf('- %s=0', $label);
        }

        $suffix = $examples === [] ? '' : ' examples='.implode(',', array_slice($examples, 0, 10));

        return sprintf('- %s=%d%s', $label, $count, $suffix);
    }

    private function lineBreakIfNoFindings(bool $hasFindings): string
    {
        return $hasFindings ? '' : '- none';
    }

    private function isBlank(mixed $value): bool
    {
        return trim((string) $value) === '';
    }

    private function hasStringMismatch(mixed $currentValue, mixed $legacyValue): bool
    {
        return $this->normalizeString($currentValue) !== $this->normalizeString($legacyValue);
    }

    private function hasIntMismatch(mixed $currentValue, mixed $legacyValue): bool
    {
        return $this->normalizeInt($currentValue) !== $this->normalizeInt($legacyValue);
    }

    private function hasBoolMismatch(mixed $legacyValue, bool $expectedValue): bool
    {
        return (bool) $legacyValue !== $expectedValue;
    }

    private function normalizeString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    protected function hasOriginalFilenameColumn(): bool
    {
        return Schema::hasColumn('knowledge_items', 'original_filename');
    }

    protected function hasStoragePathColumn(): bool
    {
        return Schema::hasColumn('knowledge_items', 'storage_path');
    }

    protected function hasMimeTypeColumn(): bool
    {
        return Schema::hasColumn('knowledge_items', 'mime_type');
    }

    protected function hasFileSizeBytesColumn(): bool
    {
        return Schema::hasColumn('knowledge_items', 'file_size_bytes');
    }

    protected function hasContentTypeColumn(): bool
    {
        return Schema::hasColumn('knowledge_items', 'content_type');
    }

    protected function hasIsActiveColumn(): bool
    {
        return Schema::hasColumn('knowledge_items', 'is_active');
    }

    protected function hasExtractionStatusColumn(): bool
    {
        return Schema::hasColumn('knowledge_items', 'extraction_status');
    }

    protected function hasExtractionErrorColumn(): bool
    {
        return Schema::hasColumn('knowledge_items', 'extraction_error');
    }

    protected function hasExtractedTextColumn(): bool
    {
        return Schema::hasColumn('knowledge_items', 'extracted_text');
    }

    /**
     * @param array<string, int> $summary
     */
    private function recommendationStatus(array $summary): string
    {
        if (
            $summary['current_versions_without_parent_item'] > 0
            || $summary['items_with_multiple_current_versions'] > 0
            || $summary['items_without_current_version'] > 0
            || $summary['current_version_missing_storage_path'] > 0
            || $summary['current_version_missing_original_filename'] > 0
            || $summary['current_version_missing_mime_type'] > 0
            || $summary['current_version_missing_file_size_bytes'] > 0
            || $summary['current_version_missing_extraction_status'] > 0
        ) {
            return 'BLOCKED';
        }

        $reviewKeys = [
            'current_version_failed_without_extraction_error',
            'current_version_missing_extracted_text',
            'storage_path_mismatches',
            'original_filename_mismatches',
            'mime_type_mismatches',
            'file_size_bytes_mismatches',
            'legacy_extraction_status_mismatches',
            'legacy_extraction_error_mismatches',
            'legacy_extracted_text_mismatches',
            'content_fallback_candidates',
        ];

        foreach ($reviewKeys as $key) {
            if (($summary[$key] ?? 0) > 0) {
                return 'NEEDS_REVIEW';
            }
        }

        return 'OK_FOR_NEXT_STEP';
    }

    /**
     * @param array<string, int> $summary
     */
    private function recommendationReason(array $summary, string $status): string
    {
        return match ($status) {
            'BLOCKED' => 'Referential integrity or duplicate current versions must be fixed before cleanup.',
            'NEEDS_REVIEW' => 'Legacy mirrors, missing current versions, or content fallbacks still require cleanup planning.',
            default => 'No legacy dependence or integrity drift detected.',
        };
    }
}
