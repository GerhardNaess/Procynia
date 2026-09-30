<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\Opportunity;
use App\Models\OpportunitySourceRecord;
use App\Models\SavedNotice;
use App\Models\User;
use App\Services\Doffin\DoffinLiveSearchService;
use App\Services\Doffin\DoffinSourceAdapter;
use App\Services\OpportunitySources\OpportunitySourceRegistry;
use App\Services\Ted\TedSearchClient;
use App\Services\Ted\TedSourceAdapter;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * What one procurement in two registers looks like to the person using Procynia.
 *
 * Phase 5D made Doffin's 2026-113736 and TED's 599740-2026 one procurement in the database. On
 * screen they were still two tenders: a bid manager who had worked the Doffin record for a month
 * was offered the TED record as something new, and the case said nothing about the second register
 * holding the same competition under a different reference number.
 *
 * Both ends of that are here. A hit is already saved when the customer has a case for its
 * procurement, whichever register opened it; and a case says which registers publish it. Nothing
 * is merged — the case keeps its own title, deadline and documents, and the second register is
 * recorded beside it rather than over it.
 *
 * Every identifier below was read from the live Doffin and TED APIs on 30 September 2026. Nothing
 * here touches the network: both search clients are mocked and Doffin's detail endpoint is faked.
 */
class MultiSourceOpportunityUiTest extends TestCase
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

    private const DOFFIN_TITLE = 'Årlig kontroll av sikkerhetsventilasjonskap til St. Olavs hospital';

    private const TED_TITLE = 'Norway – Inspection services – Annual inspection of safety cabinets';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->fakeDoffinDetail([]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ fixtures

    /**
     * Doffin's detail endpoint, answering for the notice ids named and nothing else.
     *
     * An empty body is the ordinary answer for most of the register — a notice whose identifiers
     * Doffin does not publish — and it is what keeps an unnamed id from accidentally matching.
     */
    private function fakeDoffinDetail(array $identitiesByNoticeId): void
    {
        // Http::fake() adds to whatever was stubbed before it rather than replacing it, and the
        // oldest matching stub wins — so a catch-all registered earlier would answer for every
        // notice id named here. Dropping the factory first is what makes a second call mean what
        // it says.
        $this->app->forgetInstance(Factory::class);
        Http::clearResolvedInstances();

        $stubs = [];

        foreach ($identitiesByNoticeId as $noticeId => $identity) {
            $stubs['*notices-api/notices/'.$noticeId] = Http::response($identity, 200);
        }

        $stubs['*'] = Http::response([], 200);

        Http::fake($stubs);
    }

    /**
     * How many notices this run asked Doffin to identify.
     *
     * Counted by URL rather than with assertSentCount(), because the page render itself reaches
     * Inertia's SSR server over the same faked client and that request is not the subject here.
     */
    private function assertDoffinLookups(int $expected, string $message = ''): void
    {
        $lookups = Http::recorded(
            fn (Request $request): bool => str_contains($request->url(), 'notices-api/notices/'),
        );

        $this->assertCount($expected, $lookups, $message);
    }

    /**
     * Both registers' clients, bound before this test's first request.
     *
     * Before, not between: Laravel caches the controller instance on the matched route, so a
     * controller built for the first request keeps the registry it was given for every request
     * after it. A mock bound halfway through a test is therefore a mock the controller never sees,
     * and the register it stands in for would be called for real.
     *
     * @param  array<int, array<string, mixed>>  $doffinItems
     * @param  array<int, array<string, mixed>>  $tedItems
     */
    private function mockRegisters(array $doffinItems = [], array $tedItems = []): void
    {
        $doffin = Mockery::mock(DoffinLiveSearchService::class);
        $doffin->shouldReceive('search')->andReturn([
            'ok' => true,
            'items' => $doffinItems,
            'page' => 1,
            'perPage' => 50,
            'numHitsTotal' => count($doffinItems),
            'numHitsAccessible' => count($doffinItems),
        ]);

        $ted = Mockery::mock(TedSearchClient::class);
        $ted->shouldReceive('search')->andReturn([
            'ok' => true,
            'items' => $tedItems,
            'page' => 1,
            'perPage' => 50,
            'numHitsTotal' => count($tedItems),
            'numHitsAccessible' => count($tedItems),
            'fallback_used' => false,
        ]);

        $this->app->instance(DoffinLiveSearchService::class, $doffin);
        $this->app->instance(TedSearchClient::class, $ted);
        $this->forgetResolvedSources();
    }

    /** The registry is a singleton holding built adapters; a mock bound now must replace them. */
    private function forgetResolvedSources(): void
    {
        $this->app->forgetInstance(OpportunitySourceRegistry::class);
        $this->app->forgetInstance(TedSourceAdapter::class);
        $this->app->forgetInstance(DoffinSourceAdapter::class);
    }

    /** @return array<string, mixed> one Doffin search hit, with whatever identifiers are given */
    private function doffinHit(string $externalId, array $identity = []): array
    {
        return [
            'id' => $externalId,
            'heading' => self::DOFFIN_TITLE,
            'status' => 'ACTIVE',
            'publicationDate' => '2026-09-01',
            ...$identity,
        ];
    }

    /** @return array<string, mixed> one TED search hit; TED names its procurements for free */
    private function tedHit(string $publicationNumber, ?string $procedure = self::PROCEDURE, ?string $notice = self::NOTICE): array
    {
        return array_filter([
            'publication-number' => $publicationNumber,
            'notice-type' => 'cn-standard',
            'notice-title' => ['eng' => self::TED_TITLE],
            'publication-date' => '2026-09-01+02:00',
            'procedure-identifier' => $procedure,
            'notice-identifier' => $notice,
        ], fn (mixed $value): bool => $value !== null);
    }

    private function customer(string $name = 'Multi Source AS'): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        return Customer::query()->create([
            'name' => $name,
            'slug' => 'multi-ui-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function user(?Customer $customer = null): User
    {
        $customer ??= $this->customer();

        return User::query()->create([
            'name' => 'Bid Manager',
            'email' => 'multi-ui-'.Str::uuid().'@procynia.test',
            'password' => bcrypt('secret-only-local'),
            'customer_id' => $customer->id,
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'is_active' => true,
        ]);
    }

    /** @return array<string, mixed> the props the discovery page was rendered with */
    private function liveSearch(User $user, string $source): array
    {
        return $this->actingAs($user)
            ->get('/app/notices?'.http_build_query(['mode' => 'live', 'source' => $source]))
            ->assertOk()
            ->viewData('page')['props'];
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

    /** @return array<string, mixed> the case payload the case page was rendered with */
    private function casePayload(User $user, SavedNotice $case): array
    {
        return $this->actingAs($user)
            ->get(route('app.notices.saved.show', ['savedNotice' => $case->id]))
            ->assertOk()
            ->viewData('page')['props']['notice'];
    }

    /** The one case this customer has, so a second one fails loudly rather than being picked. */
    private function onlyCase(Customer $customer): SavedNotice
    {
        $cases = SavedNotice::query()->where('customer_id', $customer->id)->get();

        $this->assertCount(1, $cases, 'one procurement must not become two cases');

        return $cases->first();
    }

    /** The identity Doffin's detail endpoint holds for the tender used throughout. */
    private function doffinIdentity(): array
    {
        return [self::DOFFIN_ID => ['procedureId' => self::PROCEDURE, 'eFormId' => self::NOTICE]];
    }

    /**
     * Work a Doffin case up to the point where TED would be the second register.
     *
     * The detail request here is the one Doffin lookup the whole flow costs: saving is the moment
     * a single notice matters enough to pay for an identity.
     */
    private function doffinCase(User $user): SavedNotice
    {
        $this->fakeDoffinDetail($this->doffinIdentity());
        $this->save($user, DoffinSourceAdapter::SOURCE_KEY, self::DOFFIN_ID, self::DOFFIN_TITLE);

        return $this->onlyCase($user->customer);
    }

    /**
     * And the same from the other end.
     *
     * TED publishes both identifiers in its search results, so the live search records them on the
     * way past and the save costs nothing at all. This is the ordinary path: a TED record reaches
     * the identity layer through discovery, not through a lookup.
     */
    private function tedCase(User $user): SavedNotice
    {
        $this->liveSearch($user, TedSourceAdapter::SOURCE_KEY);
        $this->save($user, TedSourceAdapter::SOURCE_KEY, self::TED_ID, self::TED_TITLE);

        return $this->onlyCase($user->customer);
    }

    // ------------------------------------------------------------------ already saved, other register

    /**
     * The Doffin case exists; TED's record of the same tender is not a new opportunity.
     *
     * Free: the TED hit names its own procurement, so the answer is a join and not a request.
     */
    public function test_a_ted_hit_reads_as_saved_when_the_case_came_from_doffin(): void
    {
        $this->mockRegisters(tedItems: [
            $this->tedHit(self::TED_ID),
            $this->tedHit('333255-2026', self::OTHER_PROCEDURE, self::OTHER_NOTICE),
        ]);

        $user = $this->user();
        $case = $this->doffinCase($user);

        $hits = collect($this->liveSearch($user, TedSourceAdapter::SOURCE_KEY)['notices']['data'])->keyBy('notice_id');

        $this->assertTrue($hits[self::TED_ID]['is_saved'], 'the customer already works this procurement');
        $this->assertFalse($hits['333255-2026']['is_saved'], 'a different procurement is still new');
        $this->assertNotNull($case->fresh()->opportunity_id);
    }

    /**
     * And the other way round, which is the direction that costs something.
     *
     * A Doffin search result carries no identifiers, so the only way to know is to ask Doffin —
     * once per record, and only because this customer has a case no Doffin record is attached to
     * yet. The test after next proves the gate stays shut when that is not true.
     */
    public function test_a_doffin_hit_reads_as_saved_when_the_case_came_from_ted(): void
    {
        $this->mockRegisters(
            doffinItems: [$this->doffinHit(self::DOFFIN_ID), $this->doffinHit('2026-102030')],
            tedItems: [$this->tedHit(self::TED_ID)],
        );

        $user = $this->user();
        $this->tedCase($user);
        $this->fakeDoffinDetail($this->doffinIdentity());

        $hits = collect($this->liveSearch($user, DoffinSourceAdapter::SOURCE_KEY)['notices']['data'])->keyBy('notice_id');

        $this->assertTrue($hits[self::DOFFIN_ID]['is_saved']);
        $this->assertFalse($hits['2026-102030']['is_saved'], 'Doffin said nothing about this one');
        $this->assertDoffinLookups(2, 'both unidentified hits were asked about, and neither twice');
    }

    /**
     * A customer whose cases all have a Doffin record behind them learns nothing by asking, so
     * nothing is asked. This is what keeps ordinary Doffin search free.
     */
    public function test_doffin_search_asks_nothing_when_every_case_already_has_a_doffin_record(): void
    {
        $this->mockRegisters(doffinItems: [$this->doffinHit('2026-102030'), $this->doffinHit('2026-102031')]);

        $user = $this->user();
        $this->doffinCase($user);

        // Re-faking also clears what has been recorded, so the count below is the search's own and
        // not the lookup the save above paid for.
        $this->fakeDoffinDetail($this->doffinIdentity());
        $this->liveSearch($user, DoffinSourceAdapter::SOURCE_KEY);

        $this->assertDoffinLookups(0, 'a covered case has nothing to learn from a lookup');
    }

    /** A customer with no identified case at all has nothing to compare against, and pays nothing. */
    public function test_doffin_search_asks_nothing_for_a_customer_with_no_identified_case(): void
    {
        $this->mockRegisters(doffinItems: [$this->doffinHit(self::DOFFIN_ID)]);

        $this->liveSearch($this->user(), DoffinSourceAdapter::SOURCE_KEY);

        $this->assertDoffinLookups(0);
    }

    /**
     * And when the gate does open, it opens onto a ceiling.
     *
     * A page of results must not become an unbounded page of requests, however badly the gate is
     * misjudged, so the number of notices one search may identify is capped.
     */
    public function test_one_search_never_identifies_more_than_the_ceiling(): void
    {
        $hits = [];

        for ($index = 0; $index < 40; $index++) {
            $hits[] = $this->doffinHit(sprintf('2026-%06d', 200000 + $index));
        }

        $this->mockRegisters(doffinItems: $hits, tedItems: [$this->tedHit(self::TED_ID)]);

        $user = $this->user();
        $this->tedCase($user);
        $this->fakeDoffinDetail([]);

        $this->liveSearch($user, DoffinSourceAdapter::SOURCE_KEY);

        $this->assertDoffinLookups(25);
    }

    /** History is the other side of the same answer: an archived case is not a new opportunity. */
    public function test_a_ted_hit_reads_as_history_when_the_doffin_case_was_archived(): void
    {
        $this->mockRegisters(tedItems: [$this->tedHit(self::TED_ID)]);

        $user = $this->user();
        $case = $this->doffinCase($user);
        $case->forceFill([
            'archived_at' => now(),
            'history_type' => SavedNotice::HISTORY_TYPE_LOST,
        ])->save();

        $hit = $this->liveSearch($user, TedSourceAdapter::SOURCE_KEY)['notices']['data'][0];

        $this->assertTrue($hit['is_in_history']);
        $this->assertFalse($hit['is_saved']);
    }

    // ------------------------------------------------------------------ what must not happen

    /** A procurement is public; a case is not. Another customer's work is not this customer's. */
    public function test_another_customers_case_never_marks_a_hit_as_saved(): void
    {
        $this->mockRegisters(tedItems: [$this->tedHit(self::TED_ID)]);

        $this->doffinCase($this->user());

        $ours = $this->user();
        $hit = $this->liveSearch($ours, TedSourceAdapter::SOURCE_KEY)['notices']['data'][0];

        $this->assertFalse($hit['is_saved']);
        $this->assertSame(1, Opportunity::query()->where('procedure_identifier', self::PROCEDURE)->count());
    }

    /**
     * Without identifiers nothing changes, which is the fallback the whole phase rests on.
     *
     * Two notices that both said nothing are not thereby the same notice; the answer comes from
     * (source, external_id) exactly as it did before any of this existed — including that the same
     * number in the other register is still a different record.
     */
    public function test_a_hit_nobody_can_identify_keeps_the_source_aware_answer(): void
    {
        $this->mockRegisters(
            doffinItems: [$this->doffinHit(self::DOFFIN_ID), $this->doffinHit('2026-102030')],
            tedItems: [$this->tedHit(self::DOFFIN_ID, null, null)],
        );

        $user = $this->user();

        // Doffin has nothing to say about this one, so the case is saved without an identity.
        $this->save($user, DoffinSourceAdapter::SOURCE_KEY, self::DOFFIN_ID, self::DOFFIN_TITLE);
        $case = $this->onlyCase($user->customer);
        $this->assertNull($case->opportunity_id);

        $hits = collect($this->liveSearch($user, DoffinSourceAdapter::SOURCE_KEY)['notices']['data'])->keyBy('notice_id');

        $this->assertTrue($hits[self::DOFFIN_ID]['is_saved'], 'the register record itself is saved');
        $this->assertFalse($hits['2026-102030']['is_saved']);

        $this->assertFalse($this->liveSearch($user, TedSourceAdapter::SOURCE_KEY)['notices']['data'][0]['is_saved']);
    }

    // ------------------------------------------------------------------ saving the second register

    /** Doffin first, then TED. One case, two source records, and the case keeps its own contents. */
    public function test_saving_the_second_register_reaches_the_case_that_exists(): void
    {
        $this->mockRegisters(tedItems: [$this->tedHit(self::TED_ID)]);

        $user = $this->user();
        $case = $this->doffinCase($user);
        $case->forceFill(['notes' => 'Ansvar avklart med Kari 12.09.'])->save();

        $this->liveSearch($user, TedSourceAdapter::SOURCE_KEY);
        $this->save($user, TedSourceAdapter::SOURCE_KEY, self::TED_ID, self::TED_TITLE)->assertRedirect();

        $after = $this->onlyCase($user->customer);

        $this->assertSame($case->id, $after->id);
        $this->assertSame(self::DOFFIN_TITLE, $after->title, 'TED\'s translation must not replace the case title');
        $this->assertSame(DoffinSourceAdapter::SOURCE_KEY, $after->source);
        $this->assertSame(self::DOFFIN_ID, $after->external_id);
        $this->assertSame('Ansvar avklart med Kari 12.09.', $after->notes);
        $this->assertSame(2, OpportunitySourceRecord::query()->where('opportunity_id', $after->opportunity_id)->count());
    }

    /** TED first, then Doffin. Same rule, mirrored — including which title survives. */
    public function test_saving_doffin_after_ted_reaches_the_case_that_exists(): void
    {
        $this->mockRegisters(tedItems: [$this->tedHit(self::TED_ID)]);

        $user = $this->user();
        $case = $this->tedCase($user);

        $this->fakeDoffinDetail($this->doffinIdentity());
        $this->save($user, DoffinSourceAdapter::SOURCE_KEY, self::DOFFIN_ID, self::DOFFIN_TITLE)->assertRedirect();

        $after = $this->onlyCase($user->customer);

        $this->assertSame($case->id, $after->id);
        $this->assertSame(self::TED_TITLE, $after->title);
        $this->assertSame(TedSourceAdapter::SOURCE_KEY, $after->source);
        $this->assertSame(2, OpportunitySourceRecord::query()->where('opportunity_id', $after->opportunity_id)->count());
    }

    // ------------------------------------------------------------------ provenance on the case

    /** Both registers, named, with the one the case was opened from first. */
    public function test_the_case_shows_the_registers_the_procurement_is_published_in(): void
    {
        $this->mockRegisters(tedItems: [$this->tedHit(self::TED_ID)]);

        $user = $this->user();
        $case = $this->doffinCase($user);

        $this->liveSearch($user, TedSourceAdapter::SOURCE_KEY);
        $this->save($user, TedSourceAdapter::SOURCE_KEY, self::TED_ID, self::TED_TITLE);

        $sources = $this->casePayload($user, $case)['sources'];

        $this->assertSame(['doffin', 'ted'], array_column($sources, 'key'));
        $this->assertSame(['Doffin', 'TED'], array_column($sources, 'label'));
        $this->assertSame([self::DOFFIN_ID, self::TED_ID], array_column($sources, 'external_id'));
        $this->assertSame([true, false], array_column($sources, 'is_case_origin'));
        $this->assertStringContainsString(self::DOFFIN_ID, (string) $sources[0]['url']);
        $this->assertStringContainsString(self::TED_ID, (string) $sources[1]['url']);
    }

    /**
     * A register can hold several records of one procurement — TED republishes, and a change
     * notice and an award notice are separate documents — and the list still names it once.
     */
    public function test_the_source_list_names_each_register_once(): void
    {
        // 599740-2026 and 600314-2026 stand for one eForms notice published twice; the third is a
        // second document under the same procedure.
        $this->mockRegisters(tedItems: [
            $this->tedHit(self::TED_ID),
            $this->tedHit('600314-2026'),
            $this->tedHit('600064-2026', self::PROCEDURE, '6835ebe3-ce0f-43a2-b3eb-5d49e61baea7'),
        ]);

        $user = $this->user();
        $case = $this->doffinCase($user);

        $this->liveSearch($user, TedSourceAdapter::SOURCE_KEY);

        $sources = $this->casePayload($user, $case)['sources'];

        $this->assertSame(['doffin', 'ted'], array_column($sources, 'key'));
        $this->assertSame(3, OpportunitySourceRecord::query()
            ->where('opportunity_id', $case->fresh()->opportunity_id)
            ->where('source', TedSourceAdapter::SOURCE_KEY)
            ->count(), 'every record is still kept as provenance');
    }

    /** A case with no identity knows one register, and says so rather than saying nothing. */
    public function test_a_case_without_an_identity_shows_the_register_it_came_from(): void
    {
        $this->mockRegisters();

        $user = $this->user();
        $this->save($user, DoffinSourceAdapter::SOURCE_KEY, self::DOFFIN_ID, self::DOFFIN_TITLE);
        $case = $this->onlyCase($user->customer);

        $sources = $this->casePayload($user, $case)['sources'];

        $this->assertCount(1, $sources);
        $this->assertSame('doffin', $sources[0]['key']);
        $this->assertSame('Doffin', $sources[0]['label']);
        $this->assertTrue($sources[0]['is_case_origin']);
        $this->assertNull($case->opportunity_id);
    }

    // ------------------------------------------------------------------ provenance in discovery

    /** A TED search records what TED already said, so the identity is there before anyone saves. */
    public function test_a_ted_search_records_the_procurements_its_hits_name(): void
    {
        $this->mockRegisters(tedItems: [
            $this->tedHit(self::TED_ID),
            $this->tedHit('333255-2026', self::OTHER_PROCEDURE, self::OTHER_NOTICE),
        ]);

        $this->liveSearch($this->user(), TedSourceAdapter::SOURCE_KEY);

        $this->assertSame(2, OpportunitySourceRecord::query()->where('source', TedSourceAdapter::SOURCE_KEY)->count());
        $this->assertSame(1, Opportunity::query()->where('procedure_identifier', self::PROCEDURE)->count());
        $this->assertDoffinLookups(0, 'TED names its own procurements; nothing had to be asked');
    }

    /** A hit no register identified is not recorded, and no identity is invented for it. */
    public function test_a_search_records_nothing_for_hits_without_identifiers(): void
    {
        $this->mockRegisters(tedItems: [$this->tedHit('337011-2026', null, null)]);

        $this->liveSearch($this->user(), TedSourceAdapter::SOURCE_KEY);

        $this->assertSame(0, OpportunitySourceRecord::query()->where('external_id', '337011-2026')->count());
    }
}
