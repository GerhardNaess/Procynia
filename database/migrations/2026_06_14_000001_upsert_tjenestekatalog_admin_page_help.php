<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('admin_page_helps')->updateOrInsert(
            ['page_key' => 'admin.tjenestekatalog'],
            [
                'title'       => 'Tjenestekatalog',
                'description' => 'Primær kommersiell kilde for tjenester, priser og AI-rammer i Procynia.',
                'intro'       => 'Tjenestekatalog er master for alle varer og tjenester som kan selges til kunder. Data herfra brukes direkte i Fakturering → AI-forbruk og Fakturering → AI-lønnsomhet. Feil eller manglende oppsett her gir direkte utslag i disse oversiktene.',
                'sections'    => json_encode([
                    [
                        'title' => 'Hva er Tjenestekatalog?',
                        'items' => [
                            ['title' => '', 'text' => 'Tjenestekatalog er den primære kommersielle kilden for tjenester, priser og AI-rammer i Procynia.'],
                            ['title' => '', 'text' => 'Her registreres alle varer og tjenester som kan selges til kunder, inkludert abonnementsplaner med tilhørende priser og kapasitet.'],
                            ['title' => '', 'text' => 'Data i Tjenestekatalog brukes aktivt i AI-forbruk og AI-lønnsomhet. Feil eller manglende oppsett her gir direkte utslag i disse oversiktene.'],
                        ],
                    ],
                    [
                        'title' => 'Hvilke felt styrer AI-kapasitet (AI-forbruk)?',
                        'items' => [
                            ['title' => '', 'text' => 'Feltet «Inkludert antall AI-tilbud» (included_ai_offers) styrer AI-kapasiteten som vises i Fakturering → AI-forbruk.'],
                            ['title' => '', 'text' => 'For at Tjenestekatalog skal være aktiv kapasitetskilde, må feltet settes til en verdi over 0 på den aktive abonnementsplanlinjen for kunden.'],
                            ['title' => '', 'text' => 'Dersom feltet er 0, henter systemet kapasitet fra eldre plandata på kunden. Dette gjelder abonnementsplanpriser som er opprettet uten at included_ai_offers er satt manuelt.'],
                            ['title' => '', 'text' => 'Kapasitet leses kun fra aktive eller ventende-avslutning-linjer (status: active eller pending_cancel) knyttet til en abonnementsplan (kategori: Basisplan).'],
                        ],
                    ],
                    [
                        'title' => 'Hvilke felt styrer inntektsgrunnlag (AI-lønnsomhet)?',
                        'items' => [
                            ['title' => '', 'text' => 'Feltet «Pris» (unit_amount) og valuta (currency) styrer inntektsgrunnlaget som brukes i Fakturering → AI-lønnsomhet.'],
                            ['title' => '', 'text' => 'Valuta (currency) må være NOK for at prisen skal brukes i beregningene. Priser i andre valutaer ignoreres stille og gir manglende inntektsgrunnlag i AI-lønnsomhet.'],
                            ['title' => '', 'text' => 'Månedlige priser (interval: monthly) brukes direkte. Årlige priser (interval: yearly) periodiseres til månedlig verdi ved å dele årsbeløpet på 12.'],
                            ['title' => '', 'text' => 'Kundespesifikk pris kan overstyre standardprisen dersom kundens faktureringslinje bruker kildetype kundepris (SOURCE_CUSTOMER_PRICE) og inneholder custom_unit_amount i metadata. Denne prisen settes ikke i Tjenestekatalog, men i kundens avtaleoppsett.'],
                        ],
                    ],
                    [
                        'title' => 'Hva bør du kontrollere her?',
                        'items' => [
                            ['title' => '', 'text' => 'Kontroller at abonnementsplanpriser har Inkludert antall AI-tilbud (included_ai_offers) satt til en verdi over 0 for at Tjenestekatalog skal være aktiv kapasitetskilde i AI-forbruk.'],
                            ['title' => '', 'text' => 'Kontroller at valuta er satt til nok (NOK) på alle priser som skal gi grunnlag i AI-lønnsomhet.'],
                            ['title' => '', 'text' => 'Kontroller at aktive abonnementsplanpriser har korrekt pris i feltet Pris. Prisen lagres internt i øre og vises i kroner i skjemaet.'],
                            ['title' => '', 'text' => 'Kontroller at inaktive prislinjer er markert som inaktive dersom de ikke lenger skal gjelde. Systemet bruker kun aktive eller pending_cancel-linjer.'],
                            ['title' => '', 'text' => 'Dersom AI-forbruk eller AI-lønnsomhet viser manglende grunnlag for en kunde, er første steg å sjekke om kunden har en aktiv abonnementsplanlinje med korrekt included_ai_offers og unit_amount i Tjenestekatalog.'],
                        ],
                    ],
                    [
                        'title' => 'Hva skal ikke endres her?',
                        'items' => [
                            ['title' => '', 'text' => 'Stripe Price ID er et teknisk koblingsfeltet mellom Procynia og Stripe. Det skal ikke endres manuelt uten separat avklaring med teknisk ansvarlig.'],
                            ['title' => '', 'text' => 'Stripe Price ID er låst for redigering etter opprettelse. Duplisering av en prislinje nullstiller Stripe-koblingen automatisk.'],
                            ['title' => '', 'text' => 'Intern nøkkel (key) er låst for redigering. Opprett en ny oppføring dersom det er behov for prisendring med ny nøkkel.'],
                            ['title' => '', 'text' => 'Tjenestekatalog er ikke regnskap og ikke faktura. Den beskriver det kommersielle grunnlaget for estimater i AI-forbruk og AI-lønnsomhet.'],
                        ],
                    ],
                ]),
                'is_active'  => true,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('admin_page_helps')->where('page_key', 'admin.tjenestekatalog')->delete();
    }
};
