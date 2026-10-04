<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Risiko — periodisk vurdering: how often a risk is to be assessed again.
 *
 * Only the interval is stored, in whole calendar months like Kvalitet's review_interval_months.
 * The next review date is never stored and never typed: RiskReviewSchedule derives it on read from
 * the latest risk assessment plus this interval, so a new assessment or a changed interval moves it
 * without anything else being written. A review is carried out by registering a new assessment —
 * there is no separate review log.
 *
 * NULL means the risk has no fixed review cycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('risks', function (Blueprint $table): void {
            $table->unsignedSmallInteger('review_interval_months')->nullable()->after('status');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE risks ADD CONSTRAINT risks_review_interval_months_check CHECK (review_interval_months IS NULL OR review_interval_months IN (1, 3, 6, 12))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE risks DROP CONSTRAINT IF EXISTS risks_review_interval_months_check');
        }

        Schema::table('risks', function (Blueprint $table): void {
            $table->dropColumn('review_interval_months');
        });
    }
};
