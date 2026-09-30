<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One customer, one procurement, one case.
 *
 * A saved case is identified by (customer_id, source, external_id): the register record the bid
 * manager pressed save on. That is right about provenance and wrong about the work — Doffin's
 * 2026-113736 and TED's 599740-2026 are one procurement, and saving both would give one customer
 * two cases, two sets of requirements and two half-finished bids for one deadline.
 *
 * opportunity_id is the answer to "which procurement", and the partial unique index makes the
 * product rule a thing the database enforces rather than a thing the application remembers to do.
 *
 * WHY NULLABLE, AND WHY NOTHING IS BACKFILLED.
 *
 * The identifiers are published by the registers and are not in Procynia's own data. Doffin's live
 * search does not return them at all — they come from a detail request per notice — so the only
 * way to give an existing case an opportunity would be to call an external API from inside a
 * migration. That is forbidden here for good reasons, and the alternative, deriving identity from
 * titles or buyers, would silently merge two customers' unrelated cases into one. So every row
 * that exists today keeps a null, and keeps behaving exactly as it did: found by
 * (customer_id, source, external_id), unique by the two partial indexes from 27 September 2026,
 * which are left untouched.
 *
 * A null therefore means "Procynia has not been told which procurement this is", and because
 * Postgres treats nulls as distinct, any number of rows may hold one without colliding. Identity
 * arrives when a case is next saved through the application, one case at a time, from the register.
 *
 * WHY THE OLD INDEXES STAY.
 *
 * They are not superseded. They still guard the fallback path — a case with no identity — and they
 * still guard private requests. The new rule is an addition: a customer may hold at most one case
 * per procurement, and separately at most one per register record. Both are true at once, and a
 * case that satisfies the first can never violate the second, because a second register's record
 * is recorded in opportunity_source_records rather than as a second saved case.
 *
 * Different customers are untouched by all of this: customer_id leads both indexes, so the same
 * procurement saved by two customers is two cases, as it has always been.
 */
return new class extends Migration
{
    private const OPPORTUNITY_UNIQUE = 'saved_notices_customer_opportunity_unique';

    public function up(): void
    {
        Schema::table('saved_notices', function (Blueprint $table): void {
            // Nulled rather than cascaded, exactly like notice_id: losing the identity row must
            // never delete the customer's work built on top of it.
            $table->foreignId('opportunity_id')->nullable()->after('notice_id')->constrained('opportunities')->nullOnDelete();
        });

        // Partial, because null means unknown and unknowns are not each other.
        DB::statement(
            'CREATE UNIQUE INDEX '.self::OPPORTUNITY_UNIQUE
            .' ON saved_notices (customer_id, opportunity_id) WHERE opportunity_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::OPPORTUNITY_UNIQUE);

        Schema::table('saved_notices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('opportunity_id');
        });
    }
};
