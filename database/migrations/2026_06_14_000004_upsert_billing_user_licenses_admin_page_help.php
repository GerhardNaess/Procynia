<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('admin_page_helps')->updateOrInsert(
            ['page_key' => 'admin.billing.user_licenses'],
            [
                'title'       => 'Brukerlisenser',
                'description' => 'Kommersiell og operativ kontroll av hvilke brukere som er knyttet til betalte tjenestenivåer.',
                'intro'       => 'Brukerlisenser gir oversikt over kundens brukere og hvilke tjenestenivåer de er knyttet til. Siden brukes til å sikre at riktige brukere har tilgang til betalte funksjoner i samsvar med kundens avtale. Kommersielle rammer og priser styres fra Tjenestekatalog.',
                'sections'    => json_encode([
                    [
                        'title' => 'Hva brukes denne siden til?',
                        'items' => [
                            ['title' => '', 'text' => 'Siden brukes til administrasjon av kundens brukere og hvilket tjenestenivå eller abonnement de er knyttet til.'],
                            ['title' => '', 'text' => 'Siden brukes til kommersiell og operativ kontroll av hvilke brukere som har tilgang til betalte funksjoner.'],
                            ['title' => '', 'text' => 'Herfra kan du navigere videre til den enkelte brukers tjenestenivåoppsett for detaljert administrasjon.'],
                        ],
                    ],
                    [
                        'title' => 'Sammenheng med Tjenestekatalog',
                        'items' => [
                            ['title' => '', 'text' => 'Tjenestekatalog er kommersiell master for tjenester, priser og inkluderte AI-rammer. Tjenestekatalog er det rette stedet å endre kommersiell ramme.'],
                            ['title' => '', 'text' => 'Brukerlisenser viser og administrerer brukertilknytning mot tjenestenivå, men er ikke en manuell prissannhet.'],
                            ['title' => '', 'text' => 'Eventuelle avvik mellom brukerens tjenestenivå og kundens avtale bør avklares med utgangspunkt i Tjenestekatalog, ikke manuelt i Brukerlisenser.'],
                        ],
                    ],
                    [
                        'title' => 'Sammenheng med AI-forbruk og AI-lønnsomhet',
                        'items' => [
                            ['title' => '', 'text' => 'AI-forbruk viser faktisk AI-bruk mot kommersiell ramme definert i Tjenestekatalog.'],
                            ['title' => '', 'text' => 'AI-lønnsomhet bruker kommersiell ramme og prisgrunnlag for å estimere inntekt og kostnad.'],
                            ['title' => '', 'text' => 'Brukerlisenser påvirker hvem som har tilgang til AI-funksjoner, men skal ikke overstyre Tjenestekatalog som kommersiell kilde for kapasitet og pris.'],
                        ],
                    ],
                    [
                        'title' => 'Hva bør du kontrollere her?',
                        'items' => [
                            ['title' => '', 'text' => 'Kontroller at riktige brukere har riktig tjenestenivå i samsvar med kundens avtale.'],
                            ['title' => '', 'text' => 'Kontroller at betalte funksjoner ikke gis uten gyldig tjenestetilknytning.'],
                            ['title' => '', 'text' => 'Kontroller at endringer i brukertilknytning er avklart med kundens avtaleoppsett.'],
                            ['title' => '', 'text' => 'Kontroller at Tjenestekatalog er oppdatert før kommersielle vurderinger gjøres basert på brukerlisenser.'],
                        ],
                    ],
                    [
                        'title' => 'Hva er ikke denne siden?',
                        'items' => [
                            ['title' => '', 'text' => 'Denne siden er ikke regnskap.'],
                            ['title' => '', 'text' => 'Denne siden er ikke faktura.'],
                            ['title' => '', 'text' => 'Siden skal ikke brukes til manuell prisstyring.'],
                            ['title' => '', 'text' => 'Stripe eller tekniske betalingskoblinger skal ikke presenteres som kundens kommersielle sannhet. Kommersielle rammer og priser endres i Tjenestekatalog.'],
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
        DB::table('admin_page_helps')->where('page_key', 'admin.billing.user_licenses')->delete();
    }
};
