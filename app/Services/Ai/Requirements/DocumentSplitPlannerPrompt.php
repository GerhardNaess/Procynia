<?php

namespace App\Services\Ai\Requirements;

/**
 * Purpose: Build the canonical OpenAI prompt payload for document split planning.
 * Inputs: The raw extracted document text.
 * Returns: A strict JSON-schema payload that asks the model to produce a chunk plan, not requirements.
 * Side effects: None.
 */
final class DocumentSplitPlannerPrompt
{
    public const PROMPT_VERSION = '2026-04-13.document_split_planner.v2';

    public const PROMPT_NAME = 'document_split_planner';

    public const MODEL = 'gpt-4.1-mini';

    public const MAX_OUTPUT_TOKENS = 2200;

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
        'Du skal lage en GENERISK split-plan for et dokument.',
        'Målet er IKKE å ekstrahere krav.',
        'Målet er å dele dokumentet i FÅ, STORE og STABILE hoveddeler.',

        'ABSOLUTT HOVEDREGEL:',
        'Split KUN på dokumentets hovedkapitler (toppnivå).',
        'Alt innhold under et hovedkapittel skal være i SAMME gruppe.',

        'DETTE ER IKKE LOV:',
        'Ikke splitt på underkapitler.',
        'Ikke splitt på krav-IDer (f.eks. 1-1.S.1).',
        'Ikke splitt på enkeltkrav.',
        'Ikke splitt på tabellrader.',
        'Ikke splitt på evalueringspunkter eller bullet points.',
        'Ikke splitt på gjentatte labels som "ID", "Kravtekst", "Leverandørens besvarelse".',

        'Hvis et kapittel inneholder mange krav, skal de fortsatt være i ÉN gruppe.',
        'Foretrekk alltid for få grupper fremfor for mange.',

        'Hvis dokumentet starter med forside, innholdsfortegnelse eller veiledning, kan dette være én egen context_only-gruppe.',
        'KRITISK: En context_only-gruppe må stoppe FØR første faktiske hovedkapittel i dokumentkroppen.',
        'Context_only må aldri inkludere brødtekst som tilhører Bilag 1 eller andre hovedkapitler.',
        'Hvis første hovedkapittel er "Bilag 1: ...", skal context_only slutte rett før den faktiske forekomsten av "Bilag 1: ..." i dokumentkroppen.',

        'Chunkene skal dekke hele dokumentet i korrekt rekkefølge.',
        'Chunkene skal ikke overlappe.',

        'start_anchor og end_anchor må være EKSAKTE tekstutdrag fra dokumentet.',
        'Ikke endre whitespace, tegnsetting eller nummerering.',

        'KRITISK:',
        'Ikke bruk ankere fra innholdsfortegnelsen.',
        'Ikke bruk sidetall i ankere.',
        'Ikke bruk tekst fra topptekst eller bunntekst.',
        'Ankere må hentes fra faktisk brødtekst i dokumentet.',

        'Lag IKKE grupper for kapitler som kun er nevnt i innholdsfortegnelsen.',
        'Hvis et kapittel ikke finnes senere i dokumentets brødtekst, skal det IKKE være en egen gruppe.',
        'Hvis samme overskrift finnes både i innholdsfortegnelsen og i dokumentet, bruk den fra brødteksten.',

        'For første reelle hovedkapittel skal start_anchor være selve kapitteloverskriften i dokumentkroppen.',
        'For hver gruppe skal end_anchor være en senere tekstlinje i samme gruppe, men aldri fra neste hovedkapittel.',
        'end_anchor må ligge etter start_anchor i dokumentet.',

        'Bruk korte, stabile tekstankre som faktisk finnes i dokumentet.',
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
                    'description' => 'Document split planning result for Procynia.',
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
                'document_summary' => [
                    'type' => 'object',
                    'properties' => [
                        'document_type' => [
                            'type' => 'string',
                        ],
                        'overall_assessment' => [
                            'type' => 'string',
                        ],
                    ],
                    'required' => [
                        'document_type',
                        'overall_assessment',
                    ],
                    'additionalProperties' => false,
                ],
                'split_plan' => [
                    'type' => 'array',
                    'items' => self::groupSchema(),
                ],
            ],
            'required' => [
                'document_summary',
                'split_plan',
            ],
            'additionalProperties' => false,
        ];
    }

    private static function groupSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'group_id' => [
                    'type' => 'string',
                ],
                'group_type' => [
                    'type' => 'string',
                    'enum' => [
                        'context_only',
                        'requirements_section',
                        'mixed_section',
                        'table_section',
                        'attachment_section',
                    ],
                ],
                'title' => [
                    'type' => 'string',
                ],
                'start_anchor' => [
                    'type' => 'string',
                ],
                'end_anchor' => [
                    'type' => 'string',
                ],
                'reason' => [
                    'type' => 'string',
                ],
            ],
            'required' => [
                'group_id',
                'group_type',
                'title',
                'start_anchor',
                'end_anchor',
                'reason',
            ],
            'additionalProperties' => false,
        ];
    }
}
