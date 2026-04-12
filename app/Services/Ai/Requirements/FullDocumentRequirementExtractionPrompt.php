<?php

namespace App\Services\Ai\Requirements;

/**
 * Canonical source of truth for Procynia's Phase 1 requirement extraction prompt.
 *
 * The class name is kept for compatibility with the existing codebase, but the
 * prompt itself now represents the canonical Phase 1 full-document extraction path.
 */
final class FullDocumentRequirementExtractionPrompt
{
    public const PROMPT_VERSION = '2026-04-12.phase_1.v1';

    public const PROMPT_NAME = 'phase_1_requirement_extraction';

    public const MODEL = 'gpt-4.1-mini';

    public const MAX_OUTPUT_TOKENS = 3000;

    public const TEMPERATURE = 0;

    public static function promptName(): string
    {
        return self::PROMPT_NAME;
    }

    public static function promptVersion(): string
    {
        return self::PROMPT_VERSION;
    }

    public static function model(): string
    {
        return self::MODEL;
    }

    public static function maxOutputTokens(): int
    {
        return self::MAX_OUTPUT_TOKENS;
    }

    public static function temperature(): int
    {
        return self::TEMPERATURE;
    }

    public static function text(): string
    {
        return implode("\n", [
            'Du er Procynias Phase 1-modell for krav-ekstraksjon.',
            'Les hele dokumentet og returner bare ekte krav.',
            'Ikke berik, forklar, evaluer eller normaliser utover det som er nødvendig for å trekke ut kravet.',
            'Del krav atomisk når det naturlig kan besvares separat.',
            'Alle felter i output-skjemaet er obligatoriske, så bruk null når et felt ikke kan fylles trygt ut.',
            'Hvis det ikke finnes krav, returner en tom kandidatliste.',
            'Bruk original_text til å gjengi den faktiske kravteksten så presist som mulig.',
            'Bruk source_reference_text bare når en kort og stabil plassering i dokumentet er lett å utlede.',
            'Sett is_requirement til true for faktiske krav og false bare når teksten ikke er et krav.',
            'Returner kun JSON som matcher schemaet.',
        ]);
    }

    public static function inputTextForDocument(string $documentText): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", trim($documentText));
        $normalized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/[ \t]+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\n{3,}/u', "\n\n", $normalized) ?? $normalized;

        return trim($normalized);
    }

    public static function requestPayload(string $documentText): array
    {
        return [
            'model' => self::model(),
            'input' => [
                [
                    'role' => 'developer',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => self::text(),
                        ],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => self::inputTextForDocument($documentText),
                        ],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => self::promptName(),
                    'description' => 'Phase 1 lightweight requirement extraction result for Procynia.',
                    'strict' => true,
                    'schema' => self::schema(),
                ],
            ],
            'temperature' => self::temperature(),
            'max_output_tokens' => self::maxOutputTokens(),
        ];
    }

    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'candidates' => [
                    'type' => 'array',
                    'items' => self::candidateSchema(),
                ],
            ],
            'required' => [
                'candidates',
            ],
            'additionalProperties' => false,
        ];
    }

    private static function candidateSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'requirement_identifier' => [
                    'anyOf' => [
                        ['type' => 'string'],
                        ['type' => 'null'],
                    ],
                ],
                'parent_reference' => [
                    'anyOf' => [
                        ['type' => 'string'],
                        ['type' => 'null'],
                    ],
                ],
                'original_text' => [
                    'type' => 'string',
                ],
                'source_reference_text' => [
                    'anyOf' => [
                        ['type' => 'string'],
                        ['type' => 'null'],
                    ],
                ],
                'is_requirement' => [
                    'type' => 'boolean',
                ],
                'confidence' => [
                    'type' => 'number',
                    'minimum' => 0,
                    'maximum' => 1,
                ],
            ],
            'required' => [
                'requirement_identifier',
                'parent_reference',
                'original_text',
                'source_reference_text',
                'is_requirement',
                'confidence',
            ],
            'additionalProperties' => false,
        ];
    }
}
