<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('admin_page_helps')->updateOrInsert(
            ['page_key' => 'admin.ai_usage_capacity'],
            [
                'title'       => 'AI-bruksmønster og varsler',
                'description' => 'Operativ kontrollflate for AI-bruk, kapasitet, varsler og blokkeringer i Procynia.',
                'intro'       => 'AI-bruksmønster og varsler brukes av intern admin for å følge med på kunders AI-bruksmønster, kapasitet, varsler og eventuelle blokkeringer. Siden er en operativ kontrollflate for å oppdage uvanlig bruk, kapasitetsproblemer eller kunder som nærmer seg kommersielle rammer. Siden er ikke en fakturaside og ikke et regnskap.',
                'sections'    => json_encode([
                    [
                        'title' => 'Hva brukes denne siden til?',
                        'items' => [
                            ['title' => '', 'text' => 'Siden brukes av intern admin for å følge med på kunders AI-bruksmønster, kapasitet, varsler og eventuelle blokkeringer.'],
                            ['title' => '', 'text' => 'Siden er en operativ kontrollflate for å oppdage uvanlig bruk, kapasitetsproblemer eller kunder som nærmer seg kommersielle rammer.'],
                            ['title' => '', 'text' => 'Siden er ikke en fakturaside og ikke et regnskap.'],
                        ],
                    ],
                    [
                        'title' => 'Hva betyr tallene?',
                        'items' => [
                            ['title' => '', 'text' => 'AI-bruk må forstås i sammenheng med kommersielle rammer og kundens tjenestenivå.'],
                            ['title' => '', 'text' => 'Kapasitet og bruk skal ses mot rammene som er definert gjennom Tjenestekatalog og tilhørende entitlements.'],
                            ['title' => '', 'text' => 'Varsler peker på forhold som bør vurderes av admin, men er ikke alene en endelig beslutning.'],
                            ['title' => '', 'text' => 'Blokkeringer eller risikoflagg må vurderes mot kundens avtale, faktisk bruk og teknisk status.'],
                        ],
                    ],
                    [
                        'title' => 'Sammenheng med Tjenestekatalog, AI-forbruk og AI-lønnsomhet',
                        'items' => [
                            ['title' => '', 'text' => 'Tjenestekatalog er primær kommersiell kilde for tjenester, priser og AI-rammer.'],
                            ['title' => '', 'text' => 'AI-forbruk viser faktisk bruk mot kundens kommersielle kapasitet.'],
                            ['title' => '', 'text' => 'AI-lønnsomhet viser interne estimater for inntekt, intern AI-kost og dekningsbidrag.'],
                            ['title' => '', 'text' => 'AI-bruksmønster og varsler brukes til operativ oppfølging av mønstre, risiko og kapasitet, ikke til å definere kommersielle sannheter.'],
                        ],
                    ],
                    [
                        'title' => 'Hva bør admin kontrollere?',
                        'items' => [
                            ['title' => '', 'text' => 'Kunder med høy eller uvanlig AI-bruk.'],
                            ['title' => '', 'text' => 'Kunder som nærmer seg eller overskrider kapasitet.'],
                            ['title' => '', 'text' => 'Varsler som indikerer feil, blokkeringer eller uventet mønster.'],
                            ['title' => '', 'text' => 'Om Tjenestekatalog og kundens tjenestenivå er korrekt satt opp.'],
                            ['title' => '', 'text' => 'Om det er behov for kommersiell oppfølging, teknisk kontroll eller justering av abonnement/tjenestenivå.'],
                        ],
                    ],
                    [
                        'title' => 'Avgrensning',
                        'items' => [
                            ['title' => '', 'text' => 'Siden skal ikke brukes til manuell prisstyring.'],
                            ['title' => '', 'text' => 'Siden skal ikke overstyre Tjenestekatalog.'],
                            ['title' => '', 'text' => 'Siden er ikke faktura, regnskap eller kundeavtale.'],
                            ['title' => '', 'text' => 'Siden viser operativ styringsinformasjon og må vurderes sammen med AI-forbruk, AI-lønnsomhet og kundens kommersielle oppsett.'],
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
        DB::table('admin_page_helps')->where('page_key', 'admin.ai_usage_capacity')->delete();
    }
};
