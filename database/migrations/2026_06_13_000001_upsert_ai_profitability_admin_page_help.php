<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('admin_page_helps')->updateOrInsert(
            ['page_key' => 'admin.ai_profitability'],
            [
                'title'       => 'AI-lønnsomhet',
                'description' => 'Intern estimatside for inntekt, AI-kost og dekningsbidrag per kunde og AI-sak.',
                'intro'       => 'AI-lønnsomhet viser et internt estimat på om AI-bruken i Procynia er kommersielt bærekraftig. Siden bruker faktisk AI-bruk, registrert tokenforbruk, modellpriser, valutakurser og kundens kommersielle ramme. Tallene er estimater og skal ikke leses som regnskap eller faktura.',
                'sections'    => json_encode([
                    [
                        'title' => 'Hvor er dette i admin?',
                        'items' => [
                            ['title' => '', 'text' => 'Denne siden ligger under Fakturering → AI-lønnsomhet.'],
                            ['title' => '', 'text' => 'Siden henger sammen med Fakturering → AI-forbruk og Fakturering → Tjenestekatalog. Tjenestekatalog definerer kommersielle rammer som pris, abonnement og inkluderte AI-saker. AI-forbruk viser faktisk bruk mot kapasitet. AI-lønnsomhet viser estimert inntekt, intern AI-kost og dekningsbidrag per kunde og AI-sak.'],
                        ],
                    ],
                    [
                        'title' => 'Hva brukes denne siden til?',
                        'items' => [
                            ['title' => '', 'text' => 'Siden brukes til å vurdere om AI-bruken i Procynia er kommersielt bærekraftig.'],
                            ['title' => '', 'text' => 'Inntekten beregnes som en intern proxy. Systemet fordeler kundens månedlige baseplanverdi på antall inkluderte AI-saker. Både baseplanverdi og inkluderte AI-saker hentes primært fra Tjenestekatalog. Dersom Tjenestekatalog mangler nødvendig pris- eller kapasitetsdata, kan systemet falle tilbake til eldre plan- og kundedata.'],
                            ['title' => '', 'text' => 'Intern AI-kost beregnes fra registrerte tokenhendelser, modellpriser og valutakurser.'],
                        ],
                    ],
                    [
                        'title' => 'Sammenheng med andre sider',
                        'items' => [
                            ['title' => '', 'text' => 'Tjenestekatalog er den primære kommersielle kilden for tjenester, priser og inkluderte AI-saker.'],
                            ['title' => '', 'text' => 'AI-forbruk viser faktisk bruk, tekniske AI-hendelser, tokenforbruk og brukt AI-kapasitet.'],
                            ['title' => '', 'text' => 'AI-lønnsomhet bruker samme kommersielle ramme for kapasitet og pris, men beregner i tillegg estimert inntektsbidrag, estimert intern kost og estimert dekningsbidrag.'],
                            ['title' => '', 'text' => 'Tallene bør leses sammen med AI-forbruk. Dersom AI-forbruk viser aktive AI-saker, men AI-lønnsomhet viser manglende inntekts- eller kostgrunnlag, betyr det normalt at kommersielle data, modellpriser eller valutakurser må kontrolleres.'],
                        ],
                    ],
                    [
                        'title' => 'Hva bør du kontrollere her?',
                        'items' => [
                            ['title' => '', 'text' => 'Kontroller om kunder med AI-bruk har komplett kommersielt grunnlag i Tjenestekatalog.'],
                            ['title' => '', 'text' => 'Kontroller om kunden har aktiv baseplanlinje med korrekt pris og korrekt antall inkluderte AI-saker.'],
                            ['title' => '', 'text' => 'Kontroller røde eller manglende statuser. Slike statuser betyr vanligvis manglende datagrunnlag, ikke nødvendigvis at kunden er ulønnsom.'],
                            ['title' => '', 'text' => 'Kontroller om modellpriser finnes for modellene som faktisk er brukt.'],
                            ['title' => '', 'text' => 'Kontroller om valutakurser finnes for relevante datoer.'],
                            ['title' => '', 'text' => 'Kontroller om estimert intern AI-kost virker rimelig sett opp mot faktisk AI-bruk og antall aktive saker.'],
                        ],
                    ],
                    [
                        'title' => 'Hva skal ikke endres her?',
                        'items' => [
                            ['title' => '', 'text' => 'Denne siden er ikke regnskap.'],
                            ['title' => '', 'text' => 'Denne siden er ikke faktura.'],
                            ['title' => '', 'text' => 'Denne siden skal ikke brukes som grunnlag for direkte fakturering av kunde.'],
                            ['title' => '', 'text' => 'Ikke juster kommersielle rammer manuelt basert på tallene her. Pris, inkluderte AI-saker og abonnement skal styres fra Tjenestekatalog og kundens aktive avtalegrunnlag.'],
                            ['title' => '', 'text' => 'Ikke tolk manglende kostgrunnlag som null kost. Manglende modellpris eller valutakurs betyr at systemet ikke kan beregne kostnaden sikkert.'],
                            ['title' => '', 'text' => 'Ikke tolk estimert dekningsbidrag som endelig lønnsomhet. Tallene er interne estimater og inkluderer ikke alle kostnader, rabatter, manuelle avtaler, support, drift eller øvrige leveransekostnader.'],
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
        DB::table('admin_page_helps')->where('page_key', 'admin.ai_profitability')->delete();
    }
};
