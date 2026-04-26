<?php

namespace App\Services\Ai\Requirements;

use App\Models\SavedNoticeAiRequirement;
use Illuminate\Support\Str;

class RequirementKnowledgeDocumentRecommendationService
{
    private const FALLBACK_TITLE = 'Dokumentasjon for udekket krav';

    private const THEME_PATTERNS = [
        [
            'title' => 'Proaktiv oppfølging av Microsoft-endringer',
            'needles' => [
                'microsoft endringer',
                'microsoft endring',
                'microsoft oppfølging',
                'microsoft oppfolging',
                'microsoft change follow up',
                'microsoft change management',
                'microsoft anbefalte tiltak',
                'microsoft konsekvensvurdering',
                'microsoft prioritering',
                'microsoft endringshåndtering',
                'microsoft endringshandtering',
            ],
        ],
        [
            'title' => 'Språkkrav og norsk dokumentasjon',
            'needles' => [
                'språkkrav',
                'språkinnstillinger',
                'språk',
                'norsk dokumentasjon',
                'norsk',
                'bokmål',
                'b2',
                'veiledning',
                'kommunikasjon',
                'samhandling',
            ],
            'min_hits' => 2,
        ],
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

    public function recommendForRequirement(SavedNoticeAiRequirement $requirement, ?array $groundingJudge = null): array
    {
        $judgeThemeTitle = $groundingJudge !== null
            ? $this->themeTitleFromGroundingJudge($requirement, $groundingJudge)
            : null;
        $judgeRecommendedTitle = $groundingJudge !== null
            ? $this->normalizeThemeValue(data_get($groundingJudge, 'recommended_document_title'))
            : null;
        $judgeSuggestedFilename = $groundingJudge !== null
            ? $this->normalizeFilenameCandidate(data_get($groundingJudge, 'suggested_filename'))
            : null;

        $recommendedTitle = null;
        $useJudgeRecommendation = false;

        if ($groundingJudge !== null) {
            if ($judgeThemeTitle !== null) {
                if ($judgeRecommendedTitle !== null && $this->judgeRecommendationMatchesTheme($judgeRecommendedTitle, $judgeThemeTitle)) {
                    $recommendedTitle = $judgeRecommendedTitle;
                    $useJudgeRecommendation = true;
                } else {
                    $recommendedTitle = $judgeThemeTitle;
                }
            } elseif ($judgeRecommendedTitle !== null) {
                $recommendedTitle = $judgeRecommendedTitle;
                $useJudgeRecommendation = true;
            }
        }

        if ($recommendedTitle === null) {
            [$topic, $subTopic, $keywords] = $this->requirementThemeContext($requirement);
            $recommendedTitle = $this->titleFromMetadata($topic, $subTopic);

            if ($recommendedTitle === null) {
                $sourceText = trim(implode(' ', array_filter([
                    (string) ($requirement->requirement_text ?? ''),
                    implode(' ', $keywords),
                ])));

                $recommendedTitle = $this->titleFromText($sourceText) ?? self::FALLBACK_TITLE;
            }
        }

        $recommendedTitle = $this->normalizeTitle($recommendedTitle);
        $suggestedFilename = $useJudgeRecommendation && $judgeSuggestedFilename !== null
            ? $judgeSuggestedFilename
            : $this->suggestedFilename($recommendedTitle);

        return [
            'recommended_document_title' => $recommendedTitle,
            'suggested_filename' => $suggestedFilename,
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
    private function themeTitleFromGroundingJudge(SavedNoticeAiRequirement $requirement, ?array $groundingJudge): ?string
    {
        if (! is_array($groundingJudge)) {
            return null;
        }

        $sources = [];
        $unsupportedPoints = $this->normalizeStringList(data_get($groundingJudge, 'unsupported_points', []));
        $missingKnowledgeSummary = $this->normalizeThemeValue(data_get($groundingJudge, 'missing_knowledge_summary'));

        if ($unsupportedPoints !== []) {
            $sources[] = implode(' ', $unsupportedPoints);
        }

        if ($missingKnowledgeSummary !== null) {
            $sources[] = $missingKnowledgeSummary;
        }

        $sources[] = (string) ($requirement->requirement_text ?? '');

        [$topic, $subTopic, $keywords] = $this->requirementThemeContext($requirement);

        $metadataTitle = $this->titleFromMetadata($topic, $subTopic);
        if ($metadataTitle !== null) {
            $sources[] = $metadataTitle;
        }

        if ($keywords !== []) {
            $sources[] = implode(' ', $keywords);
        }

        foreach ($sources as $source) {
            $title = $this->titleFromText((string) $source);

            if ($title !== null) {
                return $title;
            }
        }

        return null;
    }

    /**
     * Purpose: Derive a recommended title from a search surface using small deterministic theme patterns.
     * Inputs: A normalized requirement-like text surface.
     * Returns: A human-readable document title or null when no reliable theme can be derived.
     * Side effects: None.
     */
    private function titleFromText(string $requirementText): ?string
    {
        $normalizedText = $this->normalizeTextForMatching($requirementText);

        if ($normalizedText === '') {
            return null;
        }

        foreach (self::THEME_PATTERNS as $pattern) {
            $matches = 0;
            $minimumHits = (int) ($pattern['min_hits'] ?? 1);

            foreach ($pattern['needles'] as $needle) {
                if ($needle === '') {
                    continue;
                }

                if (str_contains($normalizedText, $this->normalizeTextForMatching($needle))) {
                    $matches++;

                    if ($matches >= $minimumHits) {
                        return $pattern['title'];
                    }
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
     * Purpose: Normalize a mixed value into a trimmed unique string list.
     * Inputs: Raw array-like keyword or point input.
     * Returns: A de-duplicated array of strings.
     * Side effects: None.
     */
    private function normalizeStringList(mixed $values): array
    {
        if ($values === null) {
            return [];
        }

        $items = is_array($values)
            ? $values
            : preg_split('/[,\n;]+/u', str_replace(["\r\n", "\r"], "\n", (string) $values), -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($items)) {
            return [];
        }

        $normalized = [];
        $seen = [];

        foreach ($items as $item) {
            $value = $this->normalizeThemeValue($item);

            if ($value === null) {
                continue;
            }

            $key = mb_strtolower($value, 'UTF-8');

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $value;
        }

        return $normalized;
    }

    /**
     * Purpose: Normalize a candidate filename into a safe .docx filename.
     * Inputs: A raw filename candidate.
     * Returns: A sanitized filename or null.
     * Side effects: None.
     */
    private function normalizeFilenameCandidate(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        if ($normalized === '') {
            return null;
        }

        $normalized = basename(str_replace('\\', '/', $normalized));
        $normalized = preg_replace('/[^\pL\pN._-]+/u', '-', $normalized) ?? $normalized;
        $normalized = preg_replace('/-+/u', '-', $normalized) ?? $normalized;
        $normalized = trim($normalized, "-._ ");

        if ($normalized === '') {
            return null;
        }

        if (! Str::endsWith(Str::lower($normalized), '.docx')) {
            $normalized .= '.docx';
        }

        return Str::limit($normalized, 255, '');
    }

    /**
     * Purpose: Validate whether a judge-proposed recommendation matches the detected missing theme.
     * Inputs: The judge title candidate and the derived theme title.
     * Returns: True when the titles point to the same theme.
     * Side effects: None.
     */
    private function judgeRecommendationMatchesTheme(string $judgeRecommendedTitle, string $themeTitle): bool
    {
        $normalizedJudgeTitle = $this->normalizeTitle($judgeRecommendedTitle);
        $normalizedThemeTitle = $this->normalizeTitle($themeTitle);

        if ($normalizedJudgeTitle === '' || $normalizedThemeTitle === '') {
            return false;
        }

        if (mb_strtolower($normalizedJudgeTitle, 'UTF-8') === mb_strtolower($normalizedThemeTitle, 'UTF-8')) {
            return true;
        }

        return $this->titleFromText($normalizedJudgeTitle) === $normalizedThemeTitle;
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
