<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('admin_page_helps')->updateOrInsert(
            ['page_key' => 'admin.notifications'],
            [
                'title'       => 'Varsler',
                'description' => 'Operativ oversikt over interne adminvarsler som krever oppmerksomhet fra drift eller intern administrator.',
                'intro'       => 'Varsler viser interne adminvarsler som bør følges opp av drift eller intern administrator. Siden brukes som en operativ oppfølgingsflate for forhold som kan gjelde tekniske hendelser, synkroniseringer, prisgrunnlag, AI-kost, valutakurser, integrasjoner eller andre systemhendelser. Den er ikke en vanlig kundeside.',
                'sections'    => json_encode([
                    [
                        'title' => 'Hva brukes denne siden til?',
                        'items' => [
                            ['title' => '', 'text' => 'Siden viser interne adminvarsler som krever oppmerksomhet fra drift eller intern administrator.'],
                            ['title' => '', 'text' => 'Varsler kan gjelde tekniske forhold, synkroniseringer, prisgrunnlag, AI-kost, valutakurser, integrasjoner eller andre systemhendelser som bør følges opp.'],
                            ['title' => '', 'text' => 'Siden er en operativ oppfølgingsflate, ikke en vanlig kundeside.'],
                        ],
                    ],
                    [
                        'title' => 'Hva bør admin se etter?',
                        'items' => [
                            ['title' => '', 'text' => 'Nye eller uleste varsler.'],
                            ['title' => '', 'text' => 'Varsler som indikerer feil i integrasjoner eller bakgrunnsjobber.'],
                            ['title' => '', 'text' => 'Varsler om manglende eller utdatert datagrunnlag.'],
                            ['title' => '', 'text' => 'Varsler som krever manuell kontroll før tall eller vurderinger brukes videre.'],
                            ['title' => '', 'text' => 'Gjentakende varsler som kan tyde på underliggende systemfeil.'],
                        ],
                    ],
                    [
                        'title' => 'Sammenheng med resten av Drift',
                        'items' => [
                            ['title' => '', 'text' => 'Varsler peker ofte videre til andre Drift-sider.'],
                            ['title' => '', 'text' => 'Systemstatus brukes til rask helsesjekk.'],
                            ['title' => '', 'text' => 'Importkjøringer og Synkroniseringslogg brukes for mer teknisk feilsøking.'],
                            ['title' => '', 'text' => 'Driftsrutiner beskriver hva som skal gjøres når et varsel krever fast prosedyre.'],
                            ['title' => '', 'text' => 'Avvik og forbedringer kan brukes dersom et varsel avdekker noe som bør følges opp systematisk.'],
                        ],
                    ],
                    [
                        'title' => 'Avgrensning',
                        'items' => [
                            ['title' => '', 'text' => 'Siden er ikke en komplett incident-logg.'],
                            ['title' => '', 'text' => 'Siden er ikke en erstatning for teknisk logging, monitorering eller ekstern overvåkning.'],
                            ['title' => '', 'text' => 'Et varsel betyr ikke nødvendigvis at systemet er nede.'],
                            ['title' => '', 'text' => 'Varsler må vurderes i sammenheng med teknisk status, logger og relevante driftssider.'],
                        ],
                    ],
                    [
                        'title' => 'Anbefalt bruk',
                        'items' => [
                            ['title' => '', 'text' => 'Kontroller nye varsler jevnlig.'],
                            ['title' => '', 'text' => 'Følg opp varsler som påvirker AI-kost, prisgrunnlag, import, integrasjoner eller kundedata.'],
                            ['title' => '', 'text' => 'Lukk eller marker varsler først når årsaken er vurdert.'],
                            ['title' => '', 'text' => 'Opprett avvik eller forbedringspunkt dersom samme type varsel gjentar seg eller krever varig tiltak.'],
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
        DB::table('admin_page_helps')->where('page_key', 'admin.notifications')->delete();
    }
};
