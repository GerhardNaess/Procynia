<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Risiko — behandlingsvalg: the overall direction the business has chosen for handling a risk
 * (unngå, redusere, dele/overføre, akseptere).
 *
 * One current value, changed with risk.edit like the rest of the risk — no history. NULL means
 * «Ikke besluttet ennå».
 *
 * It is a direction, not a decision that does anything: choosing «reduce» creates no tiltak, and
 * choosing «accept» creates no risk_acceptances row and changes neither status nor assessment.
 * Formal acceptance of residual risk stays the explicit risk.accept flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('risks', function (Blueprint $table): void {
            $table->string('treatment_strategy', 16)->nullable()->after('status');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE risks ADD CONSTRAINT risks_treatment_strategy_check CHECK (treatment_strategy IS NULL OR treatment_strategy IN ('avoid', 'reduce', 'share', 'accept'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE risks DROP CONSTRAINT IF EXISTS risks_treatment_strategy_check');
        }

        Schema::table('risks', function (Blueprint $table): void {
            $table->dropColumn('treatment_strategy');
        });
    }
};
