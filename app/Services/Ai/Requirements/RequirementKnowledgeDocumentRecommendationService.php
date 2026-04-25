<?php

namespace App\Services\Ai\Requirements;

use App\Models\SavedNoticeAiRequirement;
use Illuminate\Support\Str;

class RequirementKnowledgeDocumentRecommendationService
{
    private const FALLBACK_TITLE = 'Dokumentasjon for udekket krav';

    private const THEME_PATTERNS = [
        [
            'title' => 'Lærlingeordning og kompetanseutvikling',
            'needles' => [
                'lærling',
                'læreforhold',
                'fagbrev',
                'lærlingeordning',
                'lærekontrakt',
                'kompetanseutvikling',
                'apprentice',
            ],
        ],
        [
            'title' => 'Miljøledelse og bærekraft',
            'needles' => [
                'miljøledelse',
                'miljø',
                'bærekraft',
                'baerekraft',
                'iso 14001',
                'klima',
                'miljøstyring',
            ],
        ],
        [
            'title' => 'Beredskap og hendelseshåndtering',
            'needles' => [
                'beredskap',
                'hendelseshåndtering',
                'hendelseshandtering',
                'incident',
                'krise',
                'driftsavbrudd',
                'gjenoppretting',
            ],
        ],
        [
            'title' => 'Tilgangsstyring og identitetsforvaltning',
            'needles' => [
                'tilgangsstyring',
                'identitetsforvaltning',
                'autentisering',
                'autorisering',
                'iam',
                'access management',
            ],
        ],
        [
            'title' => 'Sikkerhetsoperasjoner og overvåkning',
            'needles' => [
                'soc',
                'siem',
                'overvåkning',
                'overvåking',
                'sikkerhetsoperasjoner',
                'hendelsesmonitorering',
                'deteksjon',
            ],
        ],
    ];

    public function recommendForRequirement(SavedNoticeAiRequirement $requirement): array
    {
        [$topic, $subTopic, $keywords] = $this->requirementThemeContext($requirement);

        $recommendedTitle = $this->titleFromMetadata($topic, $subTopic);

        if ($recommendedTitle === null) {
            $sourceText = trim(implode(' ', array_filter([
                (string) ($requirement->requirement_text ?? ''),
                implode(' ', $keywords),
            ])));

            $recommendedTitle = $this->titleFromRequirementText($sourceText) ?? self::FALLBACK_TITLE;
        }

        $recommendedTitle = $this->normalizeTitle($recommendedTitle);

        return [
            'recommended_document_title' => $recommendedTitle,
            'suggested_filename' => $this->suggestedFilename($recommendedTitle),
        ];
    }

    /**
     * Purpose: Resolve any explicit requirement theme metadata available on snapshots.
     * Inputs: The requirement row.
     * Returns: Topic, sub-topic, and keyword context if present.
     * Side effects: None.
     */
    private function requirementThemeContext(SavedNoticeAiRequirement $requirement): array
    {
        $topic = $this->normalizeThemeValue(
            data_get($requirement->current_requirement_snapshot, 'topic')
            ?? data_get($requirement->original_candidate_snapshot, 'topic'),
        );
        $subTopic = $this->normalizeThemeValue(
            data_get($requirement->current_requirement_snapshot, 'sub_topic')
            ?? data_get($requirement->original_candidate_snapshot, 'sub_topic'),
        );
        $keywords = $this->normalizeKeywords(
            data_get($requirement->current_requirement_snapshot, 'keywords')
            ?? data_get($requirement->original_candidate_snapshot, 'keywords'),
        );

        return [$topic, $subTopic, $keywords];
    }

    /**
     * Purpose: Build a title directly from explicit topic metadata when available.
     * Inputs: A topic and optional sub-topic.
     * Returns: A human-readable document title or null when no theme metadata is available.
     * Side effects: None.
     */
    private function titleFromMetadata(?string $topic, ?string $subTopic): ?string
    {
        $parts = array_values(array_filter([
            $this->normalizeThemeValue($topic),
            $this->normalizeThemeValue($subTopic),
        ], static fn (?string $value): bool => filled($value)));

        if ($parts === []) {
            return null;
        }

        $uniqueParts = [];
        $seen = [];

        foreach ($parts as $part) {
            $normalizedKey = mb_strtolower($part, 'UTF-8');

            if (isset($seen[$normalizedKey])) {
                continue;
            }

            $seen[$normalizedKey] = true;
            $uniqueParts[] = $part;
        }

        return implode(' og ', $uniqueParts);
    }

    /**
     * Purpose: Normalize a recommended title into a clean human-readable string.
     * Inputs: A title candidate.
     * Returns: A trimmed title with collapsed whitespace.
     * Side effects: None.
     */
    private function normalizeTitle(string $title): string
    {
        return trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
    }

    /**
     * Purpose: Derive a recommended title from the requirement text when metadata is unavailable.
     * Inputs: The requirement text and keyword context.
     * Returns: A human-readable document title or null when no reliable theme can be derived.
     * Side effects: None.
     */
    private function titleFromRequirementText(string $requirementText): ?string
    {
        $normalizedText = $this->normalizeTextForMatching($requirementText);

        if ($normalizedText === '') {
            return null;
        }

        foreach (self::THEME_PATTERNS as $pattern) {
            foreach ($pattern['needles'] as $needle) {
                if ($needle === '') {
                    continue;
                }

                if (str_contains($normalizedText, $this->normalizeTextForMatching($needle))) {
                    return $pattern['title'];
                }
            }
        }

        return null;
    }

    /**
     * Purpose: Normalize a candidate filename from a recommended title.
     * Inputs: A human-readable document title.
     * Returns: A stable .docx filename.
     * Side effects: None.
     */
    private function suggestedFilename(string $recommendedTitle): string
    {
        $slug = Str::slug($recommendedTitle, '-');

        if ($slug === '') {
            $slug = 'dokumentasjon-for-udekket-krav';
        }

        return $slug.'.docx';
    }

    /**
     * Purpose: Normalize a theme field while preserving human casing.
     * Inputs: A candidate value.
     * Returns: A trimmed string or null when empty.
     * Side effects: None.
     */
    private function normalizeThemeValue(mixed $value): ?string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', (string) ($value ?? '')) ?? '');

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * Purpose: Normalize keyword input into a simple unique string list.
     * Inputs: Array, comma-separated string, or null.
     * Returns: A de-duplicated keyword list.
     * Side effects: None.
     */
    private function normalizeKeywords(mixed $keywords): array
    {
        if ($keywords === null) {
            return [];
        }

        $items = is_array($keywords)
            ? $keywords
            : preg_split('/[,\n;]+/u', str_replace(["\r\n", "\r"], "\n", (string) $keywords), -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($items)) {
            return [];
        }

        $normalizedKeywords = [];
        $seen = [];

        foreach ($items as $item) {
            $keyword = $this->normalizeThemeValue($item);

            if ($keyword === null) {
                continue;
            }

            $normalizedKey = mb_strtolower($keyword, 'UTF-8');

            if (isset($seen[$normalizedKey])) {
                continue;
            }

            $seen[$normalizedKey] = true;
            $normalizedKeywords[] = $keyword;
        }

        return $normalizedKeywords;
    }

    /**
     * Purpose: Normalize free-form text into a conservative search surface.
     * Inputs: Raw requirement text.
     * Returns: Lower-cased searchable text.
     * Side effects: None.
     */
    private function normalizeTextForMatching(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\pL\pN\s]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
