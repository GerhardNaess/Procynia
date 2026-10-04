<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Risiko — explicit acceptance of residual risk: a decision-maker records that one concrete
 * residual risk, as judged in one concrete assessment, has been considered and accepted.
 *
 * NOT A STATUS.
 *
 * Acceptance never touches risks.status, the assessment or its score. The risk can still be
 * active, under treatment and followed up; the acceptance only says that someone with authority
 * stood behind the residual risk as it was assessed.
 *
 * HISTORY, NOT FIELDS.
 *
 * A row is never edited. A mistake is corrected by revoking it (revoked_at) and, if wanted,
 * registering a new one. Which acceptance applies is computed, never stored: the non-revoked
 * acceptance of the risk's latest assessment. A newer assessment therefore makes an older
 * acceptance historical without anything being written. «Utløpt» is computed too: valid_until is
 * a calendar day, and the acceptance holds through that whole day.
 *
 * One active acceptance per assessment is enforced by a partial unique index, so two people
 * accepting at the same moment cannot both succeed.
 *
 * ACCESS IS THE RISK'S. Reached only through its risk, so only by someone RiskAccessService lets
 * see that risk. Deleting the risk (risk.delete) takes its acceptances along.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('risk_id')->constrained('risks')->cascadeOnDelete();
            $table->foreignId('assessment_id')->constrained('risk_assessments')->cascadeOnDelete();
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rationale');
            $table->timestamp('accepted_at');
            $table->date('valid_until')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['risk_id', 'accepted_at']);
            $table->index('assessment_id');
            $table->index('customer_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX risk_acceptances_one_active_per_assessment ON risk_acceptances (assessment_id) WHERE revoked_at IS NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_acceptances');
    }
};
