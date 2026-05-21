<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\AiTextGenerationClient;
use App\Services\OpenAi\OpenAiClient;

/**
 * Purpose: Adapt the existing OpenAI client to the provider-agnostic text generation contract.
 * Inputs: The current OpenAI client service.
 * Returns: An adapter that satisfies the AI text generation interface.
 * Side effects: None on construction.
 */
class OpenAiTextGenerationClient implements AiTextGenerationClient
{
    public function __construct(
        private readonly OpenAiClient $openAiClient,
    ) {
    }

    /**
     * Purpose: Forward a text-generation request to the OpenAI adapter.
     * Inputs: The request payload and timeout in seconds.
     * Returns: The decoded provider response as an array.
     * Side effects: Delegates to the existing OpenAI HTTP client.
     */
    public function createResponse(array $payload, int $timeoutSeconds = 120): array
    {
        return $this->openAiClient->createResponse($payload, $timeoutSeconds);
    }
}
