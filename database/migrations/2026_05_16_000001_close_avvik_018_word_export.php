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
            ->where('code', 'AVVIK-018')
            ->update([
                'status' => OperationalDeviation::STATUS_CLOSED,
                'started_at' => '2026-05-16 22:00:00',
                'ready_for_verification_at' => '2026-05-16 23:00:00',
                'verified_at' => '2026-05-16 23:34:00',
                'closed_at' => '2026-05-16 23:34:00',
                'verification_notes' => "Word-eksport er implementert og merget til main (commit 5312050, 2026-05-16 23:34).\n\nEksportfunksjonen lar brukere eksportere krav og svarutkast fra AI-arbeidsrommet direkte til .docx-format. Krav, svarutkast og kildegrunnlag inkluderes i eksporten. Brukeren aktiverer eksporten via AI-grensesnittet på SavedNotice.",
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('operational_deviations')) {
            return;
        }

        DB::table('operational_deviations')
            ->where('code', 'AVVIK-018')
            ->update([
                'status' => OperationalDeviation::STATUS_NEW,
                'started_at' => null,
                'ready_for_verification_at' => null,
                'verified_at' => null,
                'closed_at' => null,
                'verification_notes' => null,
                'updated_at' => now(),
            ]);
    }
};
