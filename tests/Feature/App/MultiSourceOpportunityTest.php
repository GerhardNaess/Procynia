<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\Opportunity;
use App\Models\OpportunityNotice;
use App\Models\OpportunitySourceRecord;
use App\Models\SavedNotice;
use App\Models\User;
use App\Services\Doffin\DoffinLiveSearchService;
use App\Services\Doffin\DoffinSourceAdapter;
use App\Services\OpportunitySources\NormalizedNotice;
use App\Services\OpportunitySources\OpportunityRegistrar;
use App\Services\Ted\TedSearchClient;
use App\Services\Ted\TedSourceAdapter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * One procurement, two registers, one case.
 *
 * Doffin's 2026-113736 and TED's 599740-2026 are the same annual inspection contract for
 * St. Olavs. Until now Procynia had no way to say so: a saved case was identified by the register
 * record it was saved from, so a bid manager who found the tender twice got two cases, two sets of
 * requirements and two half-finished bids for one deadline.
 *
 * What makes the join safe is that it is not a judgement. Both registers publish the eForms UUIDs
 * and publish them identically, and every identifier in this file was read from the live APIs on
 * 30 September 2026 and checked against the other register's record. Nothing here matches on a
 * title, a buyer, a CPV code or a deadline — Phase 5A found a buyer with two nearly identically
 * titled winter maintenance tenders, same CPV, genuinely different procurements, which is exactly
 * what a heuristic would have merged.
 */
class MultiSourceOpportunityTest extends TestCase
{
    use DatabaseTransactions;

    /** Sykehusinnkjøp, annual inspection for St. Olavs: Doffin 2026-113736 / TED 599740-2026. */
    private const PROCEDURE = '19379be5-7821-4761-b6bf-30876e2f678e';

    private const NOTICE = '50859edc-926f-405a-a1ad-6590a08a5ba9';

    private const DOFFIN_ID = '2026-113736';

    private const TED_ID = '599740-2026';

    /** Hamarøy kommune, winter road maintenance — a different procurement entirely. */
    private const OTHER_PROCEDURE = '7d02de59-3686-415d-a2a1-2fc26652e0d0';

    private const OTHER_NOTICE = 'ceaeb487-d3bb-42a4-9fcc-2eb4a3df55d8';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ fixtures

    private function registrar(): OpportunityRegistrar
    {
        return app(OpportunityRegistrar::class);
    }

    /** A TED search hit, normalised the way the nightly sweep normalises one. */
    private function tedNotice(string $publicationNumber, string $procedure, string $notice): NormalizedNotice
    {
        return (new TedSourceAdapter(Mockery::mock(TedSearchClient::class)))->normalizeLiveSearchHit([
            'publication-number' => $publicationNumber,
            'notice-title' => ['eng' => ['Norway – inspection services – Annual inspection']],
            'procedure-identifier' => $procedure,
            'notice-identifier' => $notice,
        ]);
    }

    /** A Doffin hit. The search endpoint returns no identifiers; a detail carries both. */
    private function doffinNotice(string $externalId, array $identity = []): NormalizedNotice
    {
        return (new DoffinSourceAdapter(Mockery::mock(DoffinLiveSearchService::class)))->normalizeLiveSearchHit([
            'id' => $externalId,
            'heading' => 'Årlig kontroll av sikkerhetsventilasjonskap',
            'status' => 'ACTIVE',
            ...$identity,
        ]);
    }

    /** Doffin's detail endpoint, answering for one notice id and nothing else. */
    private function fakeDoffinDetail(array $identitiesByNoticeId): void
    {
        $stubs = [];

        foreach ($identitiesByNoticeId as $noticeId => $identity) {
            $stubs['*notices-api/notices/'.$noticeId] = Http::response($identity, 200);
        }

        // Anything not named above is a notice Doffin has nothing to say about, which is the
        // ordinary answer for most of the register.
        $stubs['*'] = Http::response([], 200);

        Http::fake($stubs);
    }

    private function customer(string $name = 'Multi Source AS'): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => $name,
            'slug' => 'multi-source-'.Str::lower(Str::random(10)),
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
            'email' => 'multi-'.Str::uuid().'@procynia.test',
            'password' => bcrypt('secret-only-local'),
            'customer_id' => $customer->id,
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'is_active' => true,
        ]);
    }

    /** Press save on one register's record of a tender. */
    private function save(User $user, string $source, string $externalId, string $title): TestResponse
    {
        return $this->actingAs($user)
            ->from('/app/notices')
            ->post('/app/notices/save', [
                'source' => $source,
                'notice_id' => $externalId,
                'title' => $title,
                'buyer_name' => 'SYKEHUSINNKJØP HF',
                'status' => 'ACTIVE',
            ]);
    }

    /** @return Collection<int, SavedNotice> */
    private function casesFor(Customer $customer)
    {
        return SavedNotice::query()->where('customer_id', $customer->id)->orderBy('id')->get();
    }

    // ------------------------------------------------------------------ the identity layer

    public function test_doffin_and_ted_records_of_one_procurement_are_one_opportunity(): void
    {
        $registrar = $this->registrar();

        $fromTed = $registrar->register($this->tedNotice(self::TED_ID, self::PROCEDURE, self::NOTICE));
        $fromDoffin = $registrar->register($this->doffinNotice(self::DOFFIN_ID, [
            'procedureId' => self::PROCEDURE,
            'eFormId' => self::NOTICE,
        ]));

        $this->assertInstanceOf(Opportunity::class, $fromTed);
        $this->assertSame($fromTed->id, $fromDoffin?->id);
        $this->assertSame(1, Opportunity::query()->where('procedure_identifier', self::PROCEDURE)->count());
    }

    /**
     * The same document in two registers is two records of it — not two procurements, and not one
     * record that overwrote the other.
     */
    public function test_one_notice_in_two_registers_is_two_source_records(): void
    {
        $registrar = $this->registrar();
        $registrar->register($this->tedNotice(self::TED_ID, self::PROCEDURE, self::NOTICE));
        $opportunity = $registrar->register($this->doffinNotice(self::DOFFIN_ID, [
            'procedureId' => self::PROCEDURE,
            'eFormId' => self::NOTICE,
        ]));

        $records = OpportunitySourceRecord::query()->where('opportunity_id', $opportunity->id)->get();

        $this->assertCount(2, $records);
        $this->assertEqualsCanonicalizing(['doffin', 'ted'], $records->pluck('source')->all());
        $this->assertEqualsCanonicalizing([self::DOFFIN_ID, self::TED_ID], $records->pluck('external_id')->all());
        // One document, so both records point at the same row.
        $this->assertCount(1, $records->pluck('opportunity_notice_id')->unique());
        $this->assertSame(1, OpportunityNotice::query()->where('notice_identifier', self::NOTICE)->count());
    }

    /**
     * A procurement publishes a call, changes to it and an award. They are different documents and
     * the same opportunity — which is the reason both identifiers exist rather than one.
     */
    public function test_several_notices_under_one_procurement_stay_one_opportunity(): void
    {
        $registrar = $this->registrar();

        $call = $registrar->register($this->tedNotice('333255-2026', self::PROCEDURE, self::NOTICE));
        $award = $registrar->register($this->tedNotice('600064-2026', self::PROCEDURE, '6835ebe3-ce0f-43a2-b3eb-5d49e61baea7'));

        $this->assertSame($call->id, $award->id);
        $this->assertSame(2, OpportunityNotice::query()->where('opportunity_id', $call->id)->count());
        $this->assertSame(2, OpportunitySourceRecord::query()->where('opportunity_id', $call->id)->count());
    }

    /**
     * Two notices that both say nothing are not thereby the same notice. This is the single most
     * damaging merge available, so it is the one the layer refuses outright.
     */
    public function test_a_register_that_said_nothing_registers_nothing(): void
    {
        $registrar = $this->registrar();

        $before = Opportunity::query()->count();
        $first = $registrar->register($this->doffinNotice('2026-900001'));
        $second = $registrar->register($this->doffinNotice('2026-900002'));

        $this->assertNull($first);
        $this->assertNull($second);
        $this->assertSame($before, Opportunity::query()->count());
        $this->assertSame(0, OpportunitySourceRecord::query()->whereIn('external_id', ['2026-900001', '2026-900002'])->count());
    }

    /**
     * The nightly sweep registers what it was handed and asks nobody anything.
     *
     * This is the boundary Phase 5C drew and this phase has to keep: TED puts both identifiers in
     * its search results, Doffin puts neither, and a sweep over thousands of Doffin hits must not
     * quietly become thousands of detail requests. preventStrayRequests() makes any HTTP call at
     * all fail the test rather than merely be slow.
     */
    public function test_registering_a_discovered_notice_never_calls_a_register(): void
    {
        Http::preventStrayRequests();
        Http::fake([]);

        $registrar = $this->registrar();
        $registrar->register($this->tedNotice(self::TED_ID, self::PROCEDURE, self::NOTICE));
        $registrar->register($this->doffinNotice(self::DOFFIN_ID));

        // TED's record is registered from what it said; Doffin's is not registered at all.
        $this->assertSame(1, OpportunitySourceRecord::query()->where('external_id', self::TED_ID)->count());
        $this->assertSame(0, OpportunitySourceRecord::query()->where('external_id', self::DOFFIN_ID)->count());
    }

    /** Saving is the one moment a single notice is worth a request — and only the first time. */
    public function test_a_doffin_lookup_happens_once_per_record(): void
    {
        $this->fakeDoffinDetail([
            self::DOFFIN_ID => ['procedureId' => self::PROCEDURE, 'eFormId' => self::NOTICE],
        ]);

        $registrar = $this->registrar();
        $first = $registrar->resolveWithLookup('doffin', self::DOFFIN_ID);
        $second = $registrar->resolveWithLookup('doffin', self::DOFFIN_ID);

        $this->assertSame($first->id, $second->id);
        Http::assertSentCount(1);
    }

    // ------------------------------------------------------------------ one case per procurement

    public function test_saving_doffin_and_then_ted_gives_one_case(): void
    {
        $this->fakeDoffinDetail([
            self::DOFFIN_ID => ['procedureId' => self::PROCEDURE, 'eFormId' => self::NOTICE],
        ]);
        // TED's record is already known, the way the nightly sweep knows it.
        $this->registrar()->register($this->tedNotice(self::TED_ID, self::PROCEDURE, self::NOTICE));

        $customer = $this->customer();
        $user = $this->user($customer);

        $this->save($user, 'doffin', self::DOFFIN_ID, 'Årlig kontroll av sikkerhetsventilasjonskap')->assertRedirect();
        $this->save($user, 'ted', self::TED_ID, 'Norway – inspection services')->assertRedirect();

        $cases = $this->casesFor($customer);

        $this->assertCount(1, $cases);
        $this->assertSame(self::DOFFIN_ID, $cases->first()->external_id);
        $this->assertNotNull($cases->first()->opportunity_id);
        // The case keeps the register record it was built from; the second register is recorded as
        // provenance, which is how Procynia knows the tender exists in both.
        $this->assertSame('doffin', $cases->first()->source);
        $this->assertSame('Årlig kontroll av sikkerhetsventilasjonskap', $cases->first()->title);
        $this->assertSame(2, OpportunitySourceRecord::query()
            ->where('opportunity_id', $cases->first()->opportunity_id)
            ->count());
    }

    public function test_saving_ted_and_then_doffin_gives_one_case(): void
    {
        $this->fakeDoffinDetail([
            self::DOFFIN_ID => ['procedureId' => self::PROCEDURE, 'eFormId' => self::NOTICE],
        ]);
        $this->registrar()->register($this->tedNotice(self::TED_ID, self::PROCEDURE, self::NOTICE));

        $customer = $this->customer();
        $user = $this->user($customer);

        $this->save($user, 'ted', self::TED_ID, 'Norway – inspection services')->assertRedirect();
        $this->save($user, 'doffin', self::DOFFIN_ID, 'Årlig kontroll av sikkerhetsventilasjonskap')->assertRedirect();

        $cases = $this->casesFor($customer);

        $this->assertCount(1, $cases);
        $this->assertSame(self::TED_ID, $cases->first()->external_id);
        $this->assertSame('ted', $cases->first()->source);
        $this->assertSame('Norway – inspection services', $cases->first()->title);
    }

    public function test_two_procurements_are_two_cases(): void
    {
        $this->fakeDoffinDetail([
            self::DOFFIN_ID => ['procedureId' => self::PROCEDURE, 'eFormId' => self::NOTICE],
            '2026-102030' => ['procedureId' => self::OTHER_PROCEDURE, 'eFormId' => self::OTHER_NOTICE],
        ]);

        $customer = $this->customer();
        $user = $this->user($customer);

        $this->save($user, 'doffin', self::DOFFIN_ID, 'Årlig kontroll')->assertRedirect();
        $this->save($user, 'doffin', '2026-102030', 'Vintervedlikehold, veier')->assertRedirect();

        $cases = $this->casesFor($customer);

        $this->assertCount(2, $cases);
        $this->assertNotSame($cases[0]->opportunity_id, $cases[1]->opportunity_id);
    }

    /**
     * A record Procynia cannot identify behaves exactly as it did before this phase existed:
     * found by (customer_id, source, external_id), saved once, updated on the next save.
     */
    public function test_a_record_without_identity_keeps_the_source_aware_behaviour(): void
    {
        $this->fakeDoffinDetail([]);

        $customer = $this->customer();
        $user = $this->user($customer);

        $this->save($user, 'doffin', '2026-900500', 'Uten identitet')->assertRedirect();
        $this->save($user, 'doffin', '2026-900500', 'Uten identitet, oppdatert')->assertRedirect();

        $cases = $this->casesFor($customer);

        $this->assertCount(1, $cases);
        $this->assertNull($cases->first()->opportunity_id);
        $this->assertSame('doffin', $cases->first()->source);
        $this->assertSame('Uten identitet, oppdatert', $cases->first()->title);
    }

    /** A procurement is public; a case is not. Two customers working on one tender is two cases. */
    public function test_one_procurement_saved_by_two_customers_is_two_cases(): void
    {
        $this->fakeDoffinDetail([
            self::DOFFIN_ID => ['procedureId' => self::PROCEDURE, 'eFormId' => self::NOTICE],
        ]);

        $first = $this->customer('First Bidder AS');
        $second = $this->customer('Second Bidder AS');

        $this->save($this->user($first), 'doffin', self::DOFFIN_ID, 'Årlig kontroll')->assertRedirect();
        $this->save($this->user($second), 'doffin', self::DOFFIN_ID, 'Årlig kontroll')->assertRedirect();

        $this->assertCount(1, $this->casesFor($first));
        $this->assertCount(1, $this->casesFor($second));
        $this->assertNotSame($this->casesFor($first)->first()->id, $this->casesFor($second)->first()->id);
        // One procurement, one identity row, two customers' work hanging off it.
        $this->assertSame(
            $this->casesFor($first)->first()->opportunity_id,
            $this->casesFor($second)->first()->opportunity_id,
        );
    }

    // ------------------------------------------------------------------ the migration

    /**
     * Reversed and re-applied inside the test transaction, which is the honest way to see what a
     * real `migrate` and `migrate:rollback` do — Postgres rolls DDL back with everything else, so
     * nothing here survives the test.
     */
    public function test_the_migration_reverses_and_reapplies(): void
    {
        $identityTables = require database_path('migrations/2026_09_30_000001_create_opportunity_identity_tables.php');
        $savedNoticeLink = require database_path('migrations/2026_09_30_000002_add_opportunity_to_saved_notices_table.php');

        $savedNoticeLink->down();
        $identityTables->down();

        $this->assertFalse(Schema::hasTable('opportunities'));
        $this->assertFalse(Schema::hasTable('opportunity_notices'));
        $this->assertFalse(Schema::hasTable('opportunity_source_records'));
        $this->assertFalse(Schema::hasColumn('saved_notices', 'opportunity_id'));
        // What the rest of Procynia runs on is untouched by the reversal.
        $this->assertTrue(Schema::hasColumn('saved_notices', 'source'));
        $this->assertTrue(Schema::hasColumn('saved_notices', 'external_id'));
        $this->assertTrue(Schema::hasTable('notice_sources'));

        $identityTables->up();
        $savedNoticeLink->up();

        $this->assertTrue(Schema::hasTable('opportunities'));
        $this->assertTrue(Schema::hasTable('opportunity_notices'));
        $this->assertTrue(Schema::hasTable('opportunity_source_records'));
        $this->assertTrue(Schema::hasColumn('saved_notices', 'opportunity_id'));
    }

    /** Nothing is invented for the rows that were already there. */
    public function test_an_existing_case_is_left_without_an_identity(): void
    {
        $customer = $this->customer();
        $user = $this->user($customer);

        $legacy = SavedNotice::query()->create([
            'customer_id' => $customer->id,
            'source' => 'doffin',
            'external_id' => '2026-700001',
            'title' => 'Lagret før identitet fantes',
            'source_type' => SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE,
            'bid_status' => SavedNotice::BID_STATUS_DISCOVERED,
            'saved_by_user_id' => $user->id,
        ]);

        $identityTables = require database_path('migrations/2026_09_30_000001_create_opportunity_identity_tables.php');
        $savedNoticeLink = require database_path('migrations/2026_09_30_000002_add_opportunity_to_saved_notices_table.php');
        $savedNoticeLink->down();
        $identityTables->down();
        $identityTables->up();
        $savedNoticeLink->up();

        $this->assertNull($legacy->fresh()->opportunity_id);
        $this->assertSame('doffin', $legacy->fresh()->source);
        $this->assertSame('2026-700001', $legacy->fresh()->external_id);
        $this->assertSame(0, Opportunity::query()->count());
    }
}
