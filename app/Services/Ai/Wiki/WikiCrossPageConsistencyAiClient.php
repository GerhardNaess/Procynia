<?php

namespace App\Services\Ai\Wiki;

use App\Services\Ai\Wiki\Responses\EnterpriseWikiResponsesDecoder;
use App\Services\OpenAi\OpenAiClient;
use RuntimeException;

/**
 * Fase 8K-4 — classifies ONE occurrence of superseded substance on ONE existing page.
 *
 * This reviewer CLASSIFIES ONLY. It never rewrites content, never proposes a replacement, and never
 * returns text to be written anywhere: 8K-4's first slice is detection, so the only thing it may
 * influence is which lint finding (if any) gets recorded.
 *
 * Why AI at all: the deterministic layer can prove that a page contains the old substance, that the
 * page is not a patch target, and that it is not document-derived — but "is this sentence asserting
 * the old value as current fact, or recording that it used to be the value?" is a semantic question.
 * Keying it off language-specific cue words ("previously", "tidligere", "erstatter") would be a
 * per-language heuristic that breaks on the next customer's phrasing, so the wording judgement is
 * delegated here while every structural fact stays deterministic.
 *
 * Confidence is load-bearing: EnterpriseWikiCrossPageConsistencyService only raises a BLOCKING
 * finding on `high`. Anything less becomes a non-blocking "unknown" signal, because a false blocking
 * finding stops a technically sound run.
 */
class WikiCrossPageConsistencyAiClient
{
    public const MODEL = 'gpt-4.1-mini';

    public const PROMPT_VERSION = '1.0';

    public const CLASSIFICATION_CURRENT_ASSERTION = 'current_assertion';

    public const CLASSIFICATION_HISTORICAL_ASSERTION = 'historical_assertion';

    public const CLASSIFICATION_CHANGE_DOCUMENT_ASSERTION = 'change_document_assertion';

    public const CLASSIFICATION_CONCEPTUAL_REFERENCE = 'conceptual_reference';

    public const CLASSIFICATION_CURRENT_REPLACEMENT = 'current_replacement';

    public const CLASSIFICATION_UNKNOWN = 'unknown';

    public const CLASSIFICATIONS = [
        self::CLASSIFICATION_CURRENT_ASSERTION,
        self::CLASSIFICATION_HISTORICAL_ASSERTION,
        self::CLASSIFICATION_CHANGE_DOCUMENT_ASSERTION,
        self::CLASSIFICATION_CONCEPTUAL_REFERENCE,
        self::CLASSIFICATION_CURRENT_REPLACEMENT,
        self::CLASSIFICATION_UNKNOWN,
    ];

    public const CONFIDENCE_HIGH = 'high';

    public const CONFIDENCE_MEDIUM = 'medium';

    public const CONFIDENCE_LOW = 'low';

    public const CONFIDENCES = [self::CONFIDENCE_HIGH, self::CONFIDENCE_MEDIUM, self::CONFIDENCE_LOW];

    private const TEMPERATURE = 0;

    private const MAX_OUTPUT_TOKENS = 700;

    private const PROMPT_NAME = 'wiki_cross_page_consistency';

    public function __construct(
        private readonly OpenAiClient $openAiClient,
        private readonly EnterpriseWikiResponsesDecoder $responsesDecoder,
    ) {}

    public static function isAvailable(): bool
    {
        return (bool) config('services.enterprise_wiki.ai_enabled', false);
    }

    /**
     * @param  array{
     *   page_title: string, page_type: string, heading: ?string, excerpt: string,
     *   topic: string, old_substance: string, new_substance: string,
     *   old_substance_present: bool, new_substance_present: bool
     * }  $occurrence
     * @return array{classification: string, confidence: string, evidence_excerpt: string, reason: string, model: string}
     *
     * @throws RuntimeException when AI is disabled, the API fails, or the response is invalid
     */
    public function classify(array $occurrence, string $languageCode): array
    {
        if (! self::isAvailable()) {
            throw new RuntimeException('WikiCrossPageConsistencyAiClient: wiki AI is not enabled.');
        }

        $payload = $this->buildPayload($occurrence, $this->languageName($languageCode));
        $response = $this->openAiClient->createResponse($payload, timeoutSeconds: 120);
        $decoded = $this->responsesDecoder->decode($response, 'WikiCrossPageConsistencyAiClient');

        $this->validateResult($decoded);

        return [
            'classification' => (string) $decoded['classification'],
            'confidence' => (string) $decoded['confidence'],
            'evidence_excerpt' => (string) $decoded['evidence_excerpt'],
            'reason' => (string) $decoded['reason'],
            'model' => self::MODEL.'/'.self::PROMPT_VERSION,
        ];
    }

    private function validateResult(array $decoded): void
    {
        foreach (['classification', 'confidence', 'evidence_excerpt', 'reason'] as $field) {
            if (! array_key_exists($field, $decoded)) {
                throw new RuntimeException("WikiCrossPageConsistencyAiClient: response is missing required field [{$field}].");
            }
        }

        if (! in_array($decoded['classification'], self::CLASSIFICATIONS, true)) {
            throw new RuntimeException(
                'WikiCrossPageConsistencyAiClient: unknown classification ['.(string) $decoded['classification'].'].'
            );
        }

        if (! in_array($decoded['confidence'], self::CONFIDENCES, true)) {
            throw new RuntimeException(
                'WikiCrossPageConsistencyAiClient: unknown confidence ['.(string) $decoded['confidence'].'].'
            );
        }
    }

    private function buildPayload(array $occurrence, string $languageName): array
    {
        return [
            'model' => self::MODEL,
            'input' => [
                [
                    'role' => 'developer',
                    'content' => [['type' => 'input_text', 'text' => $this->developerPrompt($languageName)]],
                ],
                [
                    'role' => 'user',
                    'content' => [['type' => 'input_text', 'text' => $this->userPrompt($occurrence)]],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => self::PROMPT_NAME,
                    'strict' => true,
                    'schema' => self::schema(),
                ],
            ],
            'temperature' => self::TEMPERATURE,
            'store' => false,
            'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
        ];
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'classification' => ['type' => 'string', 'enum' => self::CLASSIFICATIONS],
                'confidence' => ['type' => 'string', 'enum' => self::CONFIDENCES],
                'evidence_excerpt' => ['type' => 'string'],
                'reason' => ['type' => 'string'],
            ],
            'required' => ['classification', 'confidence', 'evidence_excerpt', 'reason'],
        ];
    }

    private function developerPrompt(string $languageName): string
    {
        return implode("\n", [
            'You classify how ONE existing wiki page refers to a piece of substance that an authoritative',
            'source document has just changed. You do not edit anything and you do not suggest edits.',
            '',
            'You are given the OLD substance (now superseded) and the NEW substance (now current), plus an',
            'excerpt of the page as it currently stands.',
            '',
            'Choose exactly one classification:',
            '',
            '- current_assertion: the excerpt states the OLD substance as a fact that is in force now.',
            '  Reading the page, a reader would believe the old value is the rule today. This is the case',
            '  that contradicts the new current truth.',
            '- historical_assertion: the excerpt explicitly frames the OLD substance as a former state — it',
            '  says, in whatever words the language uses, that this used to be the case and/or has since',
            '  changed. A reader would not believe the old value is in force now.',
            '- change_document_assertion: the excerpt reads as a record OF the change itself (a decision',
            '  record, change note, or document summary whose purpose is to state old and new).',
            '- conceptual_reference: the excerpt discusses the topic generally, or points at another page as',
            '  the authority, without itself asserting a concrete value.',
            '- current_replacement: the excerpt already states the NEW substance as current.',
            '- unknown: you cannot tell from the excerpt provided.',
            '',
            'Judge only from the excerpt. Do not infer intent from the page title alone. Do not rely on any',
            'single cue word: decide from what the sentence actually claims about what is true now.',
            '',
            'Confidence rules — these matter, because "high" can block a run:',
            '- high: the excerpt makes the temporal status unambiguous on its own.',
            '- medium: the reading is likely but the excerpt is terse or could be read either way.',
            '- low: you are largely guessing.',
            'If the excerpt is truncated exactly where the decisive wording would be, do not answer high.',
            '',
            'evidence_excerpt: quote the shortest span from the excerpt that justifies your choice, verbatim.',
            'reason: one sentence, in '.$languageName.'.',
        ]);
    }

    private function userPrompt(array $occurrence): string
    {
        $heading = trim((string) ($occurrence['heading'] ?? ''));

        return implode("\n", array_filter([
            'TOPIC THAT CHANGED: '.$occurrence['topic'],
            'OLD SUBSTANCE (superseded): '.$occurrence['old_substance'],
            'NEW SUBSTANCE (now current): '.$occurrence['new_substance'],
            '',
            'PAGE TITLE: '.$occurrence['page_title'],
            'PAGE TYPE: '.$occurrence['page_type'],
            $heading !== '' ? 'SECTION HEADING: '.$heading : null,
            'OLD SUBSTANCE APPEARS VERBATIM: '.(($occurrence['old_substance_present'] ?? false) ? 'yes' : 'no'),
            'NEW SUBSTANCE APPEARS VERBATIM: '.(($occurrence['new_substance_present'] ?? false) ? 'yes' : 'no'),
            '',
            'PAGE EXCERPT AS IT CURRENTLY STANDS:',
            $occurrence['excerpt'],
        ], static fn (?string $line): bool => $line !== null));
    }

    private function languageName(string $code): string
    {
        return match ($code) {
            'en' => 'English',
            default => 'Norwegian',
        };
    }
}
