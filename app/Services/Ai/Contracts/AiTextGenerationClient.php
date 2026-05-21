<?php

namespace App\Services\Ai\Contracts;

/**
 * Purpose: Define a provider-agnostic contract for generating AI text responses.
 * Inputs: Provider-specific request payloads from domain services.
 * Returns: Decoded response arrays from the active AI provider.
 * Side effects: None by itself; implementations may perform network requests.
 */
interface AiTextGenerationClient
{
    /**
     * Purpose: Send a text-generation request through the configured AI provider.
     * Inputs: The provider-specific request payload and timeout in seconds.
     * Returns: The decoded provider response as an array.
     * Side effects: May perform one network request through the active AI provider.
     */
    public function createResponse(array $payload, int $timeoutSeconds = 120): array;
}
