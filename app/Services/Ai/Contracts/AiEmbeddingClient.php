<?php

namespace App\Services\Ai\Contracts;

/**
 * Purpose: Define a provider-agnostic contract for generating embedding vectors.
 * Inputs: Provider-agnostic text input from domain services.
 * Returns: A normalized embedding result array.
 * Side effects: None by itself; implementations may perform network requests.
 */
interface AiEmbeddingClient
{
    /**
     * Purpose: Send a text embedding request through the configured AI provider.
     * Inputs: The source text to embed.
     * Returns: The normalized embedding result as an array.
     * Side effects: May perform one network request through the active AI provider.
     */
    public function tryEmbedText(string $text): array;
}
