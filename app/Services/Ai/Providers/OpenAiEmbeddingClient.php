<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\AiEmbeddingClient;
use App\Services\OpenAi\EmbeddingService;

/**
 * Purpose: Adapt the existing OpenAI embedding service to the provider-agnostic embedding contract.
 * Inputs: The current OpenAI embedding service.
 * Returns: An adapter that satisfies the AI embedding interface.
 * Side effects: None on construction.
 */
class OpenAiEmbeddingClient implements AiEmbeddingClient
{
    public function __construct(
        private readonly EmbeddingService $embeddingService,
    ) {
    }

    /**
     * Purpose: Forward an embedding request to the OpenAI embedding service.
     * Inputs: The source text to embed.
     * Returns: The normalized embedding result as an array.
     * Side effects: Delegates to the existing OpenAI embedding service.
     */
    public function tryEmbedText(string $text): array
    {
        return $this->embeddingService->tryEmbedText($text);
    }
}
