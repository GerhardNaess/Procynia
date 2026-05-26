<?php

namespace App\Services\GoNoGo;

use App\Models\GoNoGoAssessmentCriterion;
use App\Models\GoNoGoAssessmentTemplate;

/**
 * Creates the default Go/No-go assessment template for a customer if one does not
 * already exist.  Safe to call multiple times — idempotent by design.
 *
 * The default is based on Procynia's nine standard evaluation dimensions.
 * System Owners can modify or replace it via the template admin UI.
 */
class GoNoGoDefaultTemplateService
{
    private const DEFAULT_CRITERIA = [
        [
            'title'                  => 'Strategisk relevans',
            'short_description'      => 'I hvilken grad anskaffelsen samsvarer med virksomhetens strategiske prioriteringer og markedsposisjon.',
            'help_what_is_assessed'  => 'Hvor godt anskaffelsen samsvarer med virksomhetens strategiske retning, målgruppe og prioriteringer.',
            'help_why_it_matters'    => 'Anbud utenfor strategisk retning bruker ressurser uten å bygge posisjon, referanser eller kompetanse over tid.',
            'help_what_to_investigate' => 'Samsvar med forretningsplan, bransjefokus, geografi, eksisterende referanseportefølje og langsiktige vekstmål.',
            'help_positive_indicators' => 'Klar kobling til eksisterende portefølje og kompetanse. Anskaffelsen styrker posisjonen i et prioritert marked.',
            'help_warning_signs'     => 'Ukjent bransje eller geografi, lite relevant for vekststrategi, svak kobling til kjernekompetanse.',
            'help_example_assessment' => 'Anskaffelsen er innenfor et tjenesteområde vi prioriterer. Den styrker referansebasen og passer vår markedsstrategi.',
            'weight'                 => 2,
            'is_score_reversed'      => false,
            'sort_order'             => 1,
        ],
        [
            'title'                  => 'Kravoppfyllelse',
            'short_description'      => 'I hvilken grad virksomheten oppfyller kvalifikasjonskrav og kan dokumentere dette.',
            'help_what_is_assessed'  => 'Om virksomheten oppfyller alle formelle kvalifikasjonskrav og kan dokumentere dette tilstrekkelig.',
            'help_why_it_matters'    => 'Manglende eller svak dokumentasjon på krav er en av de vanligste årsakene til avvisning tidlig i prosessen.',
            'help_what_to_investigate' => 'Minstekrav til omsetning og kapasitet, sertifiseringer, HMS-dokumentasjon, referanser og forsikringer.',
            'help_positive_indicators' => 'Alle krav oppfylt med god margin. Relevant dokumentasjon er tilgjengelig og oppdatert.',
            'help_warning_signs'     => 'Usikkerhet rundt ett eller flere krav, svak referansedokumentasjon, manglende sertifisering eller utdaterte HMS-rutiner.',
            'help_example_assessment' => 'Vi oppfyller alle kvalifikasjonskrav. Referansene er relevante og godt dokumenterte. Ingen åpne avvik.',
            'weight'                 => 3,
            'is_score_reversed'      => false,
            'sort_order'             => 2,
        ],
        [
            'title'                  => 'Kunderelasjon',
            'short_description'      => 'Hvor godt virksomheten kjenner kunden, behovene og organisasjonen bak anskaffelsen.',
            'help_what_is_assessed'  => 'Hvor godt virksomheten kjenner kunden, behovene og organisasjonen bak anskaffelsen.',
            'help_why_it_matters'    => 'God kundekjennskap gir bedre forståelse av behov, prioriteringer, risiko og evalueringslogikk.',
            'help_what_to_investigate' => 'Tidligere leveranser, dialoghistorikk, kjennskap til beslutningstakere og erfaring med kundens arbeidsform.',
            'help_positive_indicators' => 'Tidligere vellykkede leveranser, høy tillit, dokumentert kjennskap til kundens behov og relevante kontaktpunkter.',
            'help_warning_signs'     => 'Ingen tidligere kontakt, svak forståelse av behovet, ukjent beslutningsmiljø eller konkurrenter med sterkere relasjon.',
            'help_example_assessment' => 'Vi har levert relevante tjenester til kunden tidligere, kjenner behovsbildet godt og har referanser som samsvarer med anskaffelsen.',
            'weight'                 => 2,
            'is_score_reversed'      => false,
            'sort_order'             => 3,
        ],
        [
            'title'                  => 'Kundens kjennskap til oss',
            'short_description'      => 'I hvilken grad kunden kjenner virksomhetens navn, leveranser og omdømme.',
            'help_what_is_assessed'  => 'I hvilken grad kunden kjenner virksomhetens navn, leveranser og omdømme fra tidligere.',
            'help_why_it_matters'    => 'En kjent leverandør oppfattes som mindre risikofylt. Ukjente leverandører må bruke mer plass på å bygge tillit i tilbudet.',
            'help_what_to_investigate' => 'Tidligere leveranser til kunden, deltakelse i RFI/dialogmøter, synlighet i kundens marked og eventuelle referanser.',
            'help_positive_indicators' => 'Kunden kjenner oss fra tidligere, vi er nevnt i markedsdialog, har relevante referanser i kundens nettverk.',
            'help_warning_signs'     => 'Ukjent leverandør, ingen tidligere kontakt, konkurrenter med synlig posisjon og sterkere synlighet hos kunden.',
            'help_example_assessment' => 'Vi leverte et relatert prosjekt til kunden for to år siden og er en kjent aktør i dette segmentet. Omdømmet er godt.',
            'weight'                 => 2,
            'is_score_reversed'      => false,
            'sort_order'             => 4,
        ],
        [
            'title'                  => 'Kjennskap til anbudet',
            'short_description'      => 'Hvor godt vi forstår anskaffelsens omfang, behov, kontekst og evalueringslogikk.',
            'help_what_is_assessed'  => 'Hvor godt vi forstår anskaffelsens omfang, behov, kontekst og evalueringslogikk.',
            'help_why_it_matters'    => 'God innsikt i behov og evalueringskriterier gir bedre tilpasning av tilbudet og reduserer risiko for bomskudd.',
            'help_what_to_investigate' => 'Deltakelse i markedsdialog, kjennskap til konkurransegrunnlaget, forståelse av evalueringsvekter og politiske føringer.',
            'help_positive_indicators' => 'Godt kjennskap til kravbildet, deltakelse i dialog, realistisk forventning til evalueringslogikk.',
            'help_warning_signs'     => 'Anbudet er ukjent, evalueringsvektene er uklare, tildelingskriteriene er vanskelige å treffe uten mer informasjon.',
            'help_example_assessment' => 'Vi kjenner kravbildet fra markedsdialog og har god forståelse av evalueringskriteriene. Ingen åpne tolkningsspørsmål.',
            'weight'                 => 2,
            'is_score_reversed'      => false,
            'sort_order'             => 5,
        ],
        [
            'title'                  => 'Leveranseevne',
            'short_description'      => 'Om virksomheten kan levere kontrakten med tilstrekkelig kapasitet, kompetanse og kontroll.',
            'help_what_is_assessed'  => 'Om virksomheten faktisk kan levere kontrakten med tilstrekkelig kapasitet, kompetanse og kontroll.',
            'help_why_it_matters'    => 'Manglende kapasitet er en av de vanligste risikofaktorene som ikke avdekkes tidlig nok – og som kan ødelegge leveransen.',
            'help_what_to_investigate' => 'Tilgjengelig kapasitet, riktig kompetanseprofil, underleverandørbehov, prosjektleder og nøkkelressurser.',
            'help_positive_indicators' => 'Klart team med riktig kompetanse tilgjengelig. God erfaring med tilsvarende kontrakter og omfang.',
            'help_warning_signs'     => 'Nøkkelpersoner ikke tilgjengelige, høy avhengighet av underleverandører, liten erfaring med tilsvarende omfang.',
            'help_example_assessment' => 'Vi har et tilgjengelig team med riktig kompetanse. Kapasiteten er bekreftet av avdelingsleder. Ingen kritiske avhengigheter.',
            'weight'                 => 3,
            'is_score_reversed'      => false,
            'sort_order'             => 6,
        ],
        [
            'title'                  => 'Konkurranseposisjon',
            'short_description'      => 'Vår relative styrke sammenlignet med sannsynlige konkurrenter i denne anskaffelsen.',
            'help_what_is_assessed'  => 'Vår relative styrke sammenlignet med sannsynlige konkurrenter i denne anskaffelsen.',
            'help_why_it_matters'    => 'Realistisk vurdering av konkurransebildet hjelper med prisstrategi, differensiering og beslutning om ressursbruk.',
            'help_what_to_investigate' => 'Hvem som sannsynligvis deltar, konkurrentenes styrker og svakheter, og vårt reelle differensieringsgrunnlag.',
            'help_positive_indicators' => 'Sterk posisjon mot kjente konkurrenter, unike referanser, tydelig pris- eller kompetansefortrinn.',
            'help_warning_signs'     => 'Sterkt preferert konkurrent, svak differensiering, manglende relevante referanser sammenlignet med de andre.',
            'help_example_assessment' => 'Vi er blant to–tre sannsynlige kandidater med relevante referanser. Differensieringsgrunnlaget er tydelig og dokumenterbart.',
            'weight'                 => 2,
            'is_score_reversed'      => false,
            'sort_order'             => 7,
        ],
        [
            'title'                  => 'Risiko',
            'short_description'      => 'Operasjonell, kontraktuell og omdømmemessig risiko ved å delta og eventuelt vinne. Lav risiko er positivt.',
            'help_what_is_assessed'  => 'Operasjonell, juridisk, omdømmemessig og kontraktuell risiko ved å delta og eventuelt vinne kontrakten.',
            'help_why_it_matters'    => 'Høy risiko kan gjøre kontrakten ulønnsom eller skadelig – selv om vi vinner. Risiko bør vurderes eksplisitt, ikke ignoreres.',
            'help_what_to_investigate' => 'Kontraktsvilkår, sanksjonsregime, budsjettusikkerhet hos kunden, politisk/regulatorisk risiko og avhengigheter.',
            'help_positive_indicators' => 'Kjente og akseptable kontraktsvilkår, lav eksponering, realistisk omfang og leveranserisiko.',
            'help_warning_signs'     => 'Urimelige sanksjoner, lav margin, uklar scope, sterk avhengighet av tredjeparter med høy risiko.',
            'help_example_assessment' => 'Kontraktsvilkårene er kjente. Vi har identifisert én leveranserisiko som håndteres ved avklaring med kunde i spørsmålsrunden.',
            'weight'                 => 3,
            'is_score_reversed'      => true,
            'sort_order'             => 8,
        ],
        [
            'title'                  => 'Kommersiell verdi',
            'short_description'      => 'Kontraktens lønnsomhet, strategiske verdi og bidrag til vekst over tid.',
            'help_what_is_assessed'  => 'Kontraktens lønnsomhet, strategiske verdi og bidrag til vekst og posisjonering over tid.',
            'help_why_it_matters'    => 'En kontrakt kan vinnes uten å skape verdi. Ressursbruk på tilbud bør stå i forhold til forventet avkastning og strategisk gevinst.',
            'help_what_to_investigate' => 'Estimert margin, kontraktsverdi over tid, referanseverdi, kompetansebygging og mulighet for forlengelse eller oppfølgekontrakter.',
            'help_positive_indicators' => 'God margin, høy kontraktsverdi, sterk referanseverdi, bidrar til vekst i et prioritert segment.',
            'help_warning_signs'     => 'Lav margin, engangskontrakt uten strategisk verdi, høy ressursbruk i tilbudsfase relativt til forventet kontraktsverdi.',
            'help_example_assessment' => 'Kontrakten er lønnsom, gir god referanse i et prioritert segment og kan åpne for videre rammeavtale med kunden.',
            'weight'                 => 2,
            'is_score_reversed'      => false,
            'sort_order'             => 9,
        ],
    ];

    /**
     * Ensure the customer has an active default template.  Creates one from
     * Procynia's standard nine criteria if none exists.  Safe to call repeatedly.
     *
     * We chose this lazy-creation approach (rather than a seeder or migration)
     * because templates are customer-owned data, not application data.  Running it
     * at first access guarantees existing customers get the default without a
     * manual one-off migration.
     */
    public function ensureDefaultExists(int $customerId): GoNoGoAssessmentTemplate
    {
        $existing = GoNoGoAssessmentTemplate::query()
            ->where('customer_id', $customerId)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $this->createDefault($customerId);
    }

    private function createDefault(int $customerId): GoNoGoAssessmentTemplate
    {
        // Guard against a race condition creating two defaults
        return GoNoGoAssessmentTemplate::query()
            ->where('customer_id', $customerId)
            ->where('is_default', true)
            ->firstOr(function () use ($customerId): GoNoGoAssessmentTemplate {
                $template = GoNoGoAssessmentTemplate::query()->create([
                    'customer_id' => $customerId,
                    'name'        => 'Standard vurdering',
                    'description' => 'Procynias ni standarddimensjoner for Go/No-go-vurdering.',
                    'is_default'  => true,
                    'is_active'   => true,
                    'created_by'  => null,
                    'updated_by'  => null,
                ]);

                foreach (self::DEFAULT_CRITERIA as $criterionData) {
                    GoNoGoAssessmentCriterion::query()->create(
                        array_merge(['template_id' => $template->id], $criterionData),
                    );
                }

                return $template;
            });
    }
}
