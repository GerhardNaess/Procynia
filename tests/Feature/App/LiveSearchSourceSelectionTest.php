<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Models\User;
use App\Services\Doffin\DoffinLiveSearchService;
use App\Services\Doffin\DoffinSourceAdapter;
use App\Services\OpportunitySources\OpportunitySourceRegistry;
use App\Services\Ted\TedSearchClient;
use App\Services\Ted\TedSourceAdapter;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Choosing which register live search asks.
 *
 * There are two now, so "the source" is a question rather than an assumption. A request that says
 * nothing still gets Doffin, because every existing link, saved search and bookmark means Doffin
 * and none of them were asked to change. A request that names a register gets that one — or, if
 * Procynia has no adapter for it, nothing at all.
 *
 * Both registers are driven through mocked clients. Nothing here touches the network.
 */
class LiveSearchSourceSelectionTest extends TestCase
{
    use DatabaseTransactions;

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

    private function user(): User
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);
        $customer = Customer::query()->create([
            'name' => 'Live Search AS',
            'slug' => 'live-search-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);

        return User::query()->create([
            'name' => 'Bid Manager',
            'email' => 'live-'.Str::uuid().'@procynia.test',
            'password' => bcrypt('secret-only-local'),
            'customer_id' => $customer->id,
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'is_active' => true,
        ]);
    }

    private function mockDoffin(): void
    {
        $service = Mockery::mock(DoffinLiveSearchService::class);
        $service->shouldReceive('search')->andReturn([
            'ok' => true,
            'items' => [[
                'id' => 'SHARED-1',
                'heading' => 'Doffin-kunngjøring',
                'status' => 'ACTIVE',
                'publicationDate' => '2026-03-01',
            ]],
            'page' => 1,
            'perPage' => 15,
            'numHitsTotal' => 1,
            'numHitsAccessible' => 1,
        ]);

        $this->app->instance(DoffinLiveSearchService::class, $service);
        $this->forgetResolvedSources();
    }

    private function mockTed(): void
    {
        $client = Mockery::mock(TedSearchClient::class);
        $client->shouldReceive('search')->andReturn([
            'ok' => true,
            'items' => [[
                'publication-number' => 'SHARED-1',
                'notice-type' => 'cn-standard',
                'notice-title' => ['eng' => 'TED notice'],
                'publication-date' => '2026-03-01+01:00',
            ]],
            'page' => 1,
            'perPage' => 15,
            'numHitsTotal' => 1,
            'numHitsAccessible' => 1,
            'fallback_used' => false,
        ]);

        $this->app->instance(TedSearchClient::class, $client);
        $this->forgetResolvedSources();
    }

    /**
     * Drop the resolved registry so a client swapped in now is the one the adapters use.
     *
     * The registry is a singleton holding already-built adapters, so binding a mock client after
     * the first request would leave the real one in place — and the real one would reach TED's
     * live API from a test, which is how a suite starts depending on the internet without anybody
     * deciding that it should.
     */
    private function forgetResolvedSources(): void
    {
        $this->app->forgetInstance(OpportunitySourceRegistry::class);
        $this->app->forgetInstance(TedSourceAdapter::class);
        $this->app->forgetInstance(DoffinSourceAdapter::class);
    }

    /** @return array<string, mixed> */
    private function liveSearch(User $user, array $query = []): array
    {
        return $this->actingAs($user)
            ->get('/app/notices?'.http_build_query(array_merge(['mode' => 'live'], $query)))
            ->assertOk()
            ->viewData('page')['props'];
    }

    // ------------------------------------------------------------------ selection

    /** Every link, saved search and bookmark that exists today means Doffin. */
    public function test_a_request_that_names_no_source_still_searches_doffin(): void
    {
        $this->mockDoffin();

        $props = $this->liveSearch($this->user());

        $this->assertSame('doffin_live_search', $props['source']['type']);
        $this->assertSame('Doffin-kunngjøring', $props['notices']['data'][0]['title']);
    }

    public function test_a_request_naming_ted_searches_ted(): void
    {
        $this->mockTed();

        $props = $this->liveSearch($this->user(), ['source' => TedSourceAdapter::SOURCE_KEY]);

        $this->assertSame('ted_live_search', $props['source']['type']);
        $this->assertSame('Live søk i TED', $props['source']['label']);
        $this->assertSame('TED notice', $props['notices']['data'][0]['title']);
    }

    /**
     * A TED result reaches the page in the same shape a Doffin one does. If it did not, the
     * discovery list would need a second renderer — which is the thing four phases of work were
     * spent avoiding.
     */
    public function test_a_ted_result_arrives_in_the_existing_payload_shape(): void
    {
        $this->mockDoffin();
        $this->mockTed();

        $doffinKeys = array_keys($this->liveSearch($this->user())['notices']['data'][0]);
        $tedKeys = array_keys($this->liveSearch($this->user(), ['source' => 'ted'])['notices']['data'][0]);

        $this->assertSame($doffinKeys, $tedKeys);
    }

    /**
     * An unknown register is refused, not quietly answered by whichever adapter is at hand. That
     * refusal is the reason the registry throws instead of defaulting.
     */
    public function test_an_unknown_source_is_refused_rather_than_silently_becoming_doffin(): void
    {
        $this->mockDoffin();

        $this->actingAs($this->user())
            ->get('/app/notices?mode=live&source=eu-funding-and-tenders')
            ->assertServerError();
    }

    // ------------------------------------------------------------------ collision

    /**
     * The point of every source-aware phase before this one, now provable with two real registers:
     * one external id, two registers, two different opportunities.
     */
    public function test_the_same_external_id_from_two_registers_is_two_opportunities(): void
    {
        $user = $this->user();

        // A Doffin case is already saved under the shared id.
        SavedNotice::query()->create([
            'customer_id' => $user->customer_id,
            'saved_by_user_id' => $user->id,
            'source_type' => SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE,
            'source' => 'doffin',
            'external_id' => 'SHARED-1',
            'title' => 'Allerede lagret fra Doffin',
            'bid_status' => SavedNotice::BID_STATUS_DISCOVERED,
        ]);

        // Both registers stubbed before the first request, so neither adapter is ever built
        // against a real client — swapping one in later would leave the already-resolved registry
        // holding the real one, and a test would quietly start calling TED over the internet.
        $this->mockDoffin();
        $this->mockTed();

        $doffinHit = $this->liveSearch($user)['notices']['data'][0];
        $tedHit = $this->liveSearch($user, ['source' => 'ted'])['notices']['data'][0];

        $this->assertSame('SHARED-1', $doffinHit['notice_id']);
        $this->assertSame('SHARED-1', $tedHit['notice_id']);
        $this->assertTrue($doffinHit['is_saved'], 'the Doffin notice is the one that was saved');
        $this->assertFalse($tedHit['is_saved'], 'TED\'s notice of the same number is a different opportunity');
    }

    /** And saving from TED creates a second case rather than colliding with the Doffin one. */
    public function test_saving_a_ted_result_creates_a_case_of_its_own(): void
    {
        $user = $this->user();

        SavedNotice::query()->create([
            'customer_id' => $user->customer_id,
            'saved_by_user_id' => $user->id,
            'source_type' => SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE,
            'source' => 'doffin',
            'external_id' => 'SHARED-2',
            'title' => 'Doffin-saken',
            'bid_status' => SavedNotice::BID_STATUS_DISCOVERED,
        ]);

        $this->actingAs($user)
            ->from('/app/notices?mode=live&source=ted')
            ->post('/app/notices/save?source=ted', [
                'notice_id' => 'SHARED-2',
                'title' => 'TED-saken',
            ])
            ->assertRedirect();

        $cases = SavedNotice::query()
            ->where('customer_id', $user->customer_id)
            ->where('external_id', 'SHARED-2')
            ->orderBy('source')
            ->get();

        $this->assertCount(2, $cases, 'two registers, two cases');
        $this->assertSame(['doffin', 'ted'], $cases->pluck('source')->all());
        $this->assertSame(['Doffin-saken', 'TED-saken'], $cases->pluck('title')->all());
    }
}
