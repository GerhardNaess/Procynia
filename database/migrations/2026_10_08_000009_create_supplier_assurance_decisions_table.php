<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kontrollbeslutninger — what the business decided about a supplier (docs/supplier-assurance-v2-plan.md
 * §9.3, §20.2): Godkjent, Godkjent med oppfølging or Ikke godkjent for nye kjøp, always with a
 * begrunnelse (and, for Godkjent med oppfølging, what is followed up), who decided, the decision date
 * (decided_on, never in the future) and when it was registered (recorded_at).
 *
 * Never «Krever beslutning»: that is computed from the control state, not decided. Nothing but
 * SupplierAssuranceDecisionService writes here, and only for a person with supplier.assure.
 *
 * state_snapshot is the control state the person saw when deciding — the state, how many
 * requirements applied, how many per visningsstatus, and the mandatory and important requirements
 * that were not documented. It is only ever shown as history, never read as the state now. The
 * supplier's name and criticality are kept as they were.
 *
 * Append-only: a new decision is a new row. SupplierAssuranceDecision throws on update and delete,
 * and a trigger refuses UPDATE (except the foreign key nulling decided_by_user_id when that user is
 * deleted) and DELETE while the customer exists. The decision in force is the latest decided_on,
 * then the highest id.
 *
 * Supplier by (id, customer_id), NO ACTION: never across customers, and a supplier with a decision
 * cannot be deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_assurance_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('supplier_id');
            $table->string('decision');
            $table->text('rationale');
            $table->text('follow_up_note')->nullable();
            $table->date('decided_on');
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->jsonb('state_snapshot');
            $table->string('supplier_name');
            $table->string('criticality')->nullable();

            $table->foreign(['supplier_id', 'customer_id'])->references(['id', 'customer_id'])->on('suppliers');
            $table->index(['supplier_id', 'decided_on', 'id']);
            $table->index('customer_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE supplier_assurance_decisions ADD CONSTRAINT supplier_assurance_decisions_values CHECK ('
            ."decision IN ('approved', 'approved_with_follow_up', 'not_approved')"
            ." AND (decision = 'approved_with_follow_up') = (follow_up_note IS NOT NULL)"
            .' AND (follow_up_note IS NULL OR length(btrim(follow_up_note)) > 0)'
            ." AND (criticality IS NULL OR criticality IN ('standard', 'important', 'critical'))"
            ." AND jsonb_typeof(state_snapshot) = 'object'"
            .' AND length(btrim(rationale)) > 0 AND length(btrim(supplier_name)) > 0)');

        $changed = implode("\n                    OR ", array_map(
            fn (string $column): string => "NEW.{$column} IS DISTINCT FROM OLD.{$column}",
            ['id', 'customer_id', 'supplier_id', 'decision', 'rationale', 'follow_up_note', 'decided_on', 'recorded_at',
                'state_snapshot', 'supplier_name', 'criticality'],
        ));

        // OR REPLACE: migrate:fresh drops tables but leaves functions behind.
        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION supplier_assurance_decisions_immutable() RETURNS trigger AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id) THEN
                        RAISE EXCEPTION 'supplier_assurance_decisions is history and cannot be deleted';
                    END IF;

                    RETURN OLD;
                END IF;

                IF {$changed}
                    OR (NEW.decided_by_user_id IS NOT NULL AND NEW.decided_by_user_id IS DISTINCT FROM OLD.decided_by_user_id)
                THEN
                    RAISE EXCEPTION 'supplier_assurance_decisions is history and cannot be changed';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER supplier_assurance_decisions_immutable
                BEFORE UPDATE OR DELETE ON supplier_assurance_decisions
                FOR EACH ROW EXECUTE FUNCTION supplier_assurance_decisions_immutable();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_assurance_decisions');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS supplier_assurance_decisions_immutable()');
        }
    }
};
