<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two ways a suggestion stops being asked, and why they share one table.
 *
 * WHY THE TABLE IS RENAMED.
 *
 * It was created for "Avvis" alone, and that is the whole reason "Avklar" came back every time:
 * answering a clarification recorded nothing, so the next reading of the process raised the same
 * question about a description that had just answered it. The fix is to record the answer too —
 * and the moment two outcomes live here, a table called `dismissals` is saying something untrue
 * about half its rows.
 *
 * WHY NOT A SECOND TABLE.
 *
 * Avvist and avklart are different statements about the process — one says the term is left to
 * judgement, the other says here is what it means — but they have exactly one lifecycle: recorded
 * against the description they were made about, matched on the normalised question, and lapsing
 * together when that description is replaced rather than refined. Splitting them would duplicate
 * that rule in two places and make the next change to it a change in two places. `outcome` is what
 * keeps them distinguishable, and it is read wherever the difference matters.
 *
 * WHY EXISTING ROWS BECOME `dismissed`.
 *
 * They are. Every row written before this migration came through the dismiss endpoint, which was
 * the only writer there has ever been.
 */
return new class extends Migration
{
    /**
     * Constraints, indexes and the sequence travel with the table under a Postgres rename, but
     * under their old names. Carried over so the next person reading `\d` on this table is not
     * told about a table that no longer exists.
     *
     * @var array<string, string>
     */
    private const RENAMED_CONSTRAINTS = [
        'quality_flow_clarification_dismissals_quality_item_id_question_' => 'quality_flow_clarification_resolutions_item_question_unique',
        'quality_flow_clarification_dismissals_customer_id_foreign' => 'quality_flow_clarification_resolutions_customer_id_foreign',
        'quality_flow_clarification_dismissals_quality_item_id_foreign' => 'quality_flow_clarification_resolutions_quality_item_id_foreign',
        'quality_flow_clarification_dismissals_dismissed_by_user_id_fore' => 'quality_flow_clarification_resolutions_resolved_by_user_foreign',
        'quality_flow_clarification_dismissals_pkey' => 'quality_flow_clarification_resolutions_pkey',
    ];

    public function up(): void
    {
        Schema::rename('quality_flow_clarification_dismissals', 'quality_flow_clarification_resolutions');

        Schema::table('quality_flow_clarification_resolutions', function (Blueprint $table): void {
            // dismissed | answered. Defaulted rather than nullable: a row here is always one of the
            // two, and the default is what makes the backfill of the existing rows a no-op.
            $table->string('outcome')->default('dismissed')->after('question_key');

            // Whoever settled it, whichever way they settled it. The old name described one half.
            $table->renameColumn('dismissed_by_user_id', 'resolved_by_user_id');
        });

        $this->renameConstraints(self::RENAMED_CONSTRAINTS);
        $this->renameSequence(
            'quality_flow_clarification_dismissals_id_seq',
            'quality_flow_clarification_resolutions_id_seq',
        );
    }

    public function down(): void
    {
        $this->renameConstraints(array_flip(self::RENAMED_CONSTRAINTS));
        $this->renameSequence(
            'quality_flow_clarification_resolutions_id_seq',
            'quality_flow_clarification_dismissals_id_seq',
        );

        Schema::table('quality_flow_clarification_resolutions', function (Blueprint $table): void {
            $table->renameColumn('resolved_by_user_id', 'dismissed_by_user_id');
            $table->dropColumn('outcome');
        });

        Schema::rename('quality_flow_clarification_resolutions', 'quality_flow_clarification_dismissals');
    }

    /**
     * Skipping what is not there rather than failing on it: a database built from scratch after
     * this migration lands never carried the old names, and `RENAME CONSTRAINT` has no IF EXISTS.
     *
     * @param  array<string, string>  $names
     */
    private function renameConstraints(array $names): void
    {
        $table = Schema::hasTable('quality_flow_clarification_resolutions')
            ? 'quality_flow_clarification_resolutions'
            : 'quality_flow_clarification_dismissals';

        foreach ($names as $from => $to) {
            $exists = DB::selectOne(
                'select 1 from pg_constraint where conname = ? and conrelid = to_regclass(?)',
                [$from, $table],
            );

            if ($exists !== null) {
                DB::statement(sprintf('ALTER TABLE %s RENAME CONSTRAINT %s TO %s', $table, $from, $to));
            }
        }
    }

    private function renameSequence(string $from, string $to): void
    {
        DB::statement(sprintf('ALTER SEQUENCE IF EXISTS %s RENAME TO %s', $from, $to));
    }
};
