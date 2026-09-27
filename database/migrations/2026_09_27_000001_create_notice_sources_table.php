<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which external record a Procynia notice is.
 *
 * `notices` stays what it has always been: one normalised Procynia notice. What it could not say
 * until now is where that notice came from — the external id lived in `notices.notice_id`, a
 * column whose name and meaning are Doffin's. This table separates the two: a notice is a notice,
 * and it is represented by one or more external records, each identified by (source, external_id).
 *
 * Today every notice has exactly one, and it is Doffin's. That is the point: nothing changes for
 * anybody yet, and `notices.notice_id` keeps working exactly as before. What changes is that the
 * database can now state the fact, so a second source can be added later without the first one
 * having to move.
 *
 * WHAT THIS TABLE DELIBERATELY DOES NOT HOLD.
 *
 * `raw_payload` / `raw_format`: the Doffin XML is already authoritative in `notice_raw_xml`, and
 * copying it here would duplicate large documents to fill a column nothing reads. There is no
 * writer for it in this phase, so it is not a field — it is a placeholder, and it can be added
 * when a source arrives whose payload is not already stored somewhere better.
 *
 * `status`: `notices.status` comes straight from the Doffin XML and is authoritative. With exactly
 * one source per notice, a per-source copy cannot carry information the notice does not already
 * have; it could only drift. A source-level status earns its place when two sources disagree about
 * one notice, which is a later phase.
 *
 * WHY THIS FILE DEPENDS ON NOTHING BUT THE SCHEMA.
 *
 * A migration is a record of what happened to the database on one day, and it has to keep being
 * runnable on a fresh install years later — after services have been renamed, namespaces moved,
 * adapters replaced and the runtime rearranged. Anything it resolves out of the container, or out
 * of config, is a future breakage waiting for a `migrate` on an empty database, and worse: it
 * would make the same migration write different data depending on the .env it happens to run
 * under. So the backfill below reads and writes raw columns, and both the source key and the URL
 * format are frozen literals — the values they had on 27 September 2026.
 *
 * That freeze is the point, not a shortcut. If Doffin changes its URL structure tomorrow, the
 * adapter and config/doffin.php change with it and the runtime follows; this file does not,
 * because it describes rows that were written under the old structure. The two are allowed to
 * disagree, and no test holds them together — a test that did would force every future URL change
 * to rewrite history.
 *
 * The source key is different, and stays tied to the adapter by a test: it is a persistent
 * identity rather than external navigation metadata, and a row written as 'doffin' has to keep
 * meaning what the adapter means by 'doffin', or the join stops working.
 */
return new class extends Migration
{
    /** The one canonical source key, spelled the way DoffinSourceAdapter::SOURCE_KEY spells it. */
    public const DOFFIN_SOURCE_KEY = 'doffin';

    /**
     * Doffin's public notice URL as it stood on 27 September 2026. Frozen, never read from config:
     * what was backfilled then must not change meaning because a later deployment is configured
     * differently.
     */
    public const DOFFIN_PUBLIC_NOTICE_URL = 'https://doffin.no/notices/%s';

    public function up(): void
    {
        Schema::create('notice_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notice_id')->constrained('notices')->cascadeOnDelete();
            $table->string('source', 50);
            $table->string('external_id');
            $table->string('source_url', 1000)->nullable();
            // When Procynia first recorded this external record, and when the source last handed
            // it to us. Distinct from the notice's own timestamps: those are about the Procynia
            // row, these are about our relationship with the source.
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            // The source's own publication date. Copied rather than derived because a second
            // source can publish the same opportunity on a different day.
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            // The only deduplication this phase introduces: one external record, one row.
            $table->unique(['source', 'external_id']);
            // "Which sources does this notice have", the direction every consumer reads.
            $table->index(['notice_id', 'source']);
        });

        $this->backfillDoffinSources();
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_sources');
    }

    /**
     * Give every notice that already exists the Doffin source record it has always implicitly had.
     *
     * Idempotent by construction: insertOrIgnore skips any (source, external_id) already present,
     * so a second run adds nothing and changes nothing. Without that, the unique constraint would
     * turn a repeated run — by hand, on a partially migrated database, from a deploy routine that
     * retries — into a failed migration rather than a no-op.
     *
     * Public so a test can run it twice and see that the second run writes nothing. That is the
     * whole reason for the visibility: the alternative was a service the migration resolves at
     * runtime, which is exactly what this file must not have.
     *
     * @return int the number of source records written; 0 on a re-run, and on an empty database
     */
    public function backfillDoffinSources(): int
    {
        $now = now();
        $written = 0;

        DB::table('notices')
            ->select(['id', 'notice_id', 'publication_date', 'created_at', 'downloaded_at'])
            ->whereNotNull('notice_id')
            ->where('notice_id', '!=', '')
            ->orderBy('id')
            // Chunked: this reads the whole notices table, which on a mature database is the
            // largest one in the schema.
            ->chunkById(500, function ($notices) use ($now, &$written): void {
                $rows = [];

                foreach ($notices as $notice) {
                    $externalId = (string) $notice->notice_id;

                    $rows[] = [
                        'notice_id' => $notice->id,
                        'source' => self::DOFFIN_SOURCE_KEY,
                        'external_id' => $externalId,
                        'source_url' => sprintf(self::DOFFIN_PUBLIC_NOTICE_URL, rawurlencode($externalId)),
                        // The notice row was created the first time Procynia saw this record, and
                        // downloaded_at is when the XML was last fetched from Doffin — which is
                        // exactly "last seen from the source". Nothing is invented: where the
                        // column is null the source field stays null rather than guessing.
                        'first_seen_at' => $notice->created_at,
                        'last_seen_at' => $notice->downloaded_at ?? $notice->created_at,
                        'published_at' => $notice->publication_date,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    $written += DB::table('notice_sources')->insertOrIgnore($rows);
                }
            });

        return $written;
    }
};
