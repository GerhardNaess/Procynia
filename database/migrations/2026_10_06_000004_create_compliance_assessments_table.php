<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Etterlevelsesvurderinger: every judgement of whether the virksomhet meets a requirement, as it
 * was made, with its begrunnelse.
 *
 *   compliant | partially_compliant | non_compliant | not_applicable
 *
 * There is no stored «not assessed»: a requirement without a row here is Ikke vurdert. The
 * requirement's current compliance status and next review date are computed from these rows
 * (ComplianceStatusResolver, ComplianceReviewSchedule) and never stored on the requirement.
 *
 * assessed_at is set by the system when the assessment is registered — never a form field — so
 * history cannot be backdated.
 *
 * requirement_reference / requirement_title / requirement_text / source_name / source_version are
 * the requirement as it read when it was assessed, so the history stays true after the requirement
 * or its source is edited, and a change since the latest assessment can be shown.
 *
 * Append-only, the same arrangement as compliance_requirement_status_changes. ComplianceAssessment
 * throws on update and delete, and on PostgreSQL a trigger holds the line below the model:
 *
 *  - UPDATE is refused, except the one change a foreign key makes on its own: nulling
 *    assessed_by_user_id when that user is deleted. The assessment stays.
 *  - DELETE is refused while the customer exists, so a requirement that has been assessed can
 *    never be deleted either. Only the customer going takes the history with it.
 *
 * The requirement is referenced by (requirement_id, customer_id), so an assessment can never
 * describe another customer's requirement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('requirement_id');
            $table->string('result');
            $table->text('rationale');
            $table->foreignId('assessed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assessed_at');
            $table->string('requirement_reference', 100)->nullable();
            $table->string('requirement_title');
            $table->text('requirement_text');
            $table->string('source_name');
            $table->string('source_version')->nullable();

            $table->foreign(['requirement_id', 'customer_id'])->references(['id', 'customer_id'])->on('compliance_requirements')->cascadeOnDelete();
            $table->index(['requirement_id', 'assessed_at', 'id']);
            $table->index('customer_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE compliance_assessments ADD CONSTRAINT compliance_assessments_result_check CHECK (result IN ('compliant', 'partially_compliant', 'non_compliant', 'not_applicable'))");
            DB::statement('ALTER TABLE compliance_assessments ADD CONSTRAINT compliance_assessments_rationale_check CHECK (length(btrim(rationale)) > 0)');

            // OR REPLACE: migrate:fresh drops tables but leaves functions behind.
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION compliance_assessments_immutable() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id) THEN
                            RAISE EXCEPTION 'compliance_assessments is history and cannot be deleted';
                        END IF;

                        RETURN OLD;
                    END IF;

                    IF NEW.id IS DISTINCT FROM OLD.id
                        OR NEW.customer_id IS DISTINCT FROM OLD.customer_id
                        OR NEW.requirement_id IS DISTINCT FROM OLD.requirement_id
                        OR NEW.result IS DISTINCT FROM OLD.result
                        OR NEW.rationale IS DISTINCT FROM OLD.rationale
                        OR NEW.assessed_at IS DISTINCT FROM OLD.assessed_at
                        OR NEW.requirement_reference IS DISTINCT FROM OLD.requirement_reference
                        OR NEW.requirement_title IS DISTINCT FROM OLD.requirement_title
                        OR NEW.requirement_text IS DISTINCT FROM OLD.requirement_text
                        OR NEW.source_name IS DISTINCT FROM OLD.source_name
                        OR NEW.source_version IS DISTINCT FROM OLD.source_version
                        OR (NEW.assessed_by_user_id IS NOT NULL AND NEW.assessed_by_user_id IS DISTINCT FROM OLD.assessed_by_user_id)
                    THEN
                        RAISE EXCEPTION 'compliance_assessments is history and cannot be changed';
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER compliance_assessments_immutable
                    BEFORE UPDATE OR DELETE ON compliance_assessments
                    FOR EACH ROW EXECUTE FUNCTION compliance_assessments_immutable();
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_assessments');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS compliance_assessments_immutable()');
        }
    }
};
