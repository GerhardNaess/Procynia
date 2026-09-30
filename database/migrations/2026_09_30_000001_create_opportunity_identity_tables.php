<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three tables for one sentence: this procurement is the one those register records describe.
 *
 * Procynia can already say where a record came from — notice_sources holds (source, external_id)
 * for every notice the Doffin import produced. What it cannot say is that two such records are the
 * same procurement, because nothing in the schema is the procurement. notices is that in name only:
 * its notice_id is a Doffin id, its raw XML is Doffin's, and the whole import pipeline treats a row
 * there as one Doffin notice. Putting TED into it would either overload notice_id with a value
 * Doffin never issued, or create a second notices row for a procurement that already has one —
 * which is precisely the duplicate this phase exists to remove.
 *
 * So the identity lives beside those tables rather than inside them, in the three levels the
 * registers themselves publish:
 *
 *   opportunities              one procurement        procedure_identifier   (eForms BT-04)
 *   opportunity_notices        one document in it     notice_identifier      (eForms BT-701)
 *   opportunity_source_records one register's copy    (source, external_id)
 *
 * Doffin and TED publish the identical UUIDs for the identical procurement, so these are exact
 * values from the sources, not anything Procynia computes. Nothing is ever matched on a title, a
 * buyer, a CPV code or a deadline: those are equal for procurements that are genuinely different
 * and different for records of one procurement, which Phase 5A established against live data.
 *
 * WHY A SOURCE RECORD POINTS AT BOTH LEVELS.
 *
 * opportunity_id is required and opportunity_notice_id is not. A register that tells us which
 * procurement a record belongs to has said the thing this whole layer is for; which document it is
 * is a second, finer answer, and a source that has not given it must still be recordable. A null
 * there means Procynia does not know which notice this is — never that the record has none.
 *
 * WHAT IS DELIBERATELY NOT HERE.
 *
 * No title, buyer, deadline or status. Those already live on notices, saved_notices and
 * watch_profile_inbox_records, and a copy here would be a fourth version of them, free to drift and
 * with no rule about which one wins. This layer answers who, and nothing else; everything about
 * what the procurement says stays where it already is, reachable by (source, external_id).
 *
 * No foreign key to notices either. A TED record has no notices row and never will, so a link that
 * only half the rows could use would be a link this table cannot rely on. The join is the same pair
 * notice_sources uses, and it is made by whoever needs it.
 *
 * This file depends on nothing but the schema — no config, no container, no services — the rule
 * every migration in this chain follows, and it writes no data at all: there is no identity to be
 * had for the rows that already exist, and inventing one would be the single most damaging thing a
 * migration in this phase could do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunities', function (Blueprint $table): void {
            $table->id();
            // The eForms procedure UUID, exactly as the register published it, lowercased by
            // OpportunityNoticeIdentity before it ever reaches here. Unique because it is the
            // definition of the row: one procurement, one opportunity.
            $table->string('procedure_identifier', 255)->unique();
            // Our relationship with the procurement, not the procurement's own dates — those
            // belong to the notices that announce it.
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('opportunity_notices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();
            // The eForms notice UUID. Unique across the table, not merely within an opportunity:
            // a notice belongs to exactly one procurement, and two opportunities claiming the same
            // document would mean one of them is wrong.
            $table->string('notice_identifier', 255)->unique();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            // "Which documents does this procurement have", the direction every consumer reads.
            // Spelled out because Postgres does not index a foreign key for you.
            $table->index('opportunity_id');
        });

        Schema::create('opportunity_source_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();
            // Null while the source has told us the procurement but not the document.
            $table->foreignId('opportunity_notice_id')->nullable()->constrained('opportunity_notices')->nullOnDelete();
            $table->string('source', 50);
            $table->string('external_id');
            $table->string('source_url', 1000)->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            // One register record, one row — the same rule notice_sources follows, for the same
            // reason: two sources using the same external id is not a collision, it is two records
            // that happen to share a string.
            $table->unique(['source', 'external_id']);
            // "Which registers hold this procurement", which is the question 5E will ask on screen.
            $table->index(['opportunity_id', 'source']);
            $table->index('opportunity_notice_id');
        });
    }

    public function down(): void
    {
        // Children first: the foreign keys point upwards.
        Schema::dropIfExists('opportunity_source_records');
        Schema::dropIfExists('opportunity_notices');
        Schema::dropIfExists('opportunities');
    }
};
