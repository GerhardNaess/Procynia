<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('admin_page_helps')->updateOrInsert(
            ['page_key' => 'admin.sync_logs'],
            [
                'title'       => 'Synkroniseringslogg',
                'description' => 'Teknisk logg for synkroniseringer og systemprosesser som oppdaterer data i Procynia.',
                'intro'       => 'Synkroniseringslogg viser tekniske logger for synkroniseringer og systemprosesser som henter, oppdaterer eller samordner data i Procynia. Siden brukes av intern admin for å kontrollere om synkroniseringer kjører som forventet og for å finne feil, avvik, forsinkelser eller uventede resultater i bakgrunnsprosesser.',
                'sections'    => json_encode([
                    [
                        'title' => 'Hva brukes denne siden til?',
                        'items' => [
                            ['title' => '', 'text' => 'Siden viser tekniske logger for synkroniseringer og systemprosesser som henter, oppdaterer eller samordner data i Procynia.'],
                            ['title' => '', 'text' => 'Den brukes av intern admin for å kontrollere om synkroniseringer kjører som forventet.'],
                            ['title' => '', 'text' => 'Loggen kan brukes til å finne feil, avvik, forsinkelser eller uventede resultater i bakgrunnsprosesser.'],
                        ],
                    ],
                    [
                        'title' => 'Hva bør admin se etter?',
                        'items' => [
                            ['title' => '', 'text' => 'Feilede eller avbrutte synkroniseringer.'],
                            ['title' => '', 'text' => 'Gjentakende feil på samme kilde, jobb eller prosess.'],
                            ['title' => '', 'text' => 'Store avvik i antall behandlede elementer.'],
                            ['title' => '', 'text' => 'Synkroniseringer som ikke har kjørt som forventet.'],
                            ['title' => '', 'text' => 'Varsler eller feilmeldinger som peker mot integrasjon, nettverk, autentisering eller datakvalitet.'],
                        ],
                    ],
                    [
                        'title' => 'Sammenheng med resten av Drift',
                        'items' => [
                            ['title' => '', 'text' => 'Systemstatus gir rask helsesjekk.'],
                            ['title' => '', 'text' => 'Varsler kan peke på hendelser som bør undersøkes i Synkroniseringslogg.'],
                            ['title' => '', 'text' => 'Importkjøringer gir mer spesifikk informasjon om importprosesser.'],
                            ['title' => '', 'text' => 'Driftsrutiner bør beskrive hvordan faste feil eller avvik i synkronisering skal følges opp.'],
                            ['title' => '', 'text' => 'Avvik og forbedringer kan brukes dersom loggen viser gjentakende feil eller behov for varig tiltak.'],
                        ],
                    ],
                    [
                        'title' => 'Avgrensning',
                        'items' => [
                            ['title' => '', 'text' => 'Siden er ikke en komplett overvåkningsplattform.'],
                            ['title' => '', 'text' => 'Siden er ikke en incident-logg.'],
                            ['title' => '', 'text' => 'Siden viser teknisk sporingsinformasjon og må tolkes sammen med logger, varsler og relevant kontekst.'],
                            ['title' => '', 'text' => 'En enkelt feil i loggen betyr ikke nødvendigvis at hele systemet er nede.'],
                        ],
                    ],
                    [
                        'title' => 'Anbefalt bruk',
                        'items' => [
                            ['title' => '', 'text' => 'Kontroller loggen ved feil i import, datagrunnlag eller integrasjoner.'],
                            ['title' => '', 'text' => 'Bruk filtrering og sortering for å finne siste relevante kjøring eller gjentakende mønstre.'],
                            ['title' => '', 'text' => 'Følg opp gjentakende feil som avvik eller forbedringspunkt.'],
                            ['title' => '', 'text' => 'Oppdater relevante driftsrutiner dersom feilen krever fast prosedyre.'],
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
        DB::table('admin_page_helps')->where('page_key', 'admin.sync_logs')->delete();
    }
};
