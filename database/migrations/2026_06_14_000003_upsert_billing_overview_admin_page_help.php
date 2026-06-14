<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('admin_page_helps')->updateOrInsert(
            ['page_key' => 'admin.billing.overview'],
            [
                'title'       => 'Faktureringsoversikt',
                'description' => 'Samlet administrativ oversikt over kunders abonnement, kommersiell status og faktureringsnøkkeltall.',
                'intro'       => 'Fakturering → Oversikt gir en samlet oversikt over alle kunders abonnement og kommersielle status. Siden er utgangspunktet for å navigere videre til den enkelte kundes faktureringsside. Detaljert administrasjon skjer i Tjenestekatalog, AI-forbruk og AI-lønnsomhet.',
                'sections'    => json_encode([
                    [
                        'title' => 'Hva brukes denne siden til?',
                        'items' => [
                            ['title' => '', 'text' => 'Siden gir en samlet administrativ oversikt over kunders abonnement, kommersiell status og relevante faktureringsnøkkeltall.'],
                            ['title' => '', 'text' => 'Herfra kan du navigere direkte til den enkelte kundes faktureringsside for detaljert administrasjon.'],
                            ['title' => '', 'text' => 'Siden er beregnet på intern kontroll av abonnements- og faktureringstilstand på tvers av alle kunder.'],
                        ],
                    ],
                    [
                        'title' => 'Sammenheng med Tjenestekatalog',
                        'items' => [
                            ['title' => '', 'text' => 'Tjenester, priser, inkluderte brukere og AI-rammer administreres i Fakturering → Tjenestekatalog.'],
                            ['title' => '', 'text' => 'Oversikt viser status og nøkkeltall basert på hva som er satt opp i Tjenestekatalog og registrert på kunden. Oversikt endrer ikke kommersielle rammer.'],
                            ['title' => '', 'text' => 'Dersom en kunde mangler korrekt abonnementsplan eller prisgrunnlag, er Tjenestekatalog det rette stedet å rette opp dette.'],
                        ],
                    ],
                    [
                        'title' => 'Sammenheng med AI-forbruk og AI-lønnsomhet',
                        'items' => [
                            ['title' => '', 'text' => 'AI-relaterte rammer og estimater hentes fra kommersielle data registrert i Tjenestekatalog og kundens avtaleoppsett.'],
                            ['title' => '', 'text' => 'Detaljert AI-bruk mot kapasitet kontrolleres i Fakturering → AI-forbruk.'],
                            ['title' => '', 'text' => 'Estimert inntekt, intern AI-kost og dekningsbidrag kontrolleres i Fakturering → AI-lønnsomhet.'],
                            ['title' => '', 'text' => 'Oversikt gir ikke tilgang til disse beregningene direkte. Bruk de dedikerte sidene for AI-analyse.'],
                        ],
                    ],
                    [
                        'title' => 'Hva bør du kontrollere her?',
                        'items' => [
                            ['title' => '', 'text' => 'Kontroller at kunden har riktig aktiv abonnementsplan.'],
                            ['title' => '', 'text' => 'Kontroller faktureringsstatus og abonnements-/betalingsstatus for aktive kunder.'],
                            ['title' => '', 'text' => 'Kontroller at kunden er koblet til Stripe dersom Stripe-basert fakturering er forventet.'],
                            ['title' => '', 'text' => 'Kontroller om kunder er i prøveperiode og om prøveperioden nærmer seg utløp.'],
                            ['title' => '', 'text' => 'Kontroller om kunden har riktig kommersiell ramme sett mot faktisk bruk og antall brukere.'],
                        ],
                    ],
                    [
                        'title' => 'Hva er ikke denne siden?',
                        'items' => [
                            ['title' => '', 'text' => 'Denne siden er ikke regnskap.'],
                            ['title' => '', 'text' => 'Denne siden er ikke faktura.'],
                            ['title' => '', 'text' => 'Denne siden er ikke stedet for manuell endring av tekniske Stripe-felter.'],
                            ['title' => '', 'text' => 'Kommersielle prisregler og AI-rammer endres i Tjenestekatalog, ikke her.'],
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
        DB::table('admin_page_helps')->where('page_key', 'admin.billing.overview')->delete();
    }
};
