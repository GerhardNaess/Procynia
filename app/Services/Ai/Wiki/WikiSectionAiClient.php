<?php

namespace App\Services\Ai\Wiki;

class WikiSectionAiClient
{
    /**
     * Fetch AI-extracted claims for a single document section.
     *
     * Returns an array compatible with EnterpriseWikiSectionParser::parseClaimsFromResponse():
     *   ['claims' => [['text' => ..., 'confidence' => ..., 'excerpt' => ..., 'conflict_note' => ...], ...]]
     *
     * OpenAI integration is implemented in Phase 1F.
     * Tests inject a mock of this class to avoid real HTTP calls.
     *
     * @throws \RuntimeException when called outside a test context before Phase 1F is implemented
     */
    public function fetchClaims(string $sectionText, ?string $heading, string $languageCode): array
    {
        throw new \RuntimeException(
            'WikiSectionAiClient: OpenAI integration not yet implemented (Phase 1F).'
        );
    }
}
