<?php

namespace App\Services\Ai\Wiki;

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
}
