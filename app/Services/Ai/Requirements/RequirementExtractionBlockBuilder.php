<?php

namespace App\Services\Ai\Requirements;

use App\Data\Ai\Requirements\RequirementExtractionBlockData;
use App\Models\SavedNoticeAiDocument;
use App\Models\SavedNoticeAiDocumentChunk;
use Illuminate\Support\Collection;

class RequirementExtractionBlockBuilder
{
    /**
     * Purpose: Convert persisted document chunks into larger contextual extraction blocks.
     * Inputs: The source AI document and its ordered chunks.
     * Returns: A deterministic collection of block DTOs for OpenAI extraction.
     * Side effects: None.
     */
    public function build(SavedNoticeAiDocument $document, Collection $chunks): Collection
    {
        $orderedChunks = $chunks
            ->filter(static fn ($chunk): bool => $chunk instanceof SavedNoticeAiDocumentChunk)
            ->sortBy('chunk_index')
            ->values();

        if ($orderedChunks->isEmpty()) {
            return collect();
        }

        $blocks = [];
        $count = $orderedChunks->count();

        for ($index = 0; $index < $count; $index++) {
            /** @var SavedNoticeAiDocumentChunk $chunk */
            $chunk = $orderedChunks->get($index);
            $previousChunk = $index > 0 ? $orderedChunks->get($index - 1) : null;
            $nextChunk = $index < $count - 1 ? $orderedChunks->get($index + 1) : null;
            $content = trim((string) $chunk->content);

            if ($content === '') {
                continue;
            }

            $blocks[] = RequirementExtractionBlockData::fromChunk(
                document: $document,
                chunk: $chunk,
                previousChunk: $previousChunk,
                nextChunk: $nextChunk,
            );
        }

        return collect($blocks)->values();
    }
}
