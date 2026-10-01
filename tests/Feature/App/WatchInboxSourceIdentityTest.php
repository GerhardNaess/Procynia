<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\WatchProfile;
use App\Models\WatchProfileInboxRecord;
use App\Services\BidWorkflowNotificationService;
use App\Services\Doffin\DoffinSourceAdapter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Watch inbox stops being a Doffin inbox.
 *
 * A row has always meant "this external notice matched this watch profile", but it could only say
 * so in Doffin's vocabulary: the identity was `doffin_notice_id`, and a hit from anywhere else
 * would have had to pretend it had a Doffin id. It is (source, external_id) now — the same pair
 * `notice_sources` uses — and `doffin_notice_id` is legacy compatibility, nullable, dual-written
 * for Doffin and null for anything else.
 *
 * What these tests protect is that the change cost nothing: Doffin behaves exactly as before, the
 * uniqueness rule means the same thing in source-aware terms, and the table can now hold a record
 * from a source that does not exist yet — which is the only way to know it really is multi-source.
 */
class WatchInboxSourceIdentityTest extends TestCase
{
    use DatabaseTransactions;

    private function migration(): object
    {
        return require database_path('migrations/2026_09_27_000002_make_watch_profile_inbox_records_source_aware.php');
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Watch Inbox AS',
            'slug' => 'watch-inbox-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function watchProfile(?Customer $customer = null, ?User $user = null): WatchProfile
    {
        $customer = $customer ?? $this->customer();
        $user = $user ?? User::query()->create([
            'name' => 'Watch Owner',
            'email' => 'watch-'.Str::uuid().'@procynia.test',
            'password' => bcrypt('secret-only-local'),
            'customer_id' => $customer->id,
            'role' => 'user',
            'bid_role' => 'contributor',
            'is_active' => true,
        ]);

        return WatchProfile::query()->create([
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'name' => 'Sikkerhetstjenester',
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function record(WatchProfile $profile, array $attributes = []): WatchProfileInboxRecord
    {
        return WatchProfileInboxRecord::query()->create(array_merge([
            'watch_profile_id' => $profile->id,
            'customer_id' => $profile->customer_id,
            'user_id' => $profile->user_id,
            'source' => DoffinSourceAdapter::SOURCE_KEY,
            'external_id' => '2026-'.random_int(100000, 999999),
            'title' => 'Rammeavtale for sikkerhetstjenester',
            'discovered_at' => now(),
        ], $attributes));
    }

    public function test_the_identity_is_a_source_and_an_external_id(): void
    {
        $record = $this->record($this->watchProfile(), ['external_id' => '2026-300001']);

        $this->assertSame('doffin', $record->source);
        $this->assertSame('2026-300001', $record->external_id);
    }

    /**
     * The same rule the table has always enforced, said in terms of a source: one external notice
     * lands in one watch profile once.
     */
    public function test_a_profile_cannot_hold_the_same_source_record_twice(): void
    {
        $profile = $this->watchProfile();
        $this->record($profile, ['external_id' => '2026-300002']);

        $this->expectException(QueryException::class);

        $this->record($profile, ['external_id' => '2026-300002']);
    }

    /** One notice may of course match several profiles — that was never the collision. */
    public function test_the_same_notice_may_match_several_profiles(): void
    {
        $customer = $this->customer();

        $this->record($this->watchProfile($customer), ['external_id' => '2026-300003']);
        $this->record($this->watchProfile($customer), ['external_id' => '2026-300003']);

        $this->assertSame(2, WatchProfileInboxRecord::query()->where('external_id', '2026-300003')->count());
    }

    /**
     * The point of the whole phase: two sources naming a notice the same thing are two records,
     * not a conflict.
     */
    public function test_two_sources_may_use_the_same_external_id_in_one_profile(): void
    {
        $profile = $this->watchProfile();

        $this->record($profile, ['source' => 'doffin', 'external_id' => '2026-300004']);
        $this->record($profile, ['source' => 'ted', 'external_id' => '2026-300004', 'doffin_notice_id' => null]);

        $this->assertSame(2, $profile->inboxRecords()->where('external_id', '2026-300004')->count());
    }

    /**
     * The proof that the database is genuinely multi-source, without a line of TED code: a record
     * from a source that does not exist yet, with no Doffin id to borrow.
     */
    public function test_a_record_from_another_source_needs_no_doffin_id(): void
    {
        $record = $this->record($this->watchProfile(), [
            'source' => 'some-future-source',
            'external_id' => 'XYZ-42',
            'doffin_notice_id' => null,
            'external_url' => 'https://example.test/notices/XYZ-42',
        ]);

        $this->assertNull($record->fresh()->doffin_notice_id);
        $this->assertSame('some-future-source', $record->fresh()->source);
        $this->assertSame('XYZ-42', $record->fresh()->external_id);
    }

    public function test_the_legacy_column_is_nullable_and_the_identity_is_not(): void
    {
        $columns = collect(DB::select(
            "select column_name, is_nullable from information_schema.columns where table_name = 'watch_profile_inbox_records'"
        ))->keyBy('column_name');

        $this->assertSame('YES', $columns['doffin_notice_id']->is_nullable, 'legacy, therefore optional');
        $this->assertSame('NO', $columns['source']->is_nullable);
        $this->assertSame('NO', $columns['external_id']->is_nullable);

        // The Doffin-only rule is gone; its source-aware replacement is in place.
        $unique = collect(DB::select(
            "select indexname from pg_indexes where tablename = 'watch_profile_inbox_records' and indexdef like '%UNIQUE%'"
        ))->pluck('indexname');

        $this->assertTrue($unique->contains('watch_profile_inbox_records_unique_source_notice'));
        $this->assertFalse($unique->contains('watch_profile_inbox_records_unique_notice'));
    }

    /**
     * The backfill, run the way it actually ran.
     *
     * The identity columns are NOT NULL now, so the pre-migration state cannot be faked by nulling
     * them — which is the migration doing its job. Reversing and re-applying inside the test
     * transaction is the honest way to see what a real `migrate` did to a real row: Postgres rolls
     * DDL back with everything else, so nothing survives the test.
     */
    private function reapplyMigration(): void
    {
        $migration = $this->migration();
        $migration->down();
        $migration->up();
    }

    public function test_an_existing_row_is_backfilled_from_its_doffin_id(): void
    {
        $profile = $this->watchProfile();
        $record = $this->record($profile, [
            'external_id' => '2026-300005',
            'doffin_notice_id' => '2026-300005',
            'external_url' => null,
        ]);

        $this->reapplyMigration();

        $backfilled = $record->fresh();

        $this->assertSame('doffin', $backfilled->source);
        $this->assertSame('2026-300005', $backfilled->external_id);
        $this->assertSame('2026-300005', $backfilled->doffin_notice_id, 'the legacy column is not disturbed');
        $this->assertSame('https://doffin.no/notices/2026-300005', $backfilled->external_url);
    }

    /** A URL the source actually gave us is better evidence than one rebuilt here. */
    public function test_the_backfill_does_not_overwrite_a_stored_url(): void
    {
        $profile = $this->watchProfile();
        $record = $this->record($profile, [
            'external_id' => '2026-300006',
            'doffin_notice_id' => '2026-300006',
            'external_url' => 'https://doffin.no/notices/2026-300006?from=discovery',
        ]);

        $this->reapplyMigration();

        $this->assertSame('https://doffin.no/notices/2026-300006?from=discovery', $record->fresh()->external_url);
    }

    public function test_the_backfill_is_idempotent(): void
    {
        $profile = $this->watchProfile();
        $record = $this->record($profile, ['external_id' => '2026-300007', 'doffin_notice_id' => '2026-300007']);

        $this->reapplyMigration();

        // up() has already backfilled this row; running it again must find nothing left to do.
        $this->assertSame(0, $this->migration()->backfillDoffinIdentity());
        $this->assertSame('2026-300007', $record->fresh()->external_id);
        $this->assertSame(1, WatchProfileInboxRecord::query()->where('external_id', '2026-300007')->count());
    }

    /**
     * A migration whose output depends on configuration is not a record of what happened, it is a
     * guess about what was configured.
     */
    public function test_configuration_cannot_change_what_the_migration_backfills(): void
    {
        $profile = $this->watchProfile();
        $record = $this->record($profile, [
            'external_id' => '2026-300008',
            'doffin_notice_id' => '2026-300008',
            'external_url' => null,
        ]);

        config(['doffin.public_notice_url' => 'https://example.test/elsewhere/%s']);

        $this->reapplyMigration();

        $this->assertStringStartsWith('https://doffin.no/notices/', (string) $record->fresh()->external_url);
        $this->assertStringNotContainsString('example.test', (string) $record->fresh()->external_url);
    }

    /** One canonical spelling, shared with the adapter the runtime uses. */
    public function test_the_migration_and_the_adapter_agree_on_the_source_key(): void
    {
        $this->assertSame(DoffinSourceAdapter::SOURCE_KEY, $this->migration()::DOFFIN_SOURCE_KEY);
        $this->assertSame('doffin', app(DoffinSourceAdapter::class)->sourceKey());
    }

    /**
     * The dedupe key had to become source-aware without changing for the alerts that already
     * exist — a key that changes meaning re-announces matches people were told about weeks ago.
     */
    public function test_a_doffin_match_keeps_the_dedupe_key_it_always_had(): void
    {
        $profile = $this->watchProfile();
        $record = $this->record($profile, ['external_id' => '2026-300009', 'doffin_notice_id' => '2026-300009']);

        app(BidWorkflowNotificationService::class)->watchProfileMatched($profile, $record);

        $notification = UserNotification::query()
            ->where('user_id', $profile->user_id)
            ->where('event_type', BidWorkflowNotificationService::EVENT_WATCH_PROFILE_MATCH)
            ->firstOrFail();

        $this->assertSame(
            sprintf('watch_profile.match_found:%d:2026-300009:%d', $profile->id, $profile->user_id),
            $notification->dedupe_key,
        );
        // Additive metadata: the identity arrives without the legacy key leaving.
        $this->assertSame('doffin', $notification->metadata['source']);
        $this->assertSame('2026-300009', $notification->metadata['external_id']);
        $this->assertSame('2026-300009', $notification->metadata['doffin_notice_id']);
    }

    /** The same notice seen again is the same alert, exactly as before. */
    public function test_a_repeated_match_does_not_notify_twice(): void
    {
        $profile = $this->watchProfile();
        $record = $this->record($profile, ['external_id' => '2026-300010', 'doffin_notice_id' => '2026-300010']);
        $service = app(BidWorkflowNotificationService::class);

        $service->watchProfileMatched($profile, $record);
        $service->watchProfileMatched($profile, $record->fresh());

        $this->assertSame(1, UserNotification::query()
            ->where('user_id', $profile->user_id)
            ->where('event_type', BidWorkflowNotificationService::EVENT_WATCH_PROFILE_MATCH)
            ->count());
    }

    /** A different source carrying the same number is a different alert, not a duplicate. */
    public function test_another_source_with_the_same_id_is_a_separate_alert(): void
    {
        $profile = $this->watchProfile();
        $doffin = $this->record($profile, ['source' => 'doffin', 'external_id' => '2026-300011', 'doffin_notice_id' => '2026-300011']);
        $other = $this->record($profile, ['source' => 'ted', 'external_id' => '2026-300011', 'doffin_notice_id' => null]);

        $service = app(BidWorkflowNotificationService::class);
        $service->watchProfileMatched($profile, $doffin);
        $service->watchProfileMatched($profile, $other);

        $keys = UserNotification::query()
            ->where('user_id', $profile->user_id)
            ->where('event_type', BidWorkflowNotificationService::EVENT_WATCH_PROFILE_MATCH)
            ->pluck('dedupe_key');

        $this->assertCount(2, $keys);
        $this->assertTrue($keys->contains(sprintf('watch_profile.match_found:%d:2026-300011:%d', $profile->id, $profile->user_id)));
        $this->assertTrue($keys->contains(sprintf('watch_profile.match_found:%d:ted/2026-300011:%d', $profile->id, $profile->user_id)));
    }

    /**
     * Phase 3A touched the watch inbox and nothing else.
     *
     * SavedNotice has since become source-aware in its own phase, so its columns are no longer
     * asserted absent here — what still holds, and is what 3A actually promised, is that the
     * legacy external_id survived and notice_sources was left alone.
     */
    public function test_saved_notice_and_notice_sources_are_untouched(): void
    {
        $this->assertTrue(Schema::hasColumn('saved_notices', 'external_id'));

        foreach (['notice_id', 'source', 'external_id', 'source_url', 'first_seen_at', 'last_seen_at', 'published_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('notice_sources', $column), "notice_sources.{$column}");
        }
    }
}
