<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\DoffinImportSetting;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\Opportunity;
use App\Models\OpportunityNotice;
use App\Models\OpportunitySourceRecord;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\WatchProfile;
use App\Models\WatchProfileCpvCode;
use App\Models\WatchProfileInboxRecord;
use App\Services\BidWorkflowNotificationService;
use App\Services\Ted\TedSearchClient;
use App\Services\Ted\TedWatchProfileInboxDiscoveryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * The nightly sweep, against a second register.
 *
 * What these tests are really about is that "still open" had to be established differently. Doffin
 * says ACTIVE; TED says nothing at all, so a contract notice published three months ago looks
 * exactly like one published this morning unless the deadline is read. Getting that wrong does not
 * produce an error — it produces a watch inbox that slowly fills with procurements nobody can bid
 * on, which is worse, because it is quiet.
 *
 * Everything else is deliberately the same as Doffin's sweep: the same profiles, the same scoring,
 * the same inbox, the same one-notification-per-new-record rule. A TED record is a record.
 *
 * TED is driven through a mocked client throughout. Nothing here touches the network.
 */
class TedWatchProfileInboxDiscoveryTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-30 02:00:00'));

        config([
            'ted.watch_discovery.enabled' => true,
            'ted.watch_discovery.window_days' => 1,
            'ted.watch_discovery.per_page' => 50,
            'ted.watch_discovery.max_pages' => 4,
        ]);

        $this->enableWatchDiscoveryAdminToggle();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();

        parent::tearDown();
    }

    private function enableWatchDiscoveryAdminToggle(): void
    {
        $setting = DoffinImportSetting::query()->first();

        if ($setting instanceof DoffinImportSetting) {
            $setting->update(['watch_inbox_discovery_enabled' => true]);

            return;
        }

        DoffinImportSetting::query()->create([
            'scheduled_import_enabled' => false,
            'watch_inbox_discovery_enabled' => true,
        ]);
    }

    /**
     * @param  array<int, string>  $keywords
     * @param  array<int, array{cpv_code: string, weight: int}>  $cpvCodes
     */
    private function watchProfile(array $keywords = ['renhold'], array $cpvCodes = []): WatchProfile
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);
        $customer = Customer::query()->create([
            'name' => 'TED Watch AS',
            'slug' => 'ted-watch-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
        $user = User::query()->create([
            'name' => 'Watch Owner',
            'email' => 'ted-watch-'.Str::uuid().'@procynia.test',
            'password' => bcrypt('secret-only-local'),
            'customer_id' => $customer->id,
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'is_active' => true,
        ]);

        $profile = WatchProfile::query()->create([
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'name' => 'Renhold i EU',
            'keywords' => $keywords,
            'is_active' => true,
        ]);

        foreach ($cpvCodes as $rule) {
            WatchProfileCpvCode::query()->create([
                'watch_profile_id' => $profile->id,
                'cpv_code' => $rule['cpv_code'],
                'weight' => $rule['weight'],
            ]);
        }

        return $profile;
    }

    /**
     * A trimmed TED hit in the shape the live API actually returns: multilingual maps, a list of
     * deadlines, a date carrying an offset.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function tedHit(array $overrides = []): array
    {
        return array_merge([
            'publication-number' => '653637-2026',
            'notice-type' => 'cn-standard',
            'notice-title' => ['eng' => 'Framework agreement for cleaning services'],
            'description-proc' => ['eng' => 'Renhold av lokaler i Oslo og omegn.'],
            'buyer-name' => ['eng' => ['Oslo kommune']],
            'publication-date' => '2026-09-29+02:00',
            'deadline-receipt-request' => ['2026-10-30+02:00'],
            'classification-cpv' => ['90910000'],
            'links' => ['htmlDirect' => ['ENG' => 'https://ted.europa.eu/en/notice/653637-2026/html']],
        ], $overrides);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function tedResponse(array $items, ?int $total = null): array
    {
        return [
            'ok' => true,
            'items' => $items,
            'page' => 1,
            'perPage' => 50,
            'numHitsTotal' => $total ?? count($items),
            'numHitsAccessible' => $total ?? count($items),
            'fallback_used' => false,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, string>|null  $capturedQueries
     */
    private function mockTed(array $items, ?int $total = null, ?array &$capturedQueries = null, ?int $expectedCalls = null): void
    {
        $capturedQueries = [];
        $client = Mockery::mock(TedSearchClient::class);
        $expectation = $client->shouldReceive('search');

        if ($expectedCalls !== null) {
            $expectation->times($expectedCalls);
        }

        $expectation->andReturnUsing(function (string $query) use ($items, $total, &$capturedQueries): array {
            $capturedQueries[] = $query;

            return $this->tedResponse($items, $total);
        });

        $this->app->instance(TedSearchClient::class, $client);
    }

    private function discovery(): TedWatchProfileInboxDiscoveryService
    {
        return app(TedWatchProfileInboxDiscoveryService::class);
    }

    // ------------------------------------------------------------------ the record

    public function test_a_ted_hit_becomes_an_inbox_record_under_its_own_source(): void
    {
        $profile = $this->watchProfile();
        $this->mockTed([$this->tedHit()]);

        $summary = $this->discovery()->run($profile->id, 'scheduler');

        $this->assertSame('success', $summary['status']);
        $this->assertSame(1, $summary['profiles_processed']);
        $this->assertSame(1, $summary['records_created']);

        $record = WatchProfileInboxRecord::query()->where('watch_profile_id', $profile->id)->sole();

        $this->assertSame('ted', $record->source);
        $this->assertSame('653637-2026', $record->external_id);
        // The legacy column names Doffin, and a TED notice was never in Doffin.
        $this->assertNull($record->doffin_notice_id);
        $this->assertSame('Framework agreement for cleaning services', $record->title);
        $this->assertSame('Oslo kommune', $record->buyer_name);
        $this->assertSame('https://ted.europa.eu/en/notice/653637-2026/html', $record->external_url);
        $this->assertSame('2026-10-30', $record->deadline->toDateString());
        // The same scoring as Doffin's sweep: the keyword is in the description.
        $this->assertSame(20, $record->relevance_score);
    }

    /**
     * The sweep writes down which procurement a hit is, because TED already told it.
     *
     * Free here and impossible for Doffin: TED returns both eForms identifiers in the search
     * result, so no extra request is made, and none may be — a nightly sweep that asked a register
     * one question per hit is the cost this whole design refused to take on.
     */
    public function test_a_ted_hit_registers_the_procurement_it_belongs_to(): void
    {
        $profile = $this->watchProfile();
        $this->mockTed([$this->tedHit([
            'procedure-identifier' => '19379be5-7821-4761-b6bf-30876e2f678e',
            'notice-identifier' => '50859edc-926f-405a-a1ad-6590a08a5ba9',
        ])]);

        $this->discovery()->run($profile->id, 'scheduler');

        $record = OpportunitySourceRecord::query()->where('external_id', '653637-2026')->sole();

        $this->assertSame('ted', $record->source);
        $this->assertSame(
            '19379be5-7821-4761-b6bf-30876e2f678e',
            Opportunity::query()->whereKey($record->opportunity_id)->value('procedure_identifier'),
        );
        $this->assertSame(
            '50859edc-926f-405a-a1ad-6590a08a5ba9',
            OpportunityNotice::query()->whereKey($record->opportunity_notice_id)->value('notice_identifier'),
        );
    }

    /** A hit TED did not identify is an inbox record and nothing more. */
    public function test_a_ted_hit_without_identifiers_registers_no_procurement(): void
    {
        $profile = $this->watchProfile();
        $this->mockTed([$this->tedHit()]);

        $this->discovery()->run($profile->id, 'scheduler');

        $this->assertSame(1, WatchProfileInboxRecord::query()->where('watch_profile_id', $profile->id)->count());
        $this->assertSame(0, OpportunitySourceRecord::query()->where('external_id', '653637-2026')->count());
    }

    /** CPV rules score a TED hit the same way they score a Doffin one. */
    public function test_a_cpv_match_scores_the_same_as_it_would_from_doffin(): void
    {
        $profile = $this->watchProfile(['renhold'], [['cpv_code' => '90910000', 'weight' => 25]]);
        $this->mockTed([$this->tedHit()]);

        $this->discovery()->run($profile->id);

        // 20 for the keyword, 25 for the CPV rule, 10 because both kinds matched.
        $this->assertSame(55, WatchProfileInboxRecord::query()->where('watch_profile_id', $profile->id)->sole()->relevance_score);
    }

    public function test_a_hit_matching_nothing_in_the_profile_is_not_stored(): void
    {
        $profile = $this->watchProfile(['snøbrøyting']);
        $this->mockTed([$this->tedHit()]);

        $summary = $this->discovery()->run($profile->id);

        $this->assertSame(0, $summary['records_created']);
        $this->assertSame(0, WatchProfileInboxRecord::query()->where('watch_profile_id', $profile->id)->count());
    }

    // ------------------------------------------------------------------ eligibility

    public function test_a_future_deadline_is_still_open(): void
    {
        $profile = $this->watchProfile();
        $this->mockTed([$this->tedHit(['deadline-receipt-request' => ['2026-10-01+02:00']])]);

        $this->assertSame(1, $this->discovery()->run($profile->id)['records_created']);
    }

    /** Today is not over, and TED gives the day rather than the hour. */
    public function test_a_deadline_falling_today_is_still_open(): void
    {
        $profile = $this->watchProfile();
        $this->mockTed([$this->tedHit(['deadline-receipt-request' => ['2026-09-30+02:00']])]);

        $this->assertSame(1, $this->discovery()->run($profile->id)['records_created']);
    }

    public function test_a_passed_deadline_is_not_a_watch_hit(): void
    {
        $profile = $this->watchProfile();
        $this->mockTed([$this->tedHit(['deadline-receipt-request' => ['2026-09-29+02:00']])]);

        $this->assertSame(0, $this->discovery()->run($profile->id)['records_created']);
    }

    /** One deadline per lot; the earliest is the one that actually constrains a bidder. */
    public function test_the_earliest_lot_deadline_decides(): void
    {
        $profile = $this->watchProfile();
        $this->mockTed([$this->tedHit(['deadline-receipt-request' => ['2026-09-20+02:00', '2026-11-20+02:00']])]);

        $this->assertSame(0, $this->discovery()->run($profile->id)['records_created']);
    }

    /**
     * The conservative case, chosen deliberately.
     *
     * A contract notice with no readable deadline could be open or could have closed last spring,
     * and TED has no third field to ask. It is excluded, because a watch inbox entry is a claim
     * that somebody can still bid — and the exclusion is logged, so a register that routinely
     * omits deadlines is something an operator can discover rather than a silence.
     */
    public function test_a_contract_notice_without_a_readable_deadline_is_excluded(): void
    {
        $profile = $this->watchProfile();
        $this->mockTed([$this->tedHit(['deadline-receipt-request' => []])]);

        $this->assertSame(0, $this->discovery()->run($profile->id)['records_created']);
    }

    /**
     * Never Open by default. An award notice records that somebody already won, and a prior
     * information notice announces something not yet open — neither is a live call for offers,
     * and neither is a document kind Procynia has not seen before.
     */
    public function test_only_a_contract_notice_can_be_a_watch_hit(): void
    {
        foreach (['can-standard', 'pin-buyer', 'something-new', ''] as $noticeType) {
            $profile = $this->watchProfile();
            $this->mockTed([$this->tedHit(['notice-type' => $noticeType])]);

            $this->assertSame(
                0,
                $this->discovery()->run($profile->id)['records_created'],
                $noticeType === '' ? 'a notice with no type' : $noticeType,
            );
        }
    }

    public function test_a_notice_published_before_the_window_is_not_a_watch_hit(): void
    {
        $profile = $this->watchProfile();
        $this->mockTed([$this->tedHit(['publication-date' => '2026-09-27+02:00'])]);

        $this->assertSame(0, $this->discovery()->run($profile->id)['records_created']);
    }

    // ------------------------------------------------------------------ search volume

    /**
     * The sweep asks TED about this profile, for one day, and nothing wider.
     *
     * Every clause matters for volume: without them the query is "everything the union published",
     * which TED will happily start paging out.
     */
    public function test_the_query_is_narrowed_to_the_profile_and_one_day(): void
    {
        $profile = $this->watchProfile(['renhold', 'vakthold'], [['cpv_code' => '90910000', 'weight' => 10]]);
        $this->mockTed([$this->tedHit()], null, $queries);

        $this->discovery()->run($profile->id);

        $this->assertCount(1, $queries);
        $this->assertStringContainsString('(FT~"renhold" OR FT~"vakthold")', $queries[0]);
        $this->assertStringContainsString('classification-cpv IN (90910000)', $queries[0]);
        $this->assertStringContainsString('publication-date>=20260929', $queries[0]);
        $this->assertStringContainsString('notice-type~"cn-"', $queries[0]);
    }

    /**
     * A profile that names nothing is not a search against TED.
     *
     * Against Doffin it has always meant "everything published in Norway yesterday", scored to
     * zero and stored nowhere. Against the whole union it is a request nobody made.
     */
    public function test_a_profile_with_no_keywords_or_cpv_codes_is_never_asked(): void
    {
        $profile = $this->watchProfile([]);
        $this->mockTed([$this->tedHit()], null, $queries, 0);

        $summary = $this->discovery()->run($profile->id);

        $this->assertSame(1, $summary['profiles_processed']);
        $this->assertSame(0, $summary['records_seen']);
        $this->assertSame([], $queries);
    }

    /** A profile whose query is too broad is truncated rather than paged through all night. */
    public function test_one_profile_may_not_page_through_the_whole_union(): void
    {
        config(['ted.watch_discovery.max_pages' => 2]);

        $profile = $this->watchProfile();
        $this->mockTed([$this->tedHit()], 10000, $queries, 2);

        $summary = $this->discovery()->run($profile->id);

        $this->assertCount(2, $queries);
        // The same notice on both pages: one record, seen twice.
        $this->assertSame(1, $summary['records_created']);
        $this->assertSame(1, $summary['records_updated']);
    }

    // ------------------------------------------------------------------ identity and alerts

    /** The sweep is idempotent: tomorrow night's re-sighting updates, it does not duplicate. */
    public function test_re_discovery_updates_the_record_rather_than_duplicating_it(): void
    {
        $profile = $this->watchProfile();
        $this->mockTed([$this->tedHit()]);

        $first = $this->discovery()->run($profile->id);

        Carbon::setTestNow(Carbon::parse('2026-09-30 04:00:00'));

        $second = $this->discovery()->run($profile->id);

        $this->assertSame(1, $first['records_created']);
        $this->assertSame(0, $second['records_created']);
        $this->assertSame(1, $second['records_updated']);

        $record = WatchProfileInboxRecord::query()->where('watch_profile_id', $profile->id)->sole();

        $this->assertTrue($record->discovered_at->equalTo(Carbon::parse('2026-09-30 02:00:00')));
        $this->assertTrue($record->last_seen_at->equalTo(Carbon::parse('2026-09-30 04:00:00')));
    }

    /**
     * One alert per new opportunity, and none for seeing it again — the rule that keeps a standing
     * watch from becoming a daily alarm, now proved for a second register.
     */
    public function test_a_new_ted_match_notifies_once_and_a_re_sighting_not_at_all(): void
    {
        $profile = $this->watchProfile();
        $this->mockTed([$this->tedHit()]);

        $this->discovery()->run($profile->id);
        $this->discovery()->run($profile->id);

        $notifications = UserNotification::query()
            ->where('user_id', $profile->user_id)
            ->where('event_type', BidWorkflowNotificationService::EVENT_WATCH_PROFILE_MATCH)
            ->get();

        $this->assertCount(1, $notifications);
        $this->assertSame('ted', $notifications->first()->metadata['source']);
        $this->assertSame('653637-2026', $notifications->first()->metadata['external_id']);
        // The dedupe key carries the source, so a Doffin notice of the same number cannot collide.
        $this->assertSame(
            sprintf('watch_profile.match_found:%d:ted/653637-2026:%d', $profile->id, $profile->user_id),
            $notifications->first()->dedupe_key,
        );
    }

    /**
     * The point of every source-aware phase before this one, now reached by the nightly sweep:
     * one publication number, two registers, two records in the same inbox.
     */
    public function test_the_same_external_id_from_doffin_is_a_separate_record(): void
    {
        $profile = $this->watchProfile();

        WatchProfileInboxRecord::query()->create([
            'watch_profile_id' => $profile->id,
            'customer_id' => $profile->customer_id,
            'user_id' => $profile->user_id,
            'source' => 'doffin',
            'external_id' => '653637-2026',
            'doffin_notice_id' => '653637-2026',
            'title' => 'En helt annen kunngjøring fra Doffin',
            'discovered_at' => now(),
        ]);

        $this->mockTed([$this->tedHit()]);

        $this->assertSame(1, $this->discovery()->run($profile->id)['records_created']);

        $records = WatchProfileInboxRecord::query()
            ->where('watch_profile_id', $profile->id)
            ->where('external_id', '653637-2026')
            ->orderBy('source')
            ->get();

        $this->assertCount(2, $records);
        $this->assertSame(['doffin', 'ted'], $records->pluck('source')->all());
        $this->assertSame('En helt annen kunngjøring fra Doffin', $records->first()->title);
    }

    // ------------------------------------------------------------------ the switch

    /**
     * Off until somebody turns it on.
     *
     * Doffin's sweep covers one country; this one covers twenty-seven, and enabling it for every
     * existing watch profile on deployment day would change what real people find in their inbox
     * tomorrow morning without anybody having decided to.
     */
    public function test_the_sweep_is_skipped_until_the_source_is_enabled(): void
    {
        config(['ted.watch_discovery.enabled' => false]);

        $profile = $this->watchProfile();
        $this->mockTed([$this->tedHit()], null, $queries, 0);

        $summary = $this->discovery()->run($profile->id);

        $this->assertSame('skipped', $summary['status']);
        $this->assertSame('source_disabled', $summary['skip_reason']);
        $this->assertSame(0, $summary['profiles_processed']);
        $this->assertSame([], $queries);
    }

    /** And the operator's master switch for nightly discovery still means what it says. */
    public function test_the_admin_switch_for_watch_discovery_stops_ted_too(): void
    {
        DoffinImportSetting::query()->first()?->update(['watch_inbox_discovery_enabled' => false]);

        $profile = $this->watchProfile();
        $this->mockTed([$this->tedHit()], null, $queries, 0);

        $summary = $this->discovery()->run($profile->id);

        $this->assertSame('skipped', $summary['status']);
        $this->assertSame('admin_disabled', $summary['skip_reason']);
        $this->assertSame([], $queries);
    }
}
