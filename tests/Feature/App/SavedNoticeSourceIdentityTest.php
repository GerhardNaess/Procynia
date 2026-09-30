<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\Notice;
use App\Models\SavedNotice;
use App\Models\User;
use App\Models\WatchProfile;
use App\Models\WatchProfileInboxRecord;
use App\Services\Doffin\DoffinSourceAdapter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A saved case stops being identified by a bare external id.
 *
 * `external_id` alone cannot tell Doffin's notice 123 from another register's notice 123. Today
 * that costs nothing, because Doffin is the only register; the day a second one arrives it would
 * make "already saved" and "in history" answer wrongly about the exact hits a bid manager is
 * deciding on. The identity of a public case is (source, external_id) now.
 *
 * The collision tests below are the point of the phase. They use a made-up source key rather than
 * TED, because the question is whether the matching is genuinely source-aware — not whether any
 * particular second register has been built.
 */
class SavedNoticeSourceIdentityTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        // Saving a public case now asks Doffin which procurement the notice is, so these tests
        // would otherwise reach the live API. An empty detail is the ordinary "the register did
        // not say" answer, and leaves every case below on the (source, external_id) path it has
        // always taken.
        Http::fake(['*' => Http::response([], 200)]);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_09_27_000003_make_saved_notices_source_aware.php');
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => 'Saved Identity AS',
            'slug' => 'saved-identity-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function user(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'Bid Manager',
            'email' => 'saved-'.Str::uuid().'@procynia.test',
            'password' => bcrypt('secret-only-local'),
            'customer_id' => $customer->id,
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function savedNotice(Customer $customer, User $user, array $attributes = []): SavedNotice
    {
        return SavedNotice::query()->create(array_merge([
            'customer_id' => $customer->id,
            'saved_by_user_id' => $user->id,
            'source_type' => SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE,
            'source' => DoffinSourceAdapter::SOURCE_KEY,
            'external_id' => '2026-'.random_int(100000, 999999),
            'title' => 'Rammeavtale for sikkerhetstjenester',
            'bid_status' => SavedNotice::BID_STATUS_DISCOVERED,
        ], $attributes));
    }

    /** @param array<string, mixed> $attributes */
    private function watchRecord(Customer $customer, User $user, array $attributes = []): WatchProfileInboxRecord
    {
        $profile = WatchProfile::query()->create([
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'name' => 'Sikkerhetstjenester',
            'is_active' => true,
        ]);

        return WatchProfileInboxRecord::query()->create(array_merge([
            'watch_profile_id' => $profile->id,
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'source' => DoffinSourceAdapter::SOURCE_KEY,
            'external_id' => '2026-'.random_int(100000, 999999),
            'title' => 'Rammeavtale for sikkerhetstjenester',
            'discovered_at' => now(),
        ], $attributes));
    }

    /** @return array<string, mixed> */
    private function firstWatchAlert(User $user): array
    {
        $payload = $this->actingAs($user)
            ->get('/app/notices?mode=live&tab=alerts')
            ->assertOk()
            ->viewData('page')['props']['watchAlerts'] ?? null;

        $this->assertNotNull($payload, 'the alerts payload must be rendered');
        $this->assertNotEmpty($payload['data'], 'the alert must reach the payload');

        return $payload['data'][0];
    }

    // ---------------------------------------------------------------- the model

    public function test_a_public_case_carries_its_register_and_a_nullable_notice_link(): void
    {
        $customer = $this->customer();
        $case = $this->savedNotice($customer, $this->user($customer));

        $this->assertSame('doffin', $case->source);
        $this->assertNull($case->notice_id, 'a case saved from live search has no imported notice');
    }

    /** A private request comes from no external register, so it names none. */
    public function test_a_private_request_has_no_source(): void
    {
        $customer = $this->customer();
        $case = $this->savedNotice($customer, $this->user($customer), [
            'source_type' => SavedNotice::SOURCE_TYPE_PRIVATE_REQUEST,
            'source' => null,
            'external_id' => 'private-request-'.Str::ulid(),
        ]);

        $this->assertNull($case->source);
        $this->assertNotNull($case->external_id, 'it keeps its synthetic id');
    }

    public function test_the_same_register_cannot_save_one_notice_twice_for_a_customer(): void
    {
        $customer = $this->customer();
        $user = $this->user($customer);
        $this->savedNotice($customer, $user, ['external_id' => 'ABC-1']);

        $this->expectException(QueryException::class);

        $this->savedNotice($customer, $user, ['external_id' => 'ABC-1']);
    }

    /** The whole point: two registers naming a notice the same thing are two cases. */
    public function test_two_registers_may_use_the_same_external_id(): void
    {
        $customer = $this->customer();
        $user = $this->user($customer);

        $this->savedNotice($customer, $user, ['source' => 'doffin', 'external_id' => 'ABC-2']);
        $this->savedNotice($customer, $user, ['source' => 'test-source', 'external_id' => 'ABC-2']);

        $this->assertSame(2, SavedNotice::query()
            ->where('customer_id', $customer->id)
            ->where('external_id', 'ABC-2')
            ->count());
    }

    /** Replacing the old rule must not quietly stop protecting private requests. */
    public function test_private_requests_keep_their_own_uniqueness(): void
    {
        $customer = $this->customer();
        $user = $this->user($customer);
        $this->savedNotice($customer, $user, [
            'source_type' => SavedNotice::SOURCE_TYPE_PRIVATE_REQUEST,
            'source' => null,
            'external_id' => 'private-request-fixed',
        ]);

        $this->expectException(QueryException::class);

        $this->savedNotice($customer, $user, [
            'source_type' => SavedNotice::SOURCE_TYPE_PRIVATE_REQUEST,
            'source' => null,
            'external_id' => 'private-request-fixed',
        ]);
    }

    // ---------------------------------------------------------------- the backfill

    public function test_an_existing_public_case_is_backfilled_as_doffin(): void
    {
        $customer = $this->customer();
        $case = $this->savedNotice($customer, $this->user($customer), ['external_id' => 'BF-1']);

        DB::table('saved_notices')->where('id', $case->id)->update(['source' => null]);

        $this->migration()->backfillSourceIdentity();

        $this->assertSame('doffin', $case->fresh()->source);
        $this->assertSame('BF-1', $case->fresh()->external_id, 'the legacy column is untouched');
    }

    public function test_a_private_request_is_never_backfilled_as_doffin(): void
    {
        $customer = $this->customer();
        $case = $this->savedNotice($customer, $this->user($customer), [
            'source_type' => SavedNotice::SOURCE_TYPE_PRIVATE_REQUEST,
            'source' => null,
            'external_id' => 'private-request-'.Str::ulid(),
        ]);

        $this->migration()->backfillSourceIdentity();

        $this->assertNull($case->fresh()->source);
    }

    public function test_the_backfill_links_a_case_to_its_imported_notice(): void
    {
        $customer = $this->customer();
        $notice = Notice::query()->create(['notice_id' => 'LINK-1', 'title' => 'Importert', 'raw_xml_stored' => false]);
        $case = $this->savedNotice($customer, $this->user($customer), ['external_id' => 'LINK-1']);

        DB::table('saved_notices')->where('id', $case->id)->update(['source' => null, 'notice_id' => null]);

        $result = $this->migration()->backfillSourceIdentity();

        $this->assertSame($notice->id, $case->fresh()->notice_id);
        $this->assertGreaterThanOrEqual(1, $result['linked']);
    }

    /** No Notice, no link — and no fabricated Notice row to make a column look full. */
    public function test_a_case_without_an_imported_notice_keeps_a_null_link(): void
    {
        $customer = $this->customer();
        $case = $this->savedNotice($customer, $this->user($customer), ['external_id' => 'NOLINK-1']);
        $noticesBefore = Notice::query()->count();

        DB::table('saved_notices')->where('id', $case->id)->update(['source' => null]);

        $this->migration()->backfillSourceIdentity();

        $this->assertNull($case->fresh()->notice_id);
        $this->assertSame($noticesBefore, Notice::query()->count(), 'nothing was invented to fill the link');
    }

    public function test_the_backfill_is_idempotent(): void
    {
        $customer = $this->customer();
        $case = $this->savedNotice($customer, $this->user($customer), ['external_id' => 'IDEM-1']);

        DB::table('saved_notices')->where('id', $case->id)->update(['source' => null]);

        $migration = $this->migration();

        $this->assertGreaterThanOrEqual(1, $migration->backfillSourceIdentity()['sourced']);
        $this->assertSame(0, $migration->backfillSourceIdentity()['sourced'], 'a repeated run must write nothing');
    }

    /** The frozen key, and the one spelling the runtime uses. */
    public function test_the_migration_and_the_adapter_agree_on_the_source_key(): void
    {
        $this->assertSame(DoffinSourceAdapter::SOURCE_KEY, $this->migration()::DOFFIN_SOURCE_KEY);
        $this->assertSame('doffin', app(DoffinSourceAdapter::class)->sourceKey());
    }

    public function test_the_legacy_column_and_the_watch_inbox_are_untouched(): void
    {
        $this->assertTrue(Schema::hasColumn('saved_notices', 'external_id'));
        $this->assertTrue(Schema::hasColumn('watch_profile_inbox_records', 'doffin_notice_id'));

        // Phase 5D added the procurement a case is about. It sits beside this phase's identity
        // rather than replacing it: (source, external_id) is still what a case is found by when
        // no register has said which procurement it is, which is every case saved before 5D.
        $this->assertTrue(Schema::hasColumn('saved_notices', 'opportunity_id'));
        $this->assertTrue(Schema::hasColumn('saved_notices', 'source'));
        $this->assertTrue(Schema::hasColumn('saved_notices', 'external_id'));
    }

    // ------------------------------------------------- saved / history collisions

    /** A. Same register, same id — saved, exactly as before. */
    public function test_a_doffin_alert_reads_as_saved_when_the_doffin_case_exists(): void
    {
        $customer = $this->customer();
        $user = $this->user($customer);
        $this->savedNotice($customer, $user, ['source' => 'doffin', 'external_id' => 'ABC']);
        $this->watchRecord($customer, $user, ['source' => 'doffin', 'external_id' => 'ABC']);

        $this->assertTrue($this->firstWatchAlert($user)['is_saved']);
    }

    /** B. Another register, same id — a different opportunity, and not saved. */
    public function test_another_register_sharing_an_id_does_not_read_as_saved(): void
    {
        $customer = $this->customer();
        $user = $this->user($customer);
        $this->savedNotice($customer, $user, ['source' => 'doffin', 'external_id' => 'ABC']);
        $this->watchRecord($customer, $user, ['source' => 'test-source', 'external_id' => 'ABC', 'doffin_notice_id' => null]);

        $this->assertFalse($this->firstWatchAlert($user)['is_saved']);
    }

    /** C. The same rule for history: another register's id is not this one's past. */
    public function test_another_register_sharing_an_id_does_not_read_as_history(): void
    {
        $customer = $this->customer();
        $user = $this->user($customer);
        $this->savedNotice($customer, $user, [
            'source' => 'doffin',
            'external_id' => 'ABC',
            'archived_at' => now(),
            'history_type' => SavedNotice::HISTORY_TYPES[0],
        ]);
        $this->watchRecord($customer, $user, ['source' => 'test-source', 'external_id' => 'ABC', 'doffin_notice_id' => null]);

        $this->assertFalse($this->firstWatchAlert($user)['is_in_history']);
    }

    /** D. Same register, same id, archived — history, exactly as before. */
    public function test_a_doffin_alert_reads_as_history_when_the_doffin_case_is_archived(): void
    {
        $customer = $this->customer();
        $user = $this->user($customer);
        $this->savedNotice($customer, $user, [
            'source' => 'doffin',
            'external_id' => 'ABC',
            'archived_at' => now(),
            'history_type' => SavedNotice::HISTORY_TYPES[0],
        ]);
        $this->watchRecord($customer, $user, ['source' => 'doffin', 'external_id' => 'ABC']);

        $this->assertTrue($this->firstWatchAlert($user)['is_in_history']);
    }

    // ---------------------------------------------------------------- documents

    /** The link is used when the case has one. */
    public function test_documents_follow_the_notice_link(): void
    {
        $customer = $this->customer();
        $user = $this->user($customer);
        $notice = Notice::query()->create(['notice_id' => 'DOC-1', 'title' => 'Importert', 'raw_xml_stored' => false]);
        $case = $this->savedNotice($customer, $user, ['external_id' => 'DOC-1', 'notice_id' => $notice->id]);

        $this->assertSame($notice->id, $case->notice->id);
    }

    /**
     * Without a link, the legacy id match still works — but only inside the register whose ids
     * `notices.notice_id` actually holds. Another register's case must not collect these documents.
     */
    public function test_documents_are_not_borrowed_across_registers(): void
    {
        $customer = $this->customer();
        $user = $this->user($customer);
        Notice::query()->create(['notice_id' => 'DOC-2', 'title' => 'Doffin-kunngjøring', 'raw_xml_stored' => false]);
        $foreign = $this->savedNotice($customer, $user, [
            'source' => 'test-source',
            'external_id' => 'DOC-2',
            'notice_id' => null,
        ]);

        $payload = $this->actingAs($user)
            ->get('/app/notices/saved/'.$foreign->id)
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame([], $payload['notice']['documents']);
        $this->assertNull($payload['notice']['download_all_url']);
    }
}
