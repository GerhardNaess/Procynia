<?php

namespace Tests\Feature\App;

use App\Models\Notice;
use App\Models\NoticeSource;
use App\Services\Doffin\DoffinNoticeSourceSyncService;
use App\Services\Doffin\DoffinSourceAdapter;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A notice can now say which external record it is.
 *
 * Until this phase the answer lived in `notices.notice_id` — a column whose name and meaning are
 * Doffin's — so a second source had nowhere to go that did not involve moving the first one.
 * `notice_sources` separates the notice from its external representations, and nothing else about
 * Procynia has to know yet.
 *
 * What these tests protect is that separation, and that it cost nothing: the identity holds, the
 * backfill and the runtime sync agree with each other, and every existing field and flow named in
 * the phase brief is untouched.
 */
class NoticeSourceIdentityTest extends TestCase
{
    use DatabaseTransactions;

    private function notice(array $attributes = []): Notice
    {
        return Notice::query()->create(array_merge([
            'notice_id' => 'test-'.Str::uuid()->toString(),
            'title' => 'Rammeavtale for sikkerhetstjenester',
            'raw_xml_stored' => false,
        ], $attributes));
    }

    private function sync(): DoffinNoticeSourceSyncService
    {
        return app(DoffinNoticeSourceSyncService::class);
    }

    /**
     * The migration itself, loaded the way Laravel loads it.
     *
     * The backfill is exercised through the file that owns it rather than through a service,
     * because the migration deliberately depends on nothing but the schema — see the note at the
     * top of it. Requiring the file is what the framework does, so this runs the same code a
     * `migrate` on a fresh install would.
     */
    private function migration(): object
    {
        return require database_path('migrations/2026_09_27_000001_create_notice_sources_table.php');
    }

    public function test_a_notice_can_have_source_records(): void
    {
        $notice = $this->notice();

        $notice->sources()->create([
            'source' => 'doffin',
            'external_id' => $notice->notice_id,
        ]);

        $this->assertInstanceOf(HasMany::class, $notice->sources());
        $this->assertSame(1, $notice->sources()->count());
        $this->assertSame('doffin', $notice->sources()->first()->source);
    }

    public function test_a_source_belongs_to_its_notice(): void
    {
        $notice = $this->notice();
        $source = $notice->sources()->create([
            'source' => 'doffin',
            'external_id' => $notice->notice_id,
        ]);

        $this->assertInstanceOf(BelongsTo::class, $source->notice());
        $this->assertTrue($source->notice->is($notice));
        $this->assertSame($notice->id, (int) $source->notice_id);
    }

    /** The whole of the deduplication this phase introduces. */
    public function test_the_same_external_record_cannot_be_registered_twice(): void
    {
        $notice = $this->notice();
        $externalId = $notice->notice_id;

        $notice->sources()->create(['source' => 'doffin', 'external_id' => $externalId]);

        $this->expectException(QueryException::class);

        // A second notice claiming the same Doffin record is exactly the case the constraint is for.
        $this->notice()->sources()->create(['source' => 'doffin', 'external_id' => $externalId]);
    }

    /**
     * Two sources using the same string is not a collision. They are different records that happen
     * to be named alike, and a constraint that rejected them would make the second source
     * unusable before it is ever built.
     */
    public function test_two_sources_may_share_an_external_id(): void
    {
        $notice = $this->notice();
        $externalId = $notice->notice_id;

        $notice->sources()->create(['source' => 'doffin', 'external_id' => $externalId]);
        $notice->sources()->create(['source' => 'ted', 'external_id' => $externalId]);

        $this->assertSame(2, $notice->sources()->count());
    }

    /**
     * One canonical spelling. Not "Doffin", not "DOFFIN", not "doffin-api".
     *
     * The migration writes the literal rather than reading the adapter, so that it stays runnable
     * when the adapter is renamed or moved. This is what keeps the two from drifting apart in the
     * meantime: rename the constant without updating the migration and this fails.
     */
    public function test_the_migration_and_the_adapter_agree_on_the_source_key(): void
    {
        $this->assertSame(
            DoffinSourceAdapter::SOURCE_KEY,
            $this->migration()::DOFFIN_SOURCE_KEY,
        );

        $this->assertSame('doffin', app(DoffinSourceAdapter::class)->sourceKey());
    }

    /**
     * The URL, unlike the key, is NOT tied to the adapter.
     *
     * It is external navigation metadata: if Doffin restructures its site, the adapter and
     * config/doffin.php follow and the runtime writes the new shape, while rows written in
     * September 2026 keep describing what was true then. A test that forced the two together
     * would make every future URL change a rewrite of history, so this asserts the frozen value
     * instead — which is what makes the migration deterministic.
     */
    public function test_the_migration_writes_the_frozen_doffin_url(): void
    {
        $notice = $this->notice(['notice_id' => 'urlcheck-'.Str::uuid()->toString()]);

        $this->migration()->backfillDoffinSources();

        $source = NoticeSource::query()->where('external_id', $notice->notice_id)->firstOrFail();

        $this->assertSame(
            'https://doffin.no/notices/'.rawurlencode($notice->notice_id),
            $source->source_url,
        );
    }

    /**
     * The whole point of freezing it: the same migration run under a different .env has to write
     * the same rows. A migration whose output depends on configuration is not a record of what
     * happened, it is a guess about what was configured.
     */
    public function test_configuration_cannot_change_what_the_migration_backfills(): void
    {
        $notice = $this->notice(['notice_id' => 'configproof-'.Str::uuid()->toString()]);

        config(['doffin.public_notice_url' => 'https://example.test/somewhere-else/%s']);

        $this->migration()->backfillDoffinSources();

        $source = NoticeSource::query()->where('external_id', $notice->notice_id)->firstOrFail();

        $this->assertStringStartsWith('https://doffin.no/notices/', $source->source_url);
        $this->assertStringNotContainsString('example.test', $source->source_url);
    }

    public function test_an_existing_notice_is_backfilled_from_its_own_data(): void
    {
        $notice = $this->notice([
            'notice_id' => 'backfill-'.Str::uuid()->toString(),
            'publication_date' => Carbon::parse('2026-03-04 08:00:00'),
            'downloaded_at' => Carbon::parse('2026-03-09 11:30:00'),
        ]);

        $this->migration()->backfillDoffinSources();

        $source = NoticeSource::query()
            ->where('source', 'doffin')
            ->where('external_id', $notice->notice_id)
            ->firstOrFail();

        $this->assertSame($notice->id, (int) $source->notice_id);
        $this->assertSame(
            'https://doffin.no/notices/'.rawurlencode($notice->notice_id),
            $source->source_url,
        );
        $this->assertTrue($source->published_at->equalTo($notice->publication_date));
        // first_seen = when Procynia created the row; last_seen = when the XML was last fetched.
        $this->assertTrue($source->first_seen_at->equalTo($notice->created_at));
        $this->assertTrue($source->last_seen_at->equalTo($notice->downloaded_at));
    }

    /** Nothing is invented: a notice with no download timestamp gets no fabricated one. */
    public function test_the_backfill_does_not_invent_timestamps(): void
    {
        $notice = $this->notice([
            'notice_id' => 'nodownload-'.Str::uuid()->toString(),
            'publication_date' => null,
            'downloaded_at' => null,
        ]);

        $this->migration()->backfillDoffinSources();

        $source = NoticeSource::query()->where('external_id', $notice->notice_id)->firstOrFail();

        $this->assertNull($source->published_at);
        // Falls back to when we created the row, which is genuinely when we first saw it.
        $this->assertTrue($source->last_seen_at->equalTo($notice->created_at));
    }

    public function test_the_backfill_is_idempotent(): void
    {
        $notice = $this->notice(['notice_id' => 'idem-'.Str::uuid()->toString()]);
        $migration = $this->migration();

        $firstRun = $migration->backfillDoffinSources();
        $countAfterFirst = NoticeSource::query()->where('external_id', $notice->notice_id)->count();

        $secondRun = $migration->backfillDoffinSources();

        $this->assertSame(1, $countAfterFirst);
        $this->assertGreaterThanOrEqual(1, $firstRun);
        $this->assertSame(0, $secondRun, 'a repeated run must write nothing');
        $this->assertSame(1, NoticeSource::query()->where('external_id', $notice->notice_id)->count());
    }

    public function test_an_imported_notice_gets_a_source_record(): void
    {
        $notice = $this->notice(['notice_id' => 'import-'.Str::uuid()->toString()]);

        $source = $this->sync()->syncForNotice($notice);

        $this->assertNotNull($source);
        $this->assertSame('doffin', $source->source);
        $this->assertSame($notice->notice_id, $source->external_id);
        $this->assertSame($notice->id, (int) $source->notice_id);
        $this->assertNotNull($source->first_seen_at);
        $this->assertNotNull($source->last_seen_at);
    }

    public function test_reimporting_the_same_notice_does_not_create_a_second_source(): void
    {
        $notice = $this->notice(['notice_id' => 'reimport-'.Str::uuid()->toString()]);

        $first = $this->sync()->syncForNotice($notice);
        $second = $this->sync()->syncForNotice($notice->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, NoticeSource::query()->where('external_id', $notice->notice_id)->count());
    }

    public function test_reimporting_refreshes_last_seen_but_never_first_seen(): void
    {
        $notice = $this->notice(['notice_id' => 'seen-'.Str::uuid()->toString()]);

        Carbon::setTestNow('2026-05-01 09:00:00');
        $first = $this->sync()->syncForNotice($notice);
        $firstSeen = $first->first_seen_at;

        Carbon::setTestNow('2026-05-08 09:00:00');
        $second = $this->sync()->syncForNotice($notice->fresh());
        Carbon::setTestNow();

        $this->assertTrue($second->first_seen_at->equalTo($firstSeen), 'a record seen before was not first seen again');
        $this->assertTrue($second->last_seen_at->equalTo(Carbon::parse('2026-05-08 09:00:00')));
    }

    /**
     * The reason the pipeline calls the sync a second time: on a first import the XML has not been
     * parsed yet, so the notice has no publication date to copy.
     */
    public function test_the_publication_date_arrives_once_parsing_has_read_it(): void
    {
        $notice = $this->notice([
            'notice_id' => 'published-'.Str::uuid()->toString(),
            'publication_date' => null,
        ]);

        $source = $this->sync()->syncForNotice($notice);
        $this->assertNull($source->published_at);

        $notice->fill(['publication_date' => Carbon::parse('2026-06-02 12:00:00')])->save();
        $refreshed = $this->sync()->syncForNotice($notice->fresh());

        $this->assertTrue($refreshed->published_at->equalTo(Carbon::parse('2026-06-02 12:00:00')));
    }

    /** A later sync that runs before parsing must not erase what an earlier one established. */
    public function test_a_known_publication_date_is_never_overwritten_with_null(): void
    {
        $notice = $this->notice([
            'notice_id' => 'keep-'.Str::uuid()->toString(),
            'publication_date' => Carbon::parse('2026-06-02 12:00:00'),
        ]);

        $this->sync()->syncForNotice($notice);

        $notice->fill(['publication_date' => null])->save();
        $source = $this->sync()->syncForNotice($notice->fresh());

        $this->assertNotNull($source->published_at);
        $this->assertTrue($source->published_at->equalTo(Carbon::parse('2026-06-02 12:00:00')));
    }

    public function test_a_notice_without_an_external_id_records_nothing(): void
    {
        $notice = $this->notice(['notice_id' => '']);

        $this->assertNull($this->sync()->syncForNotice($notice));
        $this->assertSame(0, $notice->sources()->count());
    }

    public function test_notice_id_is_untouched_by_the_sync(): void
    {
        $notice = $this->notice(['notice_id' => 'untouched-'.Str::uuid()->toString()]);
        $before = $notice->notice_id;

        $this->sync()->syncForNotice($notice);

        $this->assertSame($before, $notice->fresh()->notice_id);
        $this->assertSame($before, DB::table('notices')->where('id', $notice->id)->value('notice_id'));
    }

    /**
     * The phase brief's central promise: nothing else moved. These columns are what SavedNotice,
     * the watch inbox and the raw XML still identify themselves by, and a consumer that never
     * heard of notice_sources has to keep working.
     */
    public function test_the_columns_other_flows_depend_on_are_unchanged(): void
    {
        foreach ([
            ['notices', 'notice_id'],
            ['saved_notices', 'external_id'],
            ['watch_profile_inbox_records', 'doffin_notice_id'],
            ['notice_raw_xml', 'notice_id'],
        ] as [$table, $column]) {
            $this->assertTrue(
                Schema::hasColumn($table, $column),
                "{$table}.{$column} must still exist",
            );
        }

        // Phase 2 promised not to touch saved_notices, and did not. Phase 3A made the watch inbox
        // source-aware and Phase 3B did the same for saved cases, so neither is asserted absent
        // here any more. What this phase is still answerable for is that it pushed no foreign key
        // of its own onto SavedNotice: the link it eventually got points at notices, not at a
        // notice_source row.
        $this->assertFalse(Schema::hasColumn('saved_notices', 'notice_source_id'));

        // And no domain rewrite happened underneath this phase.
        $this->assertFalse(Schema::hasTable('opportunities'));
    }

    /** Deleting a notice takes its source records with it, and leaves nothing dangling. */
    public function test_sources_go_when_their_notice_goes(): void
    {
        $notice = $this->notice(['notice_id' => 'cascade-'.Str::uuid()->toString()]);
        $this->sync()->syncForNotice($notice);

        $noticeId = $notice->id;
        $notice->delete();

        $this->assertSame(0, NoticeSource::query()->where('notice_id', $noticeId)->count());
    }
}
