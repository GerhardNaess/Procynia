<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Services\EnterpriseWiki\EnterpriseWikiClaimAnchorTextNormalizer;
use App\Services\EnterpriseWiki\EnterpriseWikiClaimCanonicalizationService;
use App\Services\EnterpriseWiki\EnterpriseWikiLinkParser;
use PHPUnit\Framework\TestCase;

/**
 * Run 963: all three claims on summary page 2586 were blocked as negation_mismatch although none
 * of them negates or drops a negation. The negation check compared the whole combined supporting
 * text, so an «ikke» in a source sentence the claim never restates decided the outcome. Negation
 * is now compared clause by clause against the source clause each claim clause restates.
 *
 * The claims and excerpts below are the run-963 texts (claim 475–477, page version 477), fed
 * through filterToRelevantSentences() per excerpt exactly as the verifier's safety net does.
 */
class EnterpriseWikiNegationClauseAlignmentTest extends TestCase
{
    private function service(): EnterpriseWikiClaimCanonicalizationService
    {
        return new EnterpriseWikiClaimCanonicalizationService(new EnterpriseWikiClaimAnchorTextNormalizer(new EnterpriseWikiLinkParser));
    }

    /** @param list<string> $excerpts */
    private function conflictAsVerified(string $claim, array $excerpts): ?string
    {
        $service = $this->service();
        $supporting = implode("\n", array_map(
            static fn (string $excerpt): string => $service->filterToRelevantSentences($claim, $excerpt),
            $excerpts,
        ));

        return $service->detectDeterministicConflict($claim, $supporting);
    }

    public function test_the_three_page_2586_claims_are_no_longer_negation_conflicts(): void
    {
        $this->assertNull($this->conflictAsVerified(
            'En [[etterhendelsesgjennomgang-post-incident-review|etterhendelsesgjennomgang]] omsetter en håndtert sikkerhetshendelse til varig forbedring ved å forklare hvorfor hendelsen skjedde, hvorfor den ble oppdaget når den ble oppdaget, og hva som må endres for å hindre gjentakelse. Aktiviteten starter når hendelsen er stabilisert og avsluttes først når effekten av avtalte tiltak er verifisert; selve hendelseshåndteringen omtales bare der den gir læringsgrunnlag, og frister fastsettes i den enkelte avtalen.',
            [
                'En etterhendelsesgjennomgang (Post Incident Review, ofte kalt «hot washup» når den gjennomføres kort tid etter stabilisering) er den strukturerte aktiviteten som gjør en håndtert sikkerhetshendelse om til varig forbedring. Hendelsesrapporten beskriver hva som skjedde. Gjennomgangen skal i tillegg forklare hvorfor det skjedde, hvorfor det ble oppdaget når det ble oppdaget, og hva som må endres for at det ikke skal skje igjen.',
                'Notatet dekker perioden fra hendelsen er stabilisert til forbedringstiltakene er verifisert. Selve håndteringen av hendelsen – skadebegrensning, fjerning av trusselaktør og gjenoppretting – omtales bare der den gir grunnlag for læring. Frister for hendelsesrapport og gjennomgang følger av den enkelte avtalen og fastsettes ikke her.',
            ],
        ));

        $this->assertNull($this->conflictAsVerified(
            'Kjerneflyten bygger på en etterprøvbar tidslinje satt sammen av systemtidsstempler, hendelseslogg og tekniske spor, og den sentrale analysen sammenligner første tekniske spor, første alarm og første bekreftede oppfølging for å synliggjøre [[deteksjonsgap-responsgap|Deteksjonsgap og responsgap]], som skal holdes atskilt. Rådata må sikres ved fare for utløpt lagringstid og perioder med bortfall i loggkilder markeres eksplisitt. Selve gjennomføringen er skyldfri og skiller mellom utløsende årsak, bidragende faktorer og barrierer for å få frem forholdene som muliggjorde feilen heller enn å vurdere enkeltpersoner.',
            [
                'Gjennomgangen bygger på en etterprøvbar tidslinje, ikke på hukommelse. Tidslinjen settes sammen av tre kilder:',
                'Systemgenererte tidsstempler i sakssystemet for registrering, start av analyse, reklassifisering, varsling og tiltak.',
                'Hendelsesloggen ført av Scribe, med beslutninger og begrunnelser.',
                'Tekniske spor fra loggkilder og endepunkter som viser når trusselaktøren faktisk var aktiv.',
                'Det viktigste analytiske grepet er å sammenligne tidspunktet for første tekniske spor med tidspunktet for første alarm og tidspunktet for første bekreftede oppfølging. Avstanden mellom de to første viser et deteksjonsgap. Avstanden mellom de to siste viser et responsgap. De to gapene har ulike årsaker og krever ulike tiltak, og bør derfor aldri slås sammen til ett tall.',
                'Rekonstruksjonen forutsetter at rådata fortsatt finnes. Dersom gjennomgangen ligger nær utløpet av ordinær lagringstid, må relevante logger og bevismateriale sikres særskilt etter kundens instruks og med dokumentert forvaringskjede. Perioder der en loggkilde har vært ute av drift, markeres eksplisitt i tidslinjen, slik at manglende spor ikke tolkes som fravær av aktivitet.',
                'Gjennomgangen gjennomføres etter et skyldfritt prinsipp. Spørsmålet er hvilke forhold som gjorde en feil mulig eller sannsynlig, ikke hvem som gjorde feilen. Erfaring viser at deltakere ellers holder tilbake nettopp den informasjonen som forklarer forløpet.',
                'Analysen skiller mellom tre forhold:',
                'Utløsende årsak: det konkrete forholdet som gjorde hendelsen mulig, for eksempel en utnyttet sårbarhet eller en kompromittert konto.',
                'Bidragende faktorer: forhold som gjorde hendelsen større eller oppdagelsen senere, for eksempel manglende deteksjonsdekning, uklar fullmakt eller en forsinket varsling.',
                'Barrierer: tiltak som virket, og tiltak som skulle ha virket, men sviktet.',
            ],
        ));

        $this->assertNull($this->conflictAsVerified(
            'Læringen omsettes til tiltak og varig forbedring: observerte teknikker føres i dekningsmatrisen, tiltak eiersettes og lukkes først når effekten er påvist gjennom klare akseptkriterier, og nye metoder kan utløse retrospektiv trusseljakt i hele miljøet. Forbedringssløyfen følges opp med indikatorer i tjenesterapporteringen, herunder andel gjennomganger innen frist, andel tiltak lukket med verifisert effekt, alder på åpne tiltak, gjentatte rotårsaker og utviklingen i deteksjons- og responsgap, vurdert i sammenheng med kvitteringstid og falske positiver.',
            [
                'Et tiltak lukkes ikke når det er gjennomført, men når effekten er påvist i henhold til akseptkriteriet. For en ny deteksjon betyr det at regelen er testet mot en kontrollert simulering av teknikken og har gitt alarm.',
                'Forbedringssløyfen bør måles som en egen prosess, ikke bare gjennom de operative responstidene. Anbefalte indikatorer er:',
                'andel tiltak lukket med verifisert effekt, ikke bare gjennomført',
                'Den siste indikatoren må ses sammen med kvitteringstid og andelen falske positiver. Et kortere responsgap er bare en forbedring dersom det ikke skyldes at flere reelle hendelser er klassifisert lavere.',
                'Indikatorene rapporteres i den ordinære tjenesterapporteringen. De viser også at læringsfasen i standarden for hendelseshåndtering faktisk gjennomføres, ikke bare beskrives.',
            ],
        ));
    }

    public function test_a_negation_in_an_unrelated_source_sentence_does_not_count(): void
    {
        $this->assertNull($this->service()->detectDeterministicConflict(
            'Hendelsesrapporten beskriver tidslinjen for hendelsen.',
            'Hendelsesrapporten beskriver tidslinjen for hendelsen. Kunden skal aldri kontaktes uten avtale med driftsansvarlig.',
        ));
    }

    public function test_a_real_negation_reversal_is_still_a_conflict(): void
    {
        $cases = [
            ['Leverandøren skal ikke utføre sikkerhetskopiering av kundens data.', 'Leverandøren skal utføre sikkerhetskopiering av kundens data.'],
            ['Endringen kan ikke gjennomføres i helgen.', 'Endringen kan gjennomføres i helgen.'],
            ['Hendelsen er ikke bekreftet av kunden.', 'Hendelsen er bekreftet av kunden.'],
            // Dropped, not added: the source negates, the claim does not.
            ['Hendelsen er bekreftet av kunden.', 'Hendelsen er ikke bekreftet av kunden.'],
            // Inside a purpose clause the claim does restate.
            ['Perioder med bortfall markeres i tidslinjen slik at manglende spor tolkes som fravær av aktivitet.', 'Perioder med bortfall markeres i tidslinjen, slik at manglende spor ikke tolkes som fravær av aktivitet.'],
            // The relevant sentence is found among unrelated ones.
            ['Leverandøren skal ikke utføre sikkerhetskopiering av kundens data.', "Rapporten sendes månedlig.\nLeverandøren skal utføre sikkerhetskopiering av kundens data.\nKunden eier tiltakene."],
        ];

        foreach ($cases as [$claim, $source]) {
            $this->assertSame('negation_mismatch', $this->service()->detectDeterministicConflict($claim, $source), $claim);
        }

        $diagnostic = $this->service()->detectNegationConflict($cases[0][0], $cases[0][1]);
        $this->assertSame(EnterpriseWikiClaimCanonicalizationService::NEGATION_STATUS_CONFLICT, $diagnostic['status']);
        $this->assertSame($cases[0][1], $diagnostic['source_clause']);
    }

    public function test_valid_rewrites_are_not_negation_conflicts(): void
    {
        $cases = [
            // A negated purpose restated as a positive verb.
            ['Gjennomgangen forklarer hva som må endres for å hindre gjentakelse.', 'Gjennomgangen forklarer hva som må endres for at det ikke skal skje igjen.'],
            // "X, ikke Y": the claim restates X only.
            ['Gjennomgangen bygger på en etterprøvbar tidslinje.', 'Gjennomgangen bygger på en etterprøvbar tidslinje, ikke på hukommelse.'],
            // "ikke X, men Y": the claim restates Y.
            ['Et tiltak lukkes når effekten er påvist i henhold til akseptkriteriet.', 'Et tiltak lukkes ikke når det er gjennomført, men når effekten er påvist i henhold til akseptkriteriet.'],
            // Both negate.
            ['Tjenesten er ikke tilgjengelig i helgene.', 'Tjenesten er ikke åpen og ikke tilgjengelig i helgene.'],
        ];

        foreach ($cases as [$claim, $source]) {
            $this->assertNull($this->service()->detectDeterministicConflict($claim, $source), $claim);
        }
    }

    public function test_an_unclear_source_sentence_is_recorded_as_ambiguous_never_as_a_conflict(): void
    {
        $claim = 'Tiltaket lukkes raskt.';
        $source = 'Tiltaket lukkes ikke. Tiltaket lukkes etter test.';

        $this->assertNotSame('negation_mismatch', $this->service()->detectDeterministicConflict($claim, $source));
        $this->assertSame(
            EnterpriseWikiClaimCanonicalizationService::NEGATION_STATUS_AMBIGUOUS,
            $this->service()->detectNegationConflict($claim, $source)['status'] ?? null,
        );
    }

    public function test_cross_language_single_sentences_are_still_compared_but_longer_texts_are_not_concluded(): void
    {
        $this->assertSame('negation_mismatch', $this->service()->detectDeterministicConflict(
            'Tjenesten er tilgjengelig utenfor arbeidstid.',
            'The service is not available outside business hours.',
        ));

        $this->assertSame(
            EnterpriseWikiClaimCanonicalizationService::NEGATION_STATUS_AMBIGUOUS,
            $this->service()->detectNegationConflict(
                'Tjenesten er tilgjengelig utenfor arbeidstid. Saker meldes i portalen.',
                'The service is not available outside business hours. Tickets are reported in the portal.',
            )['status'] ?? null,
        );
    }

    public function test_the_modality_checks_are_unchanged(): void
    {
        $service = $this->service();

        $this->assertSame('modality_mismatch', $service->detectDeterministicConflict(
            'Leverandøren skal tilby opplæring av nye brukere.',
            'Leverandøren kan tilby opplæring av nye brukere.',
        ));

        // Run 963 #472/#473: both sides negate, so this is a normative strengthening, never a
        // negation conflict.
        $claim = 'De to gapene har ulike årsaker og krever ulike tiltak, og skal derfor ikke slås sammen til ett tall.';
        $source = 'De to gapene har ulike årsaker og krever ulike tiltak, og bør derfor aldri slås sammen til ett tall.';

        $this->assertNotSame('negation_mismatch', $service->detectDeterministicConflict($claim, $source));
        $normative = $service->detectNormativeStrengthening($claim, [['text' => $source, 'type' => 'paragraph']]);
        $this->assertSame(EnterpriseWikiClaimCanonicalizationService::NORMATIVE_STATUS_STRENGTHENED, $normative['status']);
        $this->assertSame(EnterpriseWikiClaimCanonicalizationService::MODALITY_CLASS_NEGATIVE_RECOMMENDATION, $normative['source_class']);
        $this->assertSame(EnterpriseWikiClaimCanonicalizationService::MODALITY_CLASS_PROHIBITION, $normative['claim_class']);
    }
}
