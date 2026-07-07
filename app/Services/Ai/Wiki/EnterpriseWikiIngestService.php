<?php

namespace App\Services\Ai\Wiki;

use App\Models\EnterpriseWikiIngestRun;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemVersion;
use Illuminate\Support\Str;
use InvalidArgumentException;

class EnterpriseWikiIngestService
{
    public const MAX_EXTRACTED_TEXT_CHARS = 500_000;

    private const SOURCE_HASH_PREFIX = 'knowledge_item_version';

    /**
     * Compute a deterministic SHA-256 source hash for a KnowledgeItemVersion.
     * The hash encodes the version ID and the file's own content hash so that
     * a new file upload (different file_hash_sha256) always yields a different
     * source hash — triggering a re-ingest when the source content changes.
     */
    public function computeSourceHash(int $versionId, string $fileHash): string
    {
        return hash('sha256', self::SOURCE_HASH_PREFIX.":{$versionId}:{$fileHash}");
    }

    /**
     * Assert that the extracted text does not exceed the maximum allowed size.
     * Called before any AI processing to prevent runaway token costs.
     *
     * @throws InvalidArgumentException when the text exceeds MAX_EXTRACTED_TEXT_CHARS
     */
    public function validateExtractedTextSize(string $text): void
    {
        $length = mb_strlen($text);

        if ($length > self::MAX_EXTRACTED_TEXT_CHARS) {
            throw new InvalidArgumentException(sprintf(
                'Extracted text exceeds the maximum allowed size of %d characters (got %d). Reduce document size or split before ingesting.',
                self::MAX_EXTRACTED_TEXT_CHARS,
                $length,
            ));
        }
    }

    /**
     * Resolve a KnowledgeItemVersion that is eligible for wiki ingest.
     * Checks in sequence: existence, approval status, is_current, extracted text, ai_usage_enabled.
     * Each check throws a specific message so callers can surface the exact reason for rejection.
     *
     * @throws InvalidArgumentException for any validation failure
     */
    public function resolveApprovedVersion(int $customerId, int $versionId): KnowledgeItemVersion
    {
        $version = KnowledgeItemVersion::query()
            ->where('id', $versionId)
            ->where('customer_id', $customerId)
            ->select(['id', 'knowledge_item_id', 'customer_id', 'approval_status', 'is_current', 'extracted_text', 'file_hash_sha256', 'original_filename'])
            ->first();

        if ($version === null) {
            throw new InvalidArgumentException(
                "KnowledgeItemVersion [{$versionId}] not found for customer [{$customerId}]."
            );
        }

        if ($version->approval_status !== KnowledgeItemVersion::APPROVAL_STATUS_APPROVED) {
            throw new InvalidArgumentException(
                "KnowledgeItemVersion [{$versionId}] has approval_status '{$version->approval_status}', expected 'approved'."
            );
        }

        if (! $version->is_current) {
            throw new InvalidArgumentException(
                "KnowledgeItemVersion [{$versionId}] is not the current version (is_current = false)."
            );
        }

        if (blank($version->extracted_text)) {
            throw new InvalidArgumentException(
                "KnowledgeItemVersion [{$versionId}] has no extracted text."
            );
        }

        $aiUsageEnabled = KnowledgeItem::query()
            ->where('id', $version->knowledge_item_id)
            ->where('ai_usage_enabled', true)
            ->exists();

        if (! $aiUsageEnabled) {
            throw new InvalidArgumentException(
                "KnowledgeItem for version [{$versionId}] has ai_usage_enabled = false."
            );
        }

        return $version;
    }

    /**
     * Find the most recent completed ingest run for a given source.
     * Used to detect whether a re-ingest is needed when --force is not set.
     */
    public function findCompletedRun(int $customerId, string $sourceType, int $sourceId): ?EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()
            ->where('customer_id', $customerId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', EnterpriseWikiIngestRun::STATUS_COMPLETED)
            ->latest()
            ->first();
    }

    /**
     * Create a new ingest run in the QUEUED state for the given version.
     */
    public function createQueuedRun(int $customerId, KnowledgeItemVersion $version): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_KNOWLEDGE_ITEM_VERSION,
            'source_id' => $version->id,
            'source_hash' => $this->computeSourceHash($version->id, (string) $version->file_hash_sha256),
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status' => EnterpriseWikiIngestRun::STATUS_QUEUED,
        ]);
    }
}
