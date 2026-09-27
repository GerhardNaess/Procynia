<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Watch inbox stops being a Doffin inbox.
 *
 * A row here has always meant "this external notice matched this watch profile", but it could only
 * say so in Doffin's vocabulary: the identity was `doffin_notice_id`, and a hit from anywhere else
 * would have had to pretend it had a Doffin id. After this it is (source, external_id) — the same
 * pair `notice_sources` already uses — and a future TED hit can be stored without lying about
 * where it came from.
 *
 * `doffin_notice_id` stays, nullable, as legacy compatibility. It is no longer the identity, and
 * nothing source-neutral reads it; for Doffin rows the runtime keeps writing it alongside
 * external_id so that anything still reading it keeps working. A non-Doffin row leaves it null.
 *
 * WHY THERE IS NO `source_url` COLUMN.
 *
 * The table already has `external_url`, and it already holds exactly this: the discovery service
 * writes it from NormalizedNotice::sourceUrl, which is source-neutral and comes from the adapter
 * layer, not from any knowledge of Doffin's URL format. `external_url` is also the name the rest
 * of the application and the frontend payload use for the same idea. Adding `source_url` beside it
 * would create two columns for one fact, two writers, and a drift waiting to happen — so the
 * source URL this phase asks for is the column that is already there, and the backfill below fills
 * it in where history left it empty.
 *
 * This file depends on nothing but the schema. A migration is a record of what happened to the
 * database on one day and has to stay runnable on a fresh install years later, so the source key
 * and the URL format are frozen literals rather than reads of config or the adapter — the same
 * rule 2026_09_27_000001 follows.
 */
return new class extends Migration
{
    /** The one canonical source key, spelled the way DoffinSourceAdapter::SOURCE_KEY spells it. */
    public const DOFFIN_SOURCE_KEY = 'doffin';

    /** Doffin's public notice URL as it stood on 27 September 2026. Frozen, never read from config. */
    public const DOFFIN_PUBLIC_NOTICE_URL = 'https://doffin.no/notices/%s';

    /** The Doffin-only identity this table was created with. */
    private const LEGACY_UNIQUE = 'watch_profile_inbox_records_unique_notice';

    /** The same rule, said in terms of a source rather than a vendor. */
    private const SOURCE_UNIQUE = 'watch_profile_inbox_records_unique_source_notice';

    public function up(): void
    {
        // 1. The new identity, nullable to begin with so existing rows survive the addition.
        Schema::table('watch_profile_inbox_records', function (Blueprint $table): void {
            $table->string('source', 50)->nullable()->after('department_id');
            $table->string('external_id')->nullable()->after('source');
        });

        // 2. Say about the rows that already exist what was always implicitly true of them.
        $this->backfillDoffinIdentity();

        // 3. Now that every row has one, the identity is not optional.
        Schema::table('watch_profile_inbox_records', function (Blueprint $table): void {
            $table->string('source', 50)->nullable(false)->change();
            $table->string('external_id')->nullable(false)->change();
        });

        // 4. Swap the uniqueness rule for the same rule in source-aware terms. Dropped first:
        //    the two overlap for Doffin rows, and leaving both would keep enforcing the vendor one.
        Schema::table('watch_profile_inbox_records', function (Blueprint $table): void {
            $table->dropUnique(self::LEGACY_UNIQUE);
            $table->unique(['watch_profile_id', 'source', 'external_id'], self::SOURCE_UNIQUE);
        });

        // 5. Legacy, and only legacy. A hit from a source that is not Doffin has no Doffin id, and
        //    must not be forced to invent one.
        Schema::table('watch_profile_inbox_records', function (Blueprint $table): void {
            $table->string('doffin_notice_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rows created by a non-Doffin source have no doffin_notice_id and cannot satisfy the old
        // identity, so reversing this is only safe while Doffin is the only source. Guarded rather
        // than assumed: a down() that silently drops the rows it cannot express would lose history.
        $foreignRows = DB::table('watch_profile_inbox_records')
            ->where('source', '!=', self::DOFFIN_SOURCE_KEY)
            ->count();

        if ($foreignRows > 0) {
            throw new RuntimeException(
                "Cannot reverse: {$foreignRows} watch inbox rows come from a source other than Doffin "
                .'and have no doffin_notice_id to fall back on.'
            );
        }

        Schema::table('watch_profile_inbox_records', function (Blueprint $table): void {
            $table->dropUnique(self::SOURCE_UNIQUE);
        });

        DB::table('watch_profile_inbox_records')->whereNull('doffin_notice_id')->delete();

        Schema::table('watch_profile_inbox_records', function (Blueprint $table): void {
            $table->string('doffin_notice_id')->nullable(false)->change();
            $table->unique(['watch_profile_id', 'doffin_notice_id'], self::LEGACY_UNIQUE);
            $table->dropColumn(['source', 'external_id']);
        });
    }

    /**
     * Every row that exists came from Doffin, because Doffin is the only source there has ever
     * been. Stating that is the whole backfill.
     *
     * Idempotent: it only writes rows whose new columns are still empty, so a repeated run — by
     * hand, on a partially migrated database, from a deploy routine that retries — does nothing.
     *
     * Public so a test can run it twice and see the second run write nothing, and so the frozen
     * URL can be asserted without resolving a service out of the container.
     *
     * @return int the number of rows given an identity
     */
    public function backfillDoffinIdentity(): int
    {
        $written = DB::table('watch_profile_inbox_records')
            ->whereNull('source')
            ->whereNotNull('doffin_notice_id')
            ->where('doffin_notice_id', '!=', '')
            ->update([
                'source' => self::DOFFIN_SOURCE_KEY,
                'external_id' => DB::raw('doffin_notice_id'),
            ]);

        // The URL history never recorded, rebuilt from the frozen format rather than guessed. Only
        // where it is missing: a URL that was stored at discovery time is what the source actually
        // gave us, and is better evidence than anything reconstructed here.
        DB::table('watch_profile_inbox_records')
            ->where('source', self::DOFFIN_SOURCE_KEY)
            ->whereNull('external_url')
            ->whereNotNull('external_id')
            ->orderBy('id')
            ->chunkById(500, function ($records): void {
                foreach ($records as $record) {
                    DB::table('watch_profile_inbox_records')
                        ->where('id', $record->id)
                        ->update([
                            'external_url' => sprintf(
                                self::DOFFIN_PUBLIC_NOTICE_URL,
                                rawurlencode((string) $record->external_id),
                            ),
                        ]);
                }
            });

        return $written;
    }
};
