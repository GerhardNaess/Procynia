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
    public const PROMPT_VERSION = '2026-04-15.phase_1.v2';

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
            'Les hele dokumentet og identifiser formelle krav.',
            'Returner kun JSON som matcher schemaet.',
            'Ikke inkluder forklaringer, kommentarer eller tekst før eller etter JSON.',
            'Responsen må starte med { og slutte med }.',
            'Alle felter i output-skjemaet er obligatoriske, så bruk null når et felt ikke kan fylles trygt ut.',
            'Hvis dokumentet ikke inneholder formelle krav, returner en tom kandidatliste.',

            'AVGJØR FØRST OM TEKSTEN STÅR I EN TYDELIG KRAVKONTEKST.',
            'En tekst er bare et formelt krav når den tydelig står i en kravkontekst.',
            'Gyldige kravkontekster er kravtabeller med felter som "ID", "Krav", "Kravtekst" eller "Beskrivelse", seksjoner merket "Skal-krav", "Bør-krav", "Må-krav" eller "Krav", eller tekst som er direkte knyttet til en requirement_identifier.',
            'Ord som "skal", "bør" eller "må" er ikke nok alene.',
            'Tekst i kontekster som formål, omfang, bakgrunn, mål, strategiske mål, ambisjoner, prioriteringer, begrunnelser, prinsipper, veiledning til leverandør, tjenestebeskrivelser, samarbeidsbeskrivelser, forklaringer og generell informasjon er ikke formelle krav.',
            'Hvis du er i tvil om teksten står i en tydelig kravkontekst, skal den ikke klassifiseres som et formelt krav.',

            'ETT REQUIREMENT_IDENTIFIER SKAL GI ETT KANDIDATOBJEKT.',
            'For hver requirement_identifier skal du returnere nøyaktig ett kandidatobjekt.',
            'All tekst som hører til samme requirement_identifier skal samles i ett og samme original_text.',
            'Ikke del opp samme requirement_identifier i flere kandidater, selv om teksten består av flere linjer, setninger, avsnitt, bullets, underpunkter eller evalueringsmomenter.',
            'Du kan bare returnere flere kandidatobjekter når teksten ikke har requirement_identifier og dokumentet tydelig inneholder separate formelle krav uten ID.',

            'HELE KRAVBLOKKEN SKAL BEVARES SAMLET.',
            'Et krav kan bestå av en kort overskrift etterfulgt av forklarende tekst, bullets eller underpunkter.',
            'Du må inkludere alle linjer som tilhører samme kravblokk.',
            'Ikke fjern korte linjer.',
            'Ikke del opp et krav basert på linjeskift.',
            'Ikke skill ut underpunkter som egne krav når de hører til samme requirement_identifier eller samme kravblokk.',

            'KRAV MED EGEN ID ER FORMELLE KRAV.',
            'Tekst med egen requirement_identifier i en kravtabell eller kravseksjon er et formelt krav.',
            'Dette gjelder også når requirement_identifier tilhører en evalueringskategori, for eksempel E.',
            'E-krav med egen requirement_identifier skal behandles som egne formelle krav.',
            'Ikke filtrer bort et krav bare fordi det gjelder evaluering når det står som eget nummerert krav i dokumentet.',
            'Når dokumentstrukturen tydelig viser at teksten er et eget krav med egen ID, skal dette veie tyngre enn semantisk tolkning av ord som evaluering, målsetting eller prioritet.',

            'EVALUERINGSTEKST UTEN EGEN ID ER IKKE ET EGET KRAV.',
            'Tekst som beskriver evaluering, vurdering, poengsetting eller hva det legges vekt på er ikke egne krav når den ikke har egen requirement_identifier.',
            'Dette inkluderer for eksempel setninger som "Ved evalueringen legges det vekt på" og underliggende bullets eller underpunkter uten egen krav-ID.',
            'Slike tekster skal aldri returneres som egne kandidatobjekter.',
            'Hvis slik tekst står inne i samme kravblokk som et formelt krav med samme requirement_identifier, skal den enten beholdes i samme original_text eller ignoreres, men aldri skilles ut som et eget krav.',

            'MÅLSETTINGER, PRIORITERINGER, BEGRUNNELSER OG ØNSKEDE EFFEKTER UTEN EGEN ID ER IKKE AUTOMATISK FORMELLE KRAV.',
            'Tekst som beskriver strategiske mål, målsettinger, prioriteringer, begrunnelser, ønskede effekter, formål, ambisjoner, målbilde eller overordnede føringer skal normalt ikke returneres som formelle krav når den ikke står som eget nummerert krav i en kravkontekst.',
            'Dette gjelder også når slik tekst står i tabeller eller seksjoner med overskrifter som "Strategiske mål", "Prioritet", "Begrunnelse", "Formål", "Målbilde", "Ambisjon", "Ønsket effekt" eller lignende.',
            'Men hvis teksten faktisk står som et eget nummerert krav med egen requirement_identifier i kravtabell eller kravseksjon, skal den behandles som et formelt krav.',

            'KRAVNIVÅ SKAL LESES FRA DOKUMENTSTRUKTUREN, IKKE GJETTES.',
            'Identifiser må-krav, skal-krav, bør-krav eller annen eksplisitt kravkategori bare når dokumentet tydelig angir dette.',
            'Les dette fra overskrifter, seksjonsnavn, tabelloverskrifter, labels og annen eksplisitt dokumentstruktur.',
            'Hvis kravnivå ikke kan utledes sikkert fra dokumentet, sett feltet til null.',

            'Formelle krav er tekster som tydelig fremstår som krav i kravtabell, kravseksjon eller med requirement_identifier.',
            'Generell formålstekst, avtalebeskrivelse, bakgrunn, mål, samarbeidstekst, forventninger og overordnede føringer uten tydelig kravstatus skal ikke behandles som formelle krav.',
            'Bruk original_text til å gjengi hele kravblokken så presist som mulig.',
            'Bevar rekkefølge, struktur og innhold fra dokumentet innenfor samme kravblokk.',
            'Bruk source_reference_text bare når en kort og stabil plassering i dokumentet er lett å utlede.',
            'Sett is_requirement til true bare for faktiske formelle krav og false ellers.',
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
