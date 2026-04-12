<?php

namespace App\Services\Ai\Requirements;

use App\Data\Ai\Requirements\DocumentRequirementSegmentData;
use App\Models\SavedNoticeAiDocument;
use App\Models\SavedNoticeAiDocumentChunk;
use Illuminate\Support\Collection;

class DocumentRequirementSegmentBuilder
{
    /**
     * Purpose: Convert persisted AI document chunks into source-preserving document segments.
     * Inputs: The source AI document and its ordered chunks.
     * Returns: A deterministic collection of segment DTOs.
     * Side effects: None.
     */
    public function build(SavedNoticeAiDocument $document, ?Collection $chunks = null): Collection
    {
        $orderedChunks = ($chunks ?? $document->chunks)
            ->filter(static fn ($chunk): bool => $chunk instanceof SavedNoticeAiDocumentChunk)
            ->sortBy('chunk_index')
            ->values();

        if ($orderedChunks->isEmpty()) {
            return collect();
        }

        $segments = [];

        foreach ($orderedChunks as $chunk) {
            /** @var SavedNoticeAiDocumentChunk $chunk */
            $content = trim((string) $chunk->content);

            if ($content === '') {
                continue;
            }

            $segments[] = DocumentRequirementSegmentData::fromChunk($document, $chunk);
        }

        return collect($segments)->values();
    }
}
