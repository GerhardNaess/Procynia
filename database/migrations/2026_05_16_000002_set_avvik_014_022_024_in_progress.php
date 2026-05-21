<?php

use App\Models\OperationalDeviation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('operational_deviations')) {
            return;
        }

        DB::table('operational_deviations')
            ->whereIn('code', ['AVVIK-014', 'AVVIK-022', 'AVVIK-024'])
            ->update([
                'status' => OperationalDeviation::STATUS_IN_PROGRESS,
                'updated_at' => now(),
            ]);

        DB::table('operational_deviations')
            ->where('code', 'AVVIK-014')
            ->whereNull('started_at')
            ->update([
                'started_at' => '2026-05-18 22:52:00',
                'verification_notes' => "Aktivt arbeid startet 18. mai 2026 på grenen fix-pdf-extraction.\n\nImplementert så langt:\n- Tabellelementer og bildelementer bevares under PDF-parsing (461f774, 2026-05-19)\n- Tabellrader holdes samlet på tvers av sideskift (0a74043, 2026-05-20)\n- Tabeller holdes samlet på tvers av sideskift (6992c4b, 2026-05-20)\n- Innholdsfortegnelsesstøy i PDF-er undertrykkes (7cc0592, 2026-05-20)\n- Tabell- og bildegrunnlag inkluderes i kunnskapsmatching (63922b1, 2026-05-20)\n- PDF-vektorfigurer bevares som bildeblokker (a4c977 og d24111e, 2026-05-20)\n- Forhåndsvisning av figurgap-chunks rendereres (50b7b9f og 553fb60, 2026-05-20)\n- Tabellkontinuasjonshåndtering for mellomkolonner og vertikalt overlapp (48da065, 55550ef, 2026-05-18)\n\nGjenstår: verifisering mot akseptansekriterie og lukking av grenen.",
                'updated_at' => now(),
            ]);

        DB::table('operational_deviations')
            ->where('code', 'AVVIK-022')
            ->whereNull('started_at')
            ->update([
                'started_at' => '2026-05-16 21:20:00',
                'verification_notes' => "Arbeid startet 16. mai 2026. Fem delte React-komponenter er lagt til i resources/js/Components/App/Shared/:\n\n- StatusBadge — generisk statusindikatorbadge for gjenbruk på tvers av sider (9a44051, 2026-05-16)\n- EmptyStateBox — standardisert tom-tilstand-visning (008822d, 2026-05-16)\n- AlertBox — gjenbrukbart varselkomponent (9c2d4ca, 2026-05-16)\n- DepartmentCheckboxGroup — felles avdelingsvelger for bruker- og profilsider (9a44051, 2026-05-16)\n- FormButtonRow — standardisert knapperekke for skjemabunntekst (14d15f2, 2026-05-16)\n\nGjenstår: videre konsolidering av gjenbruksmønstre for tabeller, kort og modaler. Akseptansekriterie ikke fullt ut oppfylt.",
                'updated_at' => now(),
            ]);

        DB::table('operational_deviations')
            ->where('code', 'AVVIK-024')
            ->whereNull('started_at')
            ->update([
                'started_at' => '2026-05-16 22:49:00',
                'verification_notes' => "Arbeid startet 16. mai 2026.\n\nAVVIK-024A (feature-tester, 2026-05-16): Fem deterministiske tester lagt til i tests/Feature/App/AiControllerTest.php. Ingen ekte OpenAI-kall — alle bruker bind*Service-infrastruktur med Http::fake().\n- T1: Generering blokkeres og ingenting persisteres ved rød knowledge_grounding\n- T2: Generering blokkeres og ingenting persisteres når judge sier kan ikke generere\n- T3: Retrieval-kilder persisteres etter vellykket generering og overlever payload-readback\n- T4: answer_draft_payload returnerer generation_state=generated og retrieval_sources for lagret utkast\n- T5: answer_draft_payload returnerer null generation_state for krav uten utkast\n\nAVVIK-024B (unit-tester, 2026-05-16): Fire unit-tester lagt til i tests/Unit/Services/RequirementAnswerDraftServiceTest.php — kjøres uten Docker.\n- Ugyldig JSON fra OpenAI kaster RuntimeException uten DB-skriv\n- Manglende answer_draft_text-felt kaster RuntimeException\n- Tom tekst kaster RuntimeException\n- Gyldig minimal respons parses og persisteres korrekt\n\nGjenstår: T6, T7 og faglige evalueringstester mot kjente krav og forventet innhold.",
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('operational_deviations')) {
            return;
        }

        DB::table('operational_deviations')
            ->whereIn('code', ['AVVIK-014', 'AVVIK-022', 'AVVIK-024'])
            ->update([
                'status' => OperationalDeviation::STATUS_NEW,
                'started_at' => null,
                'verification_notes' => null,
                'updated_at' => now(),
            ]);
    }
};
