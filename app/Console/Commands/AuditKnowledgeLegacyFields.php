<?php

namespace App\Console\Commands;

use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemVersion;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
        $summary = [
            'total_knowledge_items' => KnowledgeItem::query()->count(),
            'total_knowledge_item_versions' => KnowledgeItemVersion::query()->count(),
            'items_without_current_version' => 0,
            'items_with_multiple_current_versions' => 0,
            'current_versions_without_parent_item' => (int) DB::table('knowledge_item_versions as kiv')
                ->leftJoin('knowledge_items as ki', 'ki.id', '=', 'kiv.knowledge_item_id')
                ->where('kiv.is_current', true)
                ->whereNull('ki.id')
                ->count(),
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
            'current_version_missing_extraction_error' => 0,
            'current_version_missing_extracted_text' => 0,
            'legacy_extraction_status_mismatches' => 0,
            'legacy_extraction_error_mismatches' => 0,
            'legacy_extracted_text_mismatches' => 0,
            'content_fallback_candidates' => 0,
            'content_type_mismatches' => 0,
            'is_active_mismatches' => 0,
        ];

        KnowledgeItem::query()
            ->select([
                'id',
                'document_type',
                'content_type',
                'document_status',
                'is_active',
                'storage_path',
                'original_filename',
                'mime_type',
                'file_size_bytes',
                'extracted_text',
                'extraction_status',
                'extraction_error',
                'content',
            ])
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
            ->chunkById(250, function ($items) use (&$summary): void {
                foreach ($items as $item) {
                    if ((int) $item->current_versions_count === 0) {
                        $summary['items_without_current_version']++;
                    }

                    if ((int) $item->current_versions_count > 1) {
                        $summary['items_with_multiple_current_versions']++;
                    }

                    $currentVersion = $item->currentVersion;

                    if ($currentVersion === null) {
                        continue;
                    }

                    if ($this->isBlank($currentVersion->storage_path)) {
                        $summary['current_version_missing_storage_path']++;
                    }

                    if ($this->isBlank($currentVersion->original_filename)) {
                        $summary['current_version_missing_original_filename']++;
                    }

                    if ($this->isBlank($currentVersion->mime_type)) {
                        $summary['current_version_missing_mime_type']++;
                    }

                    if ($currentVersion->file_size_bytes === null) {
                        $summary['current_version_missing_file_size_bytes']++;
                    }

                    if ($this->hasStringMismatch($currentVersion->storage_path, $item->storage_path)) {
                        $summary['storage_path_mismatches']++;
                    }

                    if ($this->hasStringMismatch($currentVersion->original_filename, $item->original_filename)) {
                        $summary['original_filename_mismatches']++;
                    }

                    if ($this->hasStringMismatch($currentVersion->mime_type, $item->mime_type)) {
                        $summary['mime_type_mismatches']++;
                    }

                    if ($this->hasIntMismatch($currentVersion->file_size_bytes, $item->file_size_bytes)) {
                        $summary['file_size_bytes_mismatches']++;
                    }

                    if ($this->isBlank($currentVersion->extraction_status)) {
                        $summary['current_version_missing_extraction_status']++;
                    }

                    if ($currentVersion->extraction_status === KnowledgeItem::EXTRACTION_STATUS_FAILED) {
                        $summary['current_version_failed_extraction_status']++;
                    }

                    if ($this->isBlank($currentVersion->extraction_error)) {
                        $summary['current_version_missing_extraction_error']++;
                    }

                    if ($this->isBlank($currentVersion->extracted_text)) {
                        $summary['current_version_missing_extracted_text']++;
                    }

                    if ($this->hasStringMismatch($currentVersion->extraction_status, $item->extraction_status)) {
                        $summary['legacy_extraction_status_mismatches']++;
                    }

                    if ($this->hasStringMismatch($currentVersion->extraction_error, $item->extraction_error)) {
                        $summary['legacy_extraction_error_mismatches']++;
                    }

                    if ($this->hasStringMismatch($currentVersion->extracted_text, $item->extracted_text)) {
                        $summary['legacy_extracted_text_mismatches']++;
                    }

                    if (
                        $this->isBlank($currentVersion->extracted_text)
                        && $this->isBlank($item->extracted_text)
                        && ! $this->isBlank($item->content)
                    ) {
                        $summary['content_fallback_candidates']++;
                    }

                    if ($this->hasStringMismatch($item->content_type, $item->document_type)) {
                        $summary['content_type_mismatches']++;
                    }

                    if ($this->hasBoolMismatch($item->is_active, $item->document_status === KnowledgeItem::DOCUMENT_STATUS_ACTIVE)) {
                        $summary['is_active_mismatches']++;
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
        $this->line(sprintf('- current_version_missing_extraction_error=%d', $summary['current_version_missing_extraction_error']));
        $this->line(sprintf('- current_version_missing_extracted_text=%d', $summary['current_version_missing_extracted_text']));
        $this->newLine();
        $this->line('File identity mirrors');
        $this->line(sprintf('- storage_path_mismatches=%d', $summary['storage_path_mismatches']));
        $this->line(sprintf('- original_filename_mismatches=%d', $summary['original_filename_mismatches']));
        $this->line(sprintf('- mime_type_mismatches=%d', $summary['mime_type_mismatches']));
        $this->line(sprintf('- file_size_bytes_mismatches=%d', $summary['file_size_bytes_mismatches']));
        $this->newLine();
        $this->line('Extraction mirrors');
        $this->line(sprintf('- legacy_extraction_status_mismatches=%d', $summary['legacy_extraction_status_mismatches']));
        $this->line(sprintf('- legacy_extraction_error_mismatches=%d', $summary['legacy_extraction_error_mismatches']));
        $this->line(sprintf('- legacy_extracted_text_mismatches=%d', $summary['legacy_extracted_text_mismatches']));
        $this->newLine();
        $this->line('Type/status mirrors');
        $this->line(sprintf('- content_type_vs_document_type_mismatches=%d', $summary['content_type_mismatches']));
        $this->line(sprintf('- is_active_vs_document_status_mismatches=%d', $summary['is_active_mismatches']));
        $this->newLine();
        $this->line('Content fallback');
        $this->line(sprintf('- content_fallback_candidates=%d', $summary['content_fallback_candidates']));
        $this->newLine();
        $this->line('Recommendation');
        $this->line(sprintf('- reason=%s', $reason));
        $this->line($status);

        return self::SUCCESS;
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

    /**
     * @param array<string, int> $summary
     */
    private function recommendationStatus(array $summary): string
    {
        if (
            $summary['current_versions_without_parent_item'] > 0
            || $summary['items_with_multiple_current_versions'] > 0
        ) {
            return 'BLOCKED';
        }

        $reviewKeys = [
            'items_without_current_version',
            'current_version_missing_storage_path',
            'current_version_missing_original_filename',
            'current_version_missing_mime_type',
            'current_version_missing_file_size_bytes',
            'current_version_missing_extraction_status',
            'current_version_missing_extraction_error',
            'current_version_missing_extracted_text',
            'storage_path_mismatches',
            'original_filename_mismatches',
            'mime_type_mismatches',
            'file_size_bytes_mismatches',
            'legacy_extraction_status_mismatches',
            'legacy_extraction_error_mismatches',
            'legacy_extracted_text_mismatches',
            'content_fallback_candidates',
            'content_type_mismatches',
            'is_active_mismatches',
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
