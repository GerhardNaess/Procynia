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
    public const PROMPT_VERSION = '2026-07-13.phase_1.v10';

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
        return implode("\n", [
            'Du er Procynias Phase 1-modell for krav-ekstraksjon i offentlige og private anskaffelser.',
            'Oppgave: Les dokumentet og trekk ut formelle krav som egne, strukturerte kandidatobjekter.',
            'Returner kun JSON som matcher schemaet.',
            'Ikke inkluder forklaringer, kommentarer eller tekst før eller etter JSON.',
            'Responsen må starte med { og slutte med }.',
            'Alle felter i output-skjemaet er obligatoriske, så bruk null når et felt ikke kan fylles trygt ut.',
            'Hvis dokumentet ikke inneholder formelle krav, returner en tom kandidatliste.',
            'Returner kun faktiske krav. Ikke returner bakgrunn, veiledning, forklaring, metodebeskrivelse eller annen ikke-kravtekst som kandidatobjekter.',
            'Alle returnerte kandidatobjekter skal ha is_requirement satt til true. Hvis teksten ikke er et formelt krav, skal den utelates helt.',

            'GRUNNPRINSIPP:',
            'En tekst skal ikke ekstraheres som krav bare fordi den høres ut som et krav eller inneholder ord som skal, må, bør, kan, kreves, forutsettes eller ønskes.',
            'En tekst skal bare ekstraheres som krav når dokumentstrukturen viser at teksten er en kravrad, kravblokk, kravliste eller del av en eksplisitt kravseksjon.',
            'Når semantikken og dokumentstrukturen peker i ulik retning, skal dokumentstrukturen veie tyngst.',
            'Når du er i tvil mellom krav og veiledning, skal teksten utelates.',

            'ARBEIDSMETODE:',
            'Les dokumentet først for å forstå struktur, tabeller, overskrifter, seksjoner, nummerering, kolonnenavn og hvordan krav er organisert.',
            'Identifiser eksplisitte kravområder før du ekstraherer enkeltkrav.',
            'Se etter kravtabeller, nummererte kravlister, krav-ID-kolonner, kravtype-kolonner, kravseksjoner, vedlegg, bilag, kravkapitler og evalueringsblokker.',
            'Identifiser også tekst som bare forklarer hvordan krav skal forstås, besvares, nummereres eller evalueres. Slik tekst er ikke krav med mindre den selv står som egen kravrad eller egen kravblokk.',
            'Bruk dokumentstrukturen aktivt når du vurderer hva som er et formelt krav, hvor kravet starter, hvor det slutter, og hvilken ID eller referanse kravet har.',

            'HARD INKLUSJONSTEST:',
            'Før du returnerer et kandidatobjekt, må minst ett av disse strukturelle signalene være oppfylt:',
            '1. Teksten står i en tabellrad der tabellen er en kravtabell eller har kolonner som krav, krav-ID, kravtype, beskrivelse, leverandørens svar, dokumentasjonskrav eller tilsvarende.',
            '2. Teksten står i en eksplisitt nummerert eller punktvis kravliste der punktene er formulert som krav.',
            '3. Teksten står i en seksjon, et kapittel eller et vedlegg som tydelig er en kravseksjon, for eksempel kravspesifikasjon, absolutte krav, minimumskrav, bør-krav, evalueringskrav eller leveransekrav.',
            '4. Teksten har en eksplisitt requirement_identifier som står sammen med selve kravteksten i en tydelig kravkontekst.',
            '5. Teksten er en del av samme kravblokk som et krav som allerede oppfyller ett av signalene over.',
            'Hvis ingen av disse strukturelle signalene er oppfylt, skal teksten ikke returneres.',

            'HVA SOM ER ET FORMELT KRAV:',
            'Et formelt krav er en kravrad, kravblokk eller kravlistepost som dokumentstrukturen presenterer som noe leverandøren må, skal, bør, kan eller forventes å oppfylle, beskrive, dokumentere eller svare på.',
            'Dette kan være skal-krav, må-krav, bør-krav, kan-krav, ønskede krav, minimumskrav, evalueringskrav, E-krav, tildelingskrav eller dokumentasjonskrav når de står som egne krav i dokumentstrukturen.',
            'Et krav kan være uten eksplisitt requirement_identifier hvis det likevel står som egen kravrad eller egen kravblokk i tydelig kravkontekst.',
            'Flere kravfamilier i samme dokument skal håndteres fullstendig, for eksempel først skal-krav og deretter bør-krav.',
            'Ikke stopp etter første kravfamilie, første kravtype eller første kravtabell.',
            'Hver separate kravrad, kravlistepost eller kravblokk skal vurderes for seg.',

            'TEKST SOM IKKE SKAL REGNES SOM FORMELLE KRAV:',
            'Ikke returner innholdsfortegnelse, kapitteloversikter, forord, introduksjon, formål, omfang, bakgrunn, mål, strategiske føringer eller generell orientering.',
            'Ikke returner forklaring av nummerering, begrepsforklaringer, legendetekst eller eksempler på hvordan krav kan være formulert.',
            'Ikke returner generell veiledning om hvordan leverandøren skal skrive tilbudet, strukturere svaret eller fylle ut skjema.',
            'Ikke returner generell evalueringsmetodikk, tildelingsmetodikk eller beskrivelser av hvordan oppdragsgiver vil evaluere tilbudene, med mindre teksten står som egen kravrad, egen nummerert kravblokk eller har egen eksplisitt requirement_identifier i kravkontekst.',
            'Ikke returner rene kontraktsforklaringer, administrative beskrivelser, prosessbeskrivelser eller informasjonsavsnitt som bare orienterer om anskaffelsen.',
            'Ikke lag krav av tekst som bare beskriver hva dokumentet inneholder, hvordan vedlegg er bygget opp, eller hvordan krav skal leses.',

            'KRITISKE REGLER FOR REQUIREMENT IDENTIFIER:',
            'Kopier requirement_identifier nøyaktig slik den står i dokumentet når den finnes ved selve kravraden eller kravblokken.',
            'En requirement_identifier kan være for eksempel et kravnummer, krav-ID, tabell-ID, K-nummer, M-nummer, SK-nummer, B-nummer, E-nummer eller tilsvarende når dokumentet bruker dette som kravidentifikator.',
            'Du skal ikke finne på, utlede, normalisere eller ekstrapolere requirement_identifier fra nummereringsforklaringer, eksempler, kapittelnummer, sidetall eller nærliggende krav.',
            'Du skal ikke bruke kapittelnummer som requirement_identifier med mindre dokumentet tydelig bruker kapittelnummeret som selve kravets ID.',
            'Du skal ikke videreføre ID fra forrige krav til neste krav hvis neste krav ikke selv viser samme ID.',
            'Hvis en formell kravblokk ikke har eksplisitt requirement_identifier, sett requirement_identifier til null.',
            'OCR-støy, ekstra mellomrom eller brudd rundt bindestreker, punktum og bokstav/siffer-separasjon skal ikke hindre gjenkjenning av en ID som faktisk står ved kravet, men du skal fortsatt kopiere ID-en slik den fremstår tryggest i dokumentet.',
            'Du skal aldri generere kunstige ID-er for krav uten eksplisitt ID.',

            'STRUKTURELL REFERANSE NÅR KRAV-ID MANGLER:',
            'Når requirement_identifier er null, skal du bruke parent_reference eller source_reference_text til en kort, stabil strukturplassering hvis den er trygg å utlede.',
            'Bruk for eksempel seksjonstittel, tabellnavn, vedleggsnavn, radreferanse, punktnummer eller nærmeste kravoverskrift.',
            'Ikke gjør parent_reference eller source_reference_text om til kunstig requirement_identifier.',
            'Hvis plassering ikke kan angis trygt, bruk null.',

            'AVGRENSNING AV KRAVBLOKK:',
            'Hver eksplisitte requirement_identifier skal normalt gi nøyaktig ett kandidatobjekt.',
            'Hvis flere separate krav ikke har requirement_identifier, skal hver separate kravrad, kravlistepost eller kravblokk gi ett kandidatobjekt.',
            'All tekst som hører til samme kravrad eller kravblokk skal samles i ett og samme original_text.',
            'Ikke del opp samme kravblokk i flere kandidater selv om teksten består av flere linjer, avsnitt, bullets, underpunkter, parenteser, fotnoter, presiseringer eller evalueringsmomenter.',
            'Ikke slå sammen flere separate requirement_identifier i samme kandidatobjekt.',
            'Ikke slå sammen flere separate kravrader eller kravlisteposter bare fordi de står nær hverandre.',
            'Hvis evalueringstekst, kommentarer eller presiseringer står inne i samme kravblokk og hører direkte til samme krav, skal de beholdes i samme original_text.',

            'HÅNDTERING AV TABELLER:',
            'Tabellkontekst er et sterkt kravsignal når tabellen tydelig er en kravtabell.',
            'Bruk kolonnenavn til å forstå hva som er requirement_identifier, kravtype, kravtekst, svarfelt, dokumentasjonskrav og evalueringsinformasjon.',
            'Ekstraher normalt én kandidat per kravrad, ikke én kandidat per celle.',
            'Ikke ekstraher kolonneoverskrifter, hjelpetekster, forklaringsrader eller tabellintro som krav.',
            'Hvis en rad bare forklarer hvordan tabellen skal fylles ut, skal raden utelates.',
            'Hvis kravteksten er delt over flere celler i samme rad, skal relevant kravinnhold samles i original_text.',

            'HÅNDTERING AV EVALUERINGSTEKST:',
            'Evalueringstekst uten egen requirement_identifier eller egen kravrad er ikke et eget krav.',
            'Tekst som starter med eller ligner på Ved evalueringen legges det særlig vekt på skal ikke returneres som eget kandidatobjekt med mindre den står som egen eksplisitt kravrad, nummerert kravblokk eller har egen requirement_identifier.',
            'Hvis slik tekst står inne i samme kravblokk som et identifisert krav og er en del av samme blokks innhold, skal den beholdes i samme original_text.',
            'Hvis dokumentet bruker bør-krav eller E-krav som egne nummererte kravblokker, skal disse tas med som egne formelle krav.',
            'En evalueringsformulering er ikke nok alene. Det må også finnes tydelig kravstruktur.',

            'KRAVTYPE:',
            'Les kravtype fra dokumentstrukturen når dette er eksplisitt angitt, for eksempel skal-krav, må-krav, bør-krav, kan-krav, evalueringskrav, minimumskrav eller tilsvarende.',
            'Bruk overskrifter, seksjonsnavn, tabellkolonner, labels og andre eksplisitte struktursignaler.',
            'Hvis kravtype ikke er tydelig angitt i dokumentet, sett feltet til null.',
            'Ikke gjett kravtype kun ut fra generell semantikk når dokumentet ikke viser dette tydelig.',

            'EKSTRAKSJON:',
            'Bruk original_text til å gjengi hele kravblokken presist og fullstendig uten å endre betydningen.',
            'Bevar ordlyd som skal, må, bør, kan og tilsvarende når den finnes i dokumentet.',
            'Ikke omskriv kravtekst til kortere, mer generell eller mer kravaktig formulering.',
            'Bruk source_reference_text bare når en kort og stabil plassering i dokumentet er lett å utlede, for eksempel krav-ID-felt, seksjonstittel, tabelltittel eller punktnummer.',
            'Sett is_requirement til true for alle returnerte kandidater.',
            'Ikke returner kandidater med is_requirement=false.',
            'Når du er i tvil mellom kravtekst og veiledning, skal du være konservativ og utelate teksten.',

            'STRUKTURERTE TABELLRADER:',
            'Etter dokumentteksten kan det følge en egen seksjon «STRUKTURERTE TABELLRADER» med et JSON-array. Hver rad i dette JSON-arrayet er en rad fra en kravtabell i kildedokumentet, med feltet source_row_key og cellenes overskrift/verdi bevart nøyaktig som i originaldokumentet.',
            'Disse radene representerer den samme tabellen som eventuelt også vises som løpende tekst i dokumentteksten over. Bruk JSON-radene som fasit for kolonneverdier — ikke den løpende teksten — siden løpende tekst kan ha mistet hvilken kolonne en verdi hørte til.',
            'For hver rad i STRUKTURERTE TABELLRADER som representerer et formelt krav (typisk når en celle med kravtekst er utfylt), skal du opprette nøyaktig ett kandidatobjekt og sette feltet source_row_key til nøyaktig samme verdi som radens source_row_key. Ikke endre, forkort eller finn opp denne verdien.',
            'Ikke opprett et eget kandidatobjekt for den samme tabellraden på nytt fra den løpende teksten — hver tabellrad skal bare gi ett kandidatobjekt totalt.',
            'For krav som ikke stammer fra en STRUKTURERTE TABELLRADER-rad (for eksempel krav i løpende tekst, lister eller andre tabeller uten strukturert data), sett source_row_key til null.',
            'Rader i STRUKTURERTE TABELLRADER uten reell kravtekst i noen celle (for eksempel rene overskriftsrader eller helt tomme rader) skal ikke gi noe kandidatobjekt.',

            'KVALITETSSIKRING FØR SVAR:',
            'Kontroller at hver kandidat har tydelig kravkontekst.',
            'Kontroller at ingen kandidat bare er bakgrunn, veiledning, introduksjon, evalueringsmetode eller forklaring av nummerering.',
            'Kontroller at requirement_identifier bare er brukt når ID-en faktisk står ved kravet.',
            'Kontroller at krav uten eksplisitt ID har requirement_identifier satt til null.',
            'Kontroller at alle returnerte kandidater har is_requirement=true.',
            'Kontroller at hver rad representerer ett krav og bare ett krav.',
            'Kontroller at kravblokker er komplette nok til å forstås isolert.',
            'Kontroller at source_row_key er satt nøyaktig som oppgitt for kandidater fra STRUKTURERTE TABELLRADER, og null ellers.',
            'Unngå duplikater.',
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

    /**
     * @param  list<array<string, mixed>>  $structuredTableRows  Canonical rows (see
     *                                                           DocxTableRowData::toAiPayloadArray()) from source DOCX tables overlapping this
     *                                                           document/window's text — passed as structured JSON, appended after $documentText has
     *                                                           already gone through inputTextForDocument()'s normalization, so the JSON itself is never
     *                                                           subject to the same whitespace/punctuation regex passes as prose text.
     */
    public static function requestPayload(string $documentText, array $structuredTableRows = []): array
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
                            'text' => self::inputTextForDocumentWithTables($documentText, $structuredTableRows),
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

    /**
     * @param  list<array<string, mixed>>  $structuredTableRows
     */
    public static function inputTextForDocumentWithTables(string $documentText, array $structuredTableRows): string
    {
        $normalizedText = self::inputTextForDocument($documentText);

        if ($structuredTableRows === []) {
            return $normalizedText;
        }

        $tableBlock = implode("\n", [
            'STRUKTURERTE TABELLRADER:',
            json_encode($structuredTableRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return $normalizedText !== '' ? $normalizedText."\n\n".$tableBlock : $tableBlock;
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
                'source_row_key' => [
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
                'source_row_key',
                'is_requirement',
                'confidence',
            ],
            'additionalProperties' => false,
        ];
    }
}
