<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ledelsens gjennomgåelse (docs/management-review-v1-plan.md §7).
 *
 * A review has two stored statuses, draft and finalized. «Klar for ferdigstilling» is computed and
 * never stored. While a review is a draft everything on it may change; finalizing freezes it:
 *
 *  - management_reviews: the content columns are frozen. Only the owner (who plans the next review)
 *    and next_review_due_on remain open, plus the FK nulling a deleted user causes.
 *  - participants, sections and fagområde links: no insert, update or delete under a finalized
 *    review (management_review_children_locked).
 *  - decisions: what was decided is frozen. The follow-up of a decision followed up in this module
 *    — owner, due date, status, completion — stays open, because a tiltak is followed up after the
 *    meeting. Each such change is written to management_review_events. A decision handed to Avvik
 *    og forbedringer is frozen from the moment it is handed off, also in a draft.
 *  - snapshot sections, amendments and events: append-only history.
 *
 * Every frozen table refuses DELETE while the customer exists, so a customer cascade still works.
 * Functions use CREATE OR REPLACE: migrate:fresh drops tables but leaves functions behind.
 *
 * Tenant safety: every child references its review by (management_review_id, customer_id), so no
 * row can point at another customer's review. Decisions reference an improvement case by
 * (improvement_case_id, customer_id), NO ACTION: a case that came from a review cannot be deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('management_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('purpose')->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->date('meeting_date')->nullable();
            $table->boolean('all_business_areas')->default(true);
            $table->jsonb('frameworks')->default(DB::raw("'[]'::jsonb"));
            $table->jsonb('framework_versions')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('conclusion')->nullable();
            $table->date('next_review_due_on')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('finalized_by_name')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['id', 'customer_id']);
            $table->index(['customer_id', 'status']);
            $table->index(['customer_id', 'period_end']);
            $table->index(['customer_id', 'owner_user_id']);
        });

        Schema::create('management_review_business_areas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('management_review_id');
            $table->foreignId('business_area_id')->constrained('business_areas')->restrictOnDelete();

            $table->foreign(['management_review_id', 'customer_id'])->references(['id', 'customer_id'])->on('management_reviews')->cascadeOnDelete();
            $table->unique(['management_review_id', 'business_area_id']);
        });

        Schema::create('management_review_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('management_review_id');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('role_label')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->foreign(['management_review_id', 'customer_id'])->references(['id', 'customer_id'])->on('management_reviews')->cascadeOnDelete();
            $table->index(['management_review_id', 'position']);
        });

        Schema::create('management_review_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('management_review_id');
            $table->string('section_key');
            $table->string('judgement')->nullable();
            $table->text('comment')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['management_review_id', 'customer_id'])->references(['id', 'customer_id'])->on('management_reviews')->cascadeOnDelete();
            $table->unique(['management_review_id', 'section_key']);
        });

        Schema::create('management_review_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('management_review_id');
            $table->string('section_key')->nullable();
            $table->string('kind');
            $table->text('text');
            // For a tiltak: who is responsible and by when. Followed up here (follow_up = own) these
            // are the live values; handed to Avvik og forbedringer they are what was decided, and the
            // case carries its own owner and due date from then on.
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->string('follow_up')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('completion_note')->nullable();
            $table->unsignedBigInteger('improvement_case_id')->nullable();
            $table->string('improvement_origin')->nullable();
            $table->uuid('handoff_key')->nullable();
            $table->timestamp('handed_off_at')->nullable();
            $table->foreignId('handed_off_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['id', 'customer_id']);
            $table->foreign(['management_review_id', 'customer_id'])->references(['id', 'customer_id'])->on('management_reviews')->cascadeOnDelete();
            $table->foreign(['improvement_case_id', 'customer_id'])->references(['id', 'customer_id'])->on('improvement_cases');
            $table->unique('improvement_case_id');
            $table->unique('handoff_key');
            $table->index(['management_review_id', 'position']);
            $table->index(['customer_id', 'owner_user_id', 'status']);
        });

        Schema::create('management_review_snapshot_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('management_review_id');
            $table->string('section_key');
            $table->string('state');
            $table->string('source_module')->nullable();
            $table->unsignedSmallInteger('schema_version');
            $table->jsonb('coverage');
            $table->jsonb('payload');
            $table->timestamp('captured_at');

            $table->foreign(['management_review_id', 'customer_id'])->references(['id', 'customer_id'])->on('management_reviews')->cascadeOnDelete();
            $table->unique(['management_review_id', 'section_key']);
        });

        Schema::create('management_review_amendments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('management_review_id');
            $table->text('text');
            $table->text('reason');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name');
            $table->timestamp('created_at');

            $table->foreign(['management_review_id', 'customer_id'])->references(['id', 'customer_id'])->on('management_reviews')->cascadeOnDelete();
            $table->index(['management_review_id', 'created_at']);
        });

        Schema::create('management_review_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // Nulled, not deleted, when a draft is deleted: the event of its deletion outlives it.
            $table->unsignedBigInteger('management_review_id')->nullable();
            $table->unsignedBigInteger('decision_id')->nullable();
            $table->string('event');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestamp('occurred_at');

            $table->index(['management_review_id', 'occurred_at']);
            $table->index('customer_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Composite FKs that null only the review/decision column, never customer_id (PostgreSQL 15+).
        DB::statement('ALTER TABLE management_review_events ADD CONSTRAINT management_review_events_review_fk'
            .' FOREIGN KEY (management_review_id, customer_id) REFERENCES management_reviews (id, customer_id) ON DELETE SET NULL (management_review_id)');
        DB::statement('ALTER TABLE management_review_events ADD CONSTRAINT management_review_events_decision_fk'
            .' FOREIGN KEY (decision_id, customer_id) REFERENCES management_review_decisions (id, customer_id) ON DELETE SET NULL (decision_id)');

        DB::statement('ALTER TABLE management_reviews ADD CONSTRAINT management_reviews_values CHECK ('
            ."status IN ('draft', 'finalized')"
            .' AND period_end >= period_start'
            ." AND jsonb_typeof(frameworks) = 'array'"
            .' AND length(btrim(title)) > 0'
            ." AND (status = 'finalized') = (finalized_at IS NOT NULL)"
            ." AND (status = 'draft' OR finalized_by_name IS NOT NULL))");

        DB::statement('ALTER TABLE management_review_sections ADD CONSTRAINT management_review_sections_values CHECK ('
            ."judgement IS NULL OR judgement IN ('satisfactory', 'needs_improvement', 'not_satisfactory'))");

        DB::statement('ALTER TABLE management_review_decisions ADD CONSTRAINT management_review_decisions_values CHECK ('
            ."kind IN ('decision', 'action')"
            .' AND length(btrim(text)) > 0'
            ." AND (follow_up IS NULL OR follow_up IN ('own', 'improvement_case'))"
            ." AND (status IS NULL OR status IN ('open', 'completed', 'cancelled'))"
            ." AND (improvement_origin IS NULL OR improvement_origin IN ('handoff', 'linked'))"
            // A plain decision has no follow-up at all; a tiltak always has one.
            ." AND (kind = 'decision') = (follow_up IS NULL)"
            ." AND (kind = 'action' OR (status IS NULL AND improvement_case_id IS NULL AND due_date IS NULL AND owner_user_id IS NULL))"
            // A tiltak followed up here carries its own status and frist; one in Avvik carries a case.
            ." AND (follow_up IS DISTINCT FROM 'own' OR (status IS NOT NULL AND due_date IS NOT NULL AND improvement_case_id IS NULL))"
            ." AND (follow_up IS DISTINCT FROM 'improvement_case' OR (status IS NULL AND improvement_case_id IS NOT NULL AND improvement_origin IS NOT NULL))"
            ." AND (status = 'completed') = (completed_at IS NOT NULL))");

        DB::statement('ALTER TABLE management_review_snapshot_sections ADD CONSTRAINT management_review_snapshot_sections_values CHECK ('
            ."state IN ('captured', 'module_unavailable', 'not_captured')"
            ." AND jsonb_typeof(payload) = 'object' AND jsonb_typeof(coverage) = 'object')");

        DB::statement('ALTER TABLE management_review_amendments ADD CONSTRAINT management_review_amendments_values CHECK ('
            .'length(btrim(text)) > 0 AND length(btrim(reason)) > 0)');

        $reviewFrozen = implode("\n                    OR ", array_map(
            fn (string $column): string => "NEW.{$column} IS DISTINCT FROM OLD.{$column}",
            ['id', 'customer_id', 'title', 'purpose', 'period_start', 'period_end', 'meeting_date', 'all_business_areas',
                'frameworks', 'framework_versions', 'conclusion', 'status', 'finalized_at', 'finalized_by_name', 'created_at'],
        ));

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION management_reviews_finalized_locked() RETURNS trigger AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status = 'finalized' AND EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id) THEN
                        RAISE EXCEPTION 'a finalized management review cannot be deleted';
                    END IF;

                    RETURN OLD;
                END IF;

                IF OLD.status = 'finalized' AND (
                    {$reviewFrozen}
                    OR (NEW.finalized_by_user_id IS NOT NULL AND NEW.finalized_by_user_id IS DISTINCT FROM OLD.finalized_by_user_id)
                    OR (NEW.created_by IS NOT NULL AND NEW.created_by IS DISTINCT FROM OLD.created_by)
                ) THEN
                    RAISE EXCEPTION 'a finalized management review cannot be changed';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER management_reviews_finalized_locked
                BEFORE UPDATE OR DELETE ON management_reviews
                FOR EACH ROW EXECUTE FUNCTION management_reviews_finalized_locked();
            SQL);

        // One function for every child that is wholly frozen under a finalized review. TG_ARGV names
        // the user columns a deleted user may null; nothing else may change.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION management_review_children_locked() RETURNS trigger AS $$
            DECLARE
                parent_status text;
                column_name text;
                row_customer bigint;
                row_review bigint;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    row_customer := NEW.customer_id;
                    row_review := NEW.management_review_id;
                ELSE
                    row_customer := OLD.customer_id;
                    row_review := OLD.management_review_id;
                END IF;

                SELECT status INTO parent_status FROM management_reviews WHERE id = row_review;

                IF parent_status IS DISTINCT FROM 'finalized' THEN
                    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                    RETURN NEW;
                END IF;

                IF TG_OP = 'DELETE' THEN
                    IF EXISTS (SELECT 1 FROM customers WHERE id = row_customer) THEN
                        RAISE EXCEPTION '% belongs to a finalized management review and cannot be deleted', TG_TABLE_NAME;
                    END IF;

                    RETURN OLD;
                END IF;

                IF TG_OP = 'INSERT' THEN
                    RAISE EXCEPTION '% cannot be added to a finalized management review', TG_TABLE_NAME;
                END IF;

                IF (to_jsonb(NEW) - TG_ARGV) IS DISTINCT FROM (to_jsonb(OLD) - TG_ARGV) THEN
                    RAISE EXCEPTION '% belongs to a finalized management review and cannot be changed', TG_TABLE_NAME;
                END IF;

                FOREACH column_name IN ARRAY TG_ARGV LOOP
                    IF (to_jsonb(NEW) -> column_name) <> 'null'::jsonb
                        AND (to_jsonb(NEW) -> column_name) IS DISTINCT FROM (to_jsonb(OLD) -> column_name) THEN
                        RAISE EXCEPTION '% belongs to a finalized management review and cannot be changed', TG_TABLE_NAME;
                    END IF;
                END LOOP;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER management_review_business_areas_locked
                BEFORE INSERT OR UPDATE OR DELETE ON management_review_business_areas
                FOR EACH ROW EXECUTE FUNCTION management_review_children_locked();

            CREATE TRIGGER management_review_participants_locked
                BEFORE INSERT OR UPDATE OR DELETE ON management_review_participants
                FOR EACH ROW EXECUTE FUNCTION management_review_children_locked('user_id');

            CREATE TRIGGER management_review_sections_locked
                BEFORE INSERT OR UPDATE OR DELETE ON management_review_sections
                FOR EACH ROW EXECUTE FUNCTION management_review_children_locked('updated_by');
            SQL);

        $decisionFrozen = implode("\n                    OR ", array_map(
            fn (string $column): string => "NEW.{$column} IS DISTINCT FROM OLD.{$column}",
            ['id', 'customer_id', 'management_review_id', 'section_key', 'kind', 'text', 'follow_up', 'improvement_case_id',
                'improvement_origin', 'handoff_key', 'handed_off_at', 'position', 'created_at'],
        ));

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION management_review_decisions_locked() RETURNS trigger AS \$\$
            DECLARE
                parent_status text;
                customer_exists boolean;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    SELECT status INTO parent_status FROM management_reviews WHERE id = NEW.management_review_id;

                    IF parent_status = 'finalized' THEN
                        RAISE EXCEPTION 'a decision cannot be added to a finalized management review';
                    END IF;

                    RETURN NEW;
                END IF;

                SELECT status INTO parent_status FROM management_reviews WHERE id = OLD.management_review_id;

                IF TG_OP = 'DELETE' THEN
                    customer_exists := EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id);

                    IF customer_exists AND (parent_status = 'finalized' OR OLD.improvement_case_id IS NOT NULL) THEN
                        RAISE EXCEPTION 'a decision that is finalized or handed off cannot be deleted';
                    END IF;

                    RETURN OLD;
                END IF;

                IF (parent_status = 'finalized' OR OLD.improvement_case_id IS NOT NULL) AND (
                    {$decisionFrozen}
                    OR (NEW.created_by IS NOT NULL AND NEW.created_by IS DISTINCT FROM OLD.created_by)
                    OR (NEW.handed_off_by_user_id IS NOT NULL AND NEW.handed_off_by_user_id IS DISTINCT FROM OLD.handed_off_by_user_id)
                    OR (OLD.improvement_case_id IS NOT NULL AND (
                        (NEW.owner_user_id IS NOT NULL AND NEW.owner_user_id IS DISTINCT FROM OLD.owner_user_id)
                        OR NEW.due_date IS DISTINCT FROM OLD.due_date
                        OR NEW.status IS DISTINCT FROM OLD.status))
                ) THEN
                    RAISE EXCEPTION 'what was decided cannot be changed once the review is finalized or the decision handed off';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER management_review_decisions_locked
                BEFORE INSERT OR UPDATE OR DELETE ON management_review_decisions
                FOR EACH ROW EXECUTE FUNCTION management_review_decisions_locked();
            SQL);

        // Append-only history. TG_ARGV names the columns a deletion elsewhere may null.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION management_review_history_immutable() RETURNS trigger AS $$
            DECLARE
                column_name text;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF EXISTS (SELECT 1 FROM customers WHERE id = OLD.customer_id) THEN
                        RAISE EXCEPTION '% is history and cannot be deleted', TG_TABLE_NAME;
                    END IF;

                    RETURN OLD;
                END IF;

                IF (to_jsonb(NEW) - TG_ARGV) IS DISTINCT FROM (to_jsonb(OLD) - TG_ARGV) THEN
                    RAISE EXCEPTION '% is history and cannot be changed', TG_TABLE_NAME;
                END IF;

                FOREACH column_name IN ARRAY TG_ARGV LOOP
                    IF (to_jsonb(NEW) -> column_name) <> 'null'::jsonb
                        AND (to_jsonb(NEW) -> column_name) IS DISTINCT FROM (to_jsonb(OLD) -> column_name) THEN
                        RAISE EXCEPTION '% is history and cannot be changed', TG_TABLE_NAME;
                    END IF;
                END LOOP;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER management_review_snapshot_sections_immutable
                BEFORE UPDATE OR DELETE ON management_review_snapshot_sections
                FOR EACH ROW EXECUTE FUNCTION management_review_history_immutable();

            CREATE TRIGGER management_review_amendments_immutable
                BEFORE UPDATE OR DELETE ON management_review_amendments
                FOR EACH ROW EXECUTE FUNCTION management_review_history_immutable('created_by_user_id');

            CREATE TRIGGER management_review_events_immutable
                BEFORE UPDATE OR DELETE ON management_review_events
                FOR EACH ROW EXECUTE FUNCTION management_review_history_immutable('management_review_id', 'decision_id', 'actor_user_id');
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('management_review_events');
        Schema::dropIfExists('management_review_amendments');
        Schema::dropIfExists('management_review_snapshot_sections');
        Schema::dropIfExists('management_review_decisions');
        Schema::dropIfExists('management_review_sections');
        Schema::dropIfExists('management_review_participants');
        Schema::dropIfExists('management_review_business_areas');
        Schema::dropIfExists('management_reviews');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS management_review_history_immutable()');
            DB::unprepared('DROP FUNCTION IF EXISTS management_review_decisions_locked()');
            DB::unprepared('DROP FUNCTION IF EXISTS management_review_children_locked()');
            DB::unprepared('DROP FUNCTION IF EXISTS management_reviews_finalized_locked()');
        }
    }
};
