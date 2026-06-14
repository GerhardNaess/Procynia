<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('admin_page_helps')->updateOrInsert(
            ['page_key' => 'admin.system_status'],
            [
                'title'       => 'Systemstatus',
                'description' => 'Operativ oversikt over teknisk og operativ status i Procynia.',
                'intro'       => 'Systemstatus er startpunktet for en rask helsesjekk av Procynia. Siden gir administrator en samlet oversikt over om sentrale deler av systemet ser friske ut, og peker videre til andre Drift-sider når noe krever oppfølging.',
                'sections'    => json_encode([
                    [
                        'title' => 'Hva brukes denne siden til?',
                        'items' => [
                            ['title' => '', 'text' => 'Siden gir administrator en samlet oversikt over teknisk og operativ status i Procynia.'],
                            ['title' => '', 'text' => 'Den brukes til rask kontroll av om sentrale deler av systemet ser friske ut.'],
                            ['title' => '', 'text' => 'Den er ment som en operativ driftsside, ikke som en komplett overvåkningsplattform.'],
                        ],
                    ],
                    [
                        'title' => 'Hva bør admin se etter?',
                        'items' => [
                            ['title' => '', 'text' => 'Se etter feil eller varsler i sentrale systemkomponenter.'],
                            ['title' => '', 'text' => 'Se etter avvik i køer, planlagte jobber eller integrasjoner dersom siden viser dette.'],
                            ['title' => '', 'text' => 'Se etter tegn på at import, AI-jobber, varslinger eller andre bakgrunnsprosesser ikke går som forventet.'],
                            ['title' => '', 'text' => 'Se etter statuspunkter som krever videre kontroll i andre Drift-sider.'],
                        ],
                    ],
                    [
                        'title' => 'Sammenheng med resten av Drift',
                        'items' => [
                            ['title' => '', 'text' => 'Systemstatus er inngangspunktet for rask helsesjekk.'],
                            ['title' => '', 'text' => 'Mer detaljer finnes i andre Drift-sider, for eksempel Driftsrutiner, Avvik og forbedringer, Sikkerhetskopi og gjenoppretting, Importkjøringer og Synkroniseringslogg.'],
                            ['title' => '', 'text' => 'Siden skal hjelpe admin å finne ut hvor videre feilsøking bør starte.'],
                        ],
                    ],
                    [
                        'title' => 'Avgrensning',
                        'items' => [
                            ['title' => '', 'text' => 'Siden er ikke en erstatning for teknisk overvåkning, logging eller incident-håndtering.'],
                            ['title' => '', 'text' => 'Siden skal ikke brukes alene som bevis på at alt fungerer.'],
                            ['title' => '', 'text' => 'Ved avvik må admin kontrollere relevante logger, køer, integrasjoner eller driftsrutiner.'],
                        ],
                    ],
                    [
                        'title' => 'Anbefalt bruk',
                        'items' => [
                            ['title' => '', 'text' => 'Sjekk Systemstatus ved oppstart av arbeidsdagen.'],
                            ['title' => '', 'text' => 'Sjekk siden etter deploy, større importjobber eller rapporterte feil.'],
                            ['title' => '', 'text' => 'Bruk siden som første stopp før mer detaljert feilsøking.'],
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
        DB::table('admin_page_helps')->where('page_key', 'admin.system_status')->delete();
    }
};
