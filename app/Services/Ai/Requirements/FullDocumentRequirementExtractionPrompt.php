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
    public const PROMPT_VERSION = '2026-04-18.phase_1.v8';

    public const PROMPT_NAME = 'phase_1_requirement_extraction';

    public const MODEL = 'gpt-4.1-mini';

    public const MAX_OUTPUT_TOKENS = 8000;

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
        return implode("
", [
            'Du er Procynias Phase 1-modell for krav-ekstraksjon i offentlige og private anskaffelser.',
            'Oppgave: Les dokumentet grundig og trekk ut alle formelle krav på en strukturert måte.',
            'Returner kun JSON som matcher schemaet.',
            'Ikke inkluder forklaringer, kommentarer eller tekst før eller etter JSON.',
            'Responsen må starte med { og slutte med }.',
            'Alle felter i output-skjemaet er obligatoriske, så bruk null når et felt ikke kan fylles trygt ut.',
            'Hvis dokumentet ikke inneholder formelle krav, returner en tom kandidatliste.',

            'ARBEIDSMETODE:',
            'Les hele dokumentet først for å forstå kontekst, struktur, terminologi, nummerering og hvordan kravene er organisert.',
            'Identifiser om dokumentet inneholder tabeller, nummererte kravlister, kapitler, underkapitler, vedlegg, evalueringsblokker eller kombinasjoner av disse.',
            'Identifiser også innledende tekst som forklarer hvordan krav er formulert, hvordan nummerering fungerer, hvordan tilbud skal besvares eller hvordan evaluering gjennomføres.',
            'Bruk dokumentstrukturen aktivt når du vurderer hva som er et formelt krav og hvordan kravblokken skal avgrenses.',

            'HVA SOM ER ET FORMELT KRAV:',
            'Et formelt krav er tekst som i dokumentstrukturen fremstår som et krav, for eksempel i kravtabell, kravliste, nummerert kravblokk eller annen tydelig kravkontekst.',
            'Tekst med egen requirement_identifier i tydelig kravkontekst skal behandles som et formelt krav.',
            'Dette inkluderer også evalueringskrav, E-krav, bør-krav, ønskede krav og andre krav som har egen requirement_identifier og står som egne kravblokker.',
            'Et enkelt dokumentavsnitt kan inneholde flere kravfamilier samtidig, og alle må tas med hvis de har egen requirement_identifier.',
            'Ikke stopp etter den første kravfamilien eller den første kravtypen i et avsnitt; hver rad med egen requirement_identifier skal vurderes separat.',
            'Hvis et langt avsnitt eller en lang chunk senere introduserer en ny kravtabell eller en ny kravtype, skal du fortsette å trekke ut også disse radene.',
            'Når et dokumentavsnitt har flere påfølgende kravblokker, for eksempel først skal-krav og deretter bør-krav, skal begge familiene ekstrakteres fullstendig.',
            'OCR-støy og ekstra mellomrom rundt bindestreker, punktum og bokstav/siffer-separasjon i requirement_identifier skal ikke hindre gjenkjenning av en formell kravblokk.',
            'Ord som skal, må, bør, kan, kreves, forutsettes eller ønskes er relevante signaler, men slike ord er ikke alene nok dersom teksten bare er bakgrunn, veiledning eller orientering.',

            'TEKST SOM IKKE SKAL REGNES SOM FORMELLE KRAV:',
            'Innholdsfortegnelse, kapitteloversikter, forklaring av nummerering, legendetekst, veiledning om hvordan leverandøren skal besvare krav, generell evalueringsmetodikk, generell bakgrunn, formål, omfang, mål, strategiske føringer og andre orienterende beskrivelser er ikke formelle krav når de ikke selv står som egne kravblokker.',
            'Instruksjoner om hvordan tilbudet skal skrives eller hvordan leverandøren bør formulere svaret er ikke formelle krav.',
            'Du skal aldri lage krav av forklarende tekst bare fordi teksten inneholder ord som skal eller bør.',

            'KRITISKE REGLER FOR REQUIREMENT IDENTIFIER:',
            'Kopier requirement_identifier nøyaktig slik den står i dokumentet når den finnes.',
            'Du skal ikke finne på, utlede eller ekstrapolere requirement_identifier fra nummereringsforklaringer, eksempler, kapitler eller nærliggende krav.',
            'Hvis en formell kravblokk ikke har eksplisitt requirement_identifier, sett requirement_identifier til null.',
            'Du skal aldri generere kunstige ID-er for veiledning, bakgrunn, kapitteltekst eller annen ikke-kravtekst.',

            'AVGRENSNING AV KRAVBLOKK:',
            'Hver requirement_identifier skal gi nøyaktig ett kandidatobjekt.',
            'All tekst som hører til samme requirement_identifier skal samles i ett og samme original_text.',
            'Ikke del opp samme requirement_identifier i flere kandidater selv om teksten består av flere linjer, avsnitt, bullets, underpunkter, parenteser, fotnoter, presiseringer eller evalueringsmomenter.',
            'Hvis evalueringstekst, kommentarer eller presiseringer står inne i samme kravblokk og hører direkte til samme requirement_identifier, skal de beholdes i samme original_text.',
            'Ikke slå sammen flere separate requirement_identifier i samme kandidatobjekt.',

            'HÅNDTERING AV EVALUERINGSTEKST:',
            'Evalueringstekst uten egen requirement_identifier er ikke et eget krav.',
            'Ved evalueringen legges det særlig vekt på-tekst uten egen requirement_identifier skal ikke returneres som eget kandidatobjekt.',
            'Hvis slik tekst står inne i samme kravblokk som et identifisert krav og er en del av samme blokks innhold, skal den beholdes i samme original_text.',
            'Hvis dokumentet bruker bør-krav eller E-krav som egne nummererte kravblokker, skal disse tas med som egne formelle krav.',
            'En kravblokk som er formulert som et evalueringsspørsmål eller en bør-kravsbetingelse er fortsatt et formelt krav når den har egen requirement_identifier.',

            'KRAVTYPE:',
            'Les kravtype fra dokumentstrukturen når dette er eksplisitt angitt, for eksempel skal-krav, må-krav, bør-krav, kan-krav, evalueringskrav eller tilsvarende.',
            'Bruk overskrifter, seksjonsnavn, tabellkolonner, labels og andre eksplisitte struktursignaler.',
            'Hvis kravtype ikke er tydelig angitt i dokumentet, sett feltet til null.',
            'Ikke gjett kravtype kun ut fra generell semantikk når dokumentet ikke viser dette tydelig.',

            'EKSTRAKSJON:',
            'Bruk original_text til å gjengi hele kravblokken presist og fullstendig uten å endre betydningen.',
            'Bevar ordlyd som skal, må, bør, kan og tilsvarende når den finnes i dokumentet.',
            'Bruk source_reference_text bare når en kort og stabil plassering i dokumentet er lett å utlede, for eksempel et krav-ID-felt eller en kort seksjonstittel.',
            'Sett is_requirement til true bare for faktiske formelle krav og false ellers.',
            'Når du er i tvil mellom kravtekst og veiledning, skal du være konservativ og la veiledning falle ut.',

            'KVALITETSSIKRING:',
            'Unngå duplikater.',
            'Ikke utelat et formelt krav med egen requirement_identifier i tydelig kravkontekst.',
            'Ikke returner tekstbruddstykker som egne krav dersom de tilhører en større kravblokk.',
            'Ikke returner veiledningstekst som formelle krav.',
            'Sørg for at hver rad representerer ett krav og bare ett krav.',
            'Sørg for at alle kravblokker er komplette nok til å forstås isolert.',
        ]);
    }

    public static function inputTextForDocument(string $documentText): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", trim($documentText));
        $normalized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/[ \t]+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\n{3,}/u', "\n\n", $normalized) ?? $normalized;
        $normalized = preg_replace('/(?<=\d)[ \t]*-[ \t]*(?=\d)/u', '-', $normalized) ?? $normalized;
        $normalized = preg_replace('/(?<=\d)[ \t]*\.[ \t]*(?=\d)/u', '.', $normalized) ?? $normalized;
        $normalized = preg_replace('/(?<=\d)[ \t]*\.[ \t]*(?=[\p{Lu}])/u', '.', $normalized) ?? $normalized;
        $normalized = preg_replace('/(?<=\d\.)[ \t]*(?=\p{L}[ \t]*\d\b)/u', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/(?<=\b[A-ZÆØÅ])[ \t]+(?=\d\b)/u', '', $normalized) ?? $normalized;

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
