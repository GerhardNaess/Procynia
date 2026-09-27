<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A saved case stops being identified by a bare external id.
 *
 * `external_id` alone cannot tell Doffin's notice 123 from another source's notice 123, so
 * "is this already saved?" and "is this in history?" would start answering wrongly the day a
 * second source arrives. The identity of a public case is (source, external_id) now — the same
 * pair `notice_sources` and `watch_profile_inbox_records` already use.
 *
 * WHY SOURCE IS STORED HERE RATHER THAN DERIVED.
 *
 * A public SavedNotice does not require a `notices` row, and frequently has none: storeSavedNotice()
 * takes the external id straight from a live search hit or a watch alert, neither of which goes
 * through the import pipeline. On the development database today there are two saved public cases
 * and zero notices. So the source cannot be reached through Notice → NoticeSource for the cases
 * that need it most, and fabricating Notice rows to make a foreign key work would put half-finished
 * records into the table the whole import pipeline trusts.
 *
 * `notice_id` is therefore a nullable link, not a required one: set where a canonical Notice really
 * exists, null where it does not, and never invented.
 *
 * WHY SOURCE IS NOT source_type.
 *
 * `source_type` answers "public notice or private request" — what kind of case this is. `source`
 * answers "which external register is it from". A private request comes from no external register
 * at all, so its source is null rather than a word that pretends otherwise.
 *
 * UNIQUENESS, IN TWO HALVES.
 *
 * The old rule was UNIQUE(customer_id, external_id) across both kinds. Replacing it with
 * (customer_id, source, external_id) would quietly stop protecting private requests, because
 * Postgres treats NULLs as distinct and their source is null. Two partial indexes keep both
 * guarantees exactly: public cases are unique per source, private ones per external id as before.
 *
 * This file depends on nothing but the schema — the source key is a frozen literal, the same rule
 * 2026_09_27_000001 and _000002 follow.
 */
return new class extends Migration
{
    /** The one canonical source key, spelled the way DoffinSourceAdapter::SOURCE_KEY spells it. */
    public const DOFFIN_SOURCE_KEY = 'doffin';

    /** The case kind that came from an external register, as SavedNotice spelled it on 27.09.2026. */
    private const PUBLIC_NOTICE = 'public_notice';

    private const LEGACY_UNIQUE = 'saved_notices_customer_id_external_id_unique';

    private const PUBLIC_UNIQUE = 'saved_notices_customer_source_external_unique';

    private const PRIVATE_UNIQUE = 'saved_notices_customer_external_no_source_unique';

    public function up(): void
    {
        Schema::table('saved_notices', function (Blueprint $table): void {
            // Null for a private request, which comes from no external register.
            $table->string('source', 50)->nullable()->after('source_type');
            // The canonical Notice, where one exists. Nulled rather than cascaded: losing the
            // imported notice must not delete the customer's case built on top of it.
            $table->foreignId('notice_id')->nullable()->after('source')->constrained('notices')->nullOnDelete();
        });

        $this->backfillSourceIdentity();

        // The old rule covered both kinds at once. Its two halves need different terms now, so it
        // is replaced by both of them rather than widened.
        // A constraint, not a bare index — Postgres refuses to drop the index out from under it.
        DB::statement('ALTER TABLE saved_notices DROP CONSTRAINT IF EXISTS '.self::LEGACY_UNIQUE);
        DB::statement('DROP INDEX IF EXISTS '.self::LEGACY_UNIQUE);
        DB::statement(
            'CREATE UNIQUE INDEX '.self::PUBLIC_UNIQUE
            .' ON saved_notices (customer_id, source, external_id) WHERE source IS NOT NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX '.self::PRIVATE_UNIQUE
            .' ON saved_notices (customer_id, external_id) WHERE source IS NULL'
        );
    }

    public function down(): void
    {
        // A case from a source other than Doffin cannot be expressed by the old rule: two of them
        // sharing an external id would collide the moment the global unique index came back.
        $foreignRows = DB::table('saved_notices')
            ->whereNotNull('source')
            ->where('source', '!=', self::DOFFIN_SOURCE_KEY)
            ->count();

        if ($foreignRows > 0) {
            throw new RuntimeException(
                "Cannot reverse: {$foreignRows} saved cases come from a source other than Doffin, "
                .'and the pre-3B unique index cannot tell them apart from Doffin cases.'
            );
        }

        DB::statement('DROP INDEX IF EXISTS '.self::PUBLIC_UNIQUE);
        DB::statement('DROP INDEX IF EXISTS '.self::PRIVATE_UNIQUE);
        DB::statement(
            'ALTER TABLE saved_notices ADD CONSTRAINT '.self::LEGACY_UNIQUE
            .' UNIQUE (customer_id, external_id)'
        );

        Schema::table('saved_notices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('notice_id');
            $table->dropColumn('source');
        });
    }

    /**
     * Say about the cases that already exist what was always implicitly true of them.
     *
     * Every public case came from Doffin, because Doffin is the only register there has ever been.
     * Private requests are left alone: their external id is a synthetic ULID, not an external
     * identity, and marking them as Doffin would be false.
     *
     * The Notice link is only made where it is unambiguous — one notice, one external id. A case
     * whose notice was never imported keeps a null link rather than a guessed one.
     *
     * Idempotent: it only touches rows whose source is still empty, so a repeated run does nothing.
     *
     * Public so a test can run it twice, and so the frozen key can be asserted without resolving a
     * service out of the container.
     *
     * @return array{sourced: int, linked: int}
     */
    public function backfillSourceIdentity(): array
    {
        $sourced = DB::table('saved_notices')
            ->whereNull('source')
            ->where('source_type', self::PUBLIC_NOTICE)
            ->update(['source' => self::DOFFIN_SOURCE_KEY]);

        $linked = 0;

        DB::table('saved_notices')
            ->select(['id', 'external_id'])
            ->where('source', self::DOFFIN_SOURCE_KEY)
            ->whereNull('notice_id')
            ->orderBy('id')
            ->chunkById(500, function ($savedNotices) use (&$linked): void {
                foreach ($savedNotices as $savedNotice) {
                    // notices.notice_id is the Doffin id, and this is the join the document lookup
                    // has always made by hand. Made explicit here, and only when it resolves to
                    // exactly one row — anything else is a guess, and a guess would attach a
                    // customer's case to the wrong public notice.
                    $matches = DB::table('notices')
                        ->where('notice_id', (string) $savedNotice->external_id)
                        ->limit(2)
                        ->pluck('id');

                    if ($matches->count() !== 1) {
                        continue;
                    }

                    $linked += DB::table('saved_notices')
                        ->where('id', $savedNotice->id)
                        ->update(['notice_id' => $matches->first()]);
                }
            });

        return ['sourced' => $sourced, 'linked' => $linked];
    }
};
