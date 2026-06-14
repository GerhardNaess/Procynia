<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('admin_page_helps')->updateOrInsert(
            ['page_key' => 'admin.operational_runbooks'],
            [
                'title'       => 'Driftsrutiner',
                'description' => 'Operativ dokumentasjon for faste rutiner som holder Procynia stabil, sporbar og forutsigbar.',
                'intro'       => 'Driftsrutiner samler faste prosedyrer for drift, forvaltning og operativ oppfølging i Procynia. Siden brukes til å sikre at viktige rutiner ikke bare ligger i hodet på én person, men er dokumentert og tilgjengelige for andre administratorer ved behov.',
                'sections'    => json_encode([
                    [
                        'title' => 'Hva brukes denne siden til?',
                        'items' => [
                            ['title' => '', 'text' => 'Siden brukes til å dokumentere og forvalte faste rutiner for drift, forvaltning og operativ oppfølging av Procynia.'],
                            ['title' => '', 'text' => 'Rutiner kan gjelde daglig kontroll, periodiske oppgaver, sikkerhet, backup, deploy, integrasjoner, AI-drift eller andre operative prosesser.'],
                            ['title' => '', 'text' => 'Målet er at viktige driftsrutiner ikke bare ligger i hodet på én person.'],
                        ],
                    ],
                    [
                        'title' => 'Hva bør admin kontrollere',
                        'items' => [
                            ['title' => '', 'text' => 'At rutinen har tydelig eier eller ansvar.'],
                            ['title' => '', 'text' => 'At innholdet er oppdatert.'],
                            ['title' => '', 'text' => 'At status og kategori er riktig.'],
                            ['title' => '', 'text' => 'At rutinen er forståelig for andre enn personen som skrev den.'],
                            ['title' => '', 'text' => 'At kritiske rutiner er godkjent og revidert ved behov.'],
                        ],
                    ],
                    [
                        'title' => 'Sammenheng med resten av Drift',
                        'items' => [
                            ['title' => '', 'text' => 'Driftsrutiner er dokumentasjonsgrunnlaget for praktisk drift.'],
                            ['title' => '', 'text' => 'Avvik og forbedringer kan vise behov for nye eller endrede rutiner.'],
                            ['title' => '', 'text' => 'Systemstatus, Backup og recovery, Importkjøringer og Synkroniseringslogg kan peke på rutiner som bør følges ved feil eller avvik.'],
                        ],
                    ],
                    [
                        'title' => 'Avgrensning',
                        'items' => [
                            ['title' => '', 'text' => 'Siden er ikke en hendelseslogg.'],
                            ['title' => '', 'text' => 'Siden er ikke en teknisk overvåkningsflate.'],
                            ['title' => '', 'text' => 'Siden skal ikke brukes til midlertidige notater som ikke skal vedlikeholdes.'],
                            ['title' => '', 'text' => 'Rutiner som er utdatert, skal oppdateres eller arkiveres.'],
                        ],
                    ],
                    [
                        'title' => 'Anbefalt bruk',
                        'items' => [
                            ['title' => '', 'text' => 'Bruk Driftsrutiner som første sted for faste prosedyrer.'],
                            ['title' => '', 'text' => 'Oppdater rutiner etter hendelser, avvik, deployer eller endringer i infrastruktur.'],
                            ['title' => '', 'text' => 'Sørg for at kritiske rutiner kan følges av en annen administrator ved fravær.'],
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
        DB::table('admin_page_helps')->where('page_key', 'admin.operational_runbooks')->delete();
    }
};
