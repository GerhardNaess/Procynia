<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\EnterpriseWikiPage;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Hjem is Procynia across its modules, and nothing else.
 *
 * Until now /app/dashboard was the bid cockpit, so Hjem and Anbud were the same page under two
 * names. The split is what these tests hold: Hjem carries module status and none of the cockpit's
 * payload, the cockpit still exists unchanged at its own route inside Anbud, and every number on
 * Hjem is read through the access rules its module already has rather than a new set written for
 * this page.
 */
class HomeControllerTest extends TestCase
{
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware([VerifyCsrfToken::class, ValidateCsrfToken::class]);
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    // =========================================================================
    // Hjem is its own page
    // =========================================================================

    public function test_home_renders_the_cross_module_dashboard(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $page = $this->inertiaPage($this->actingAs($user)->get('/app/dashboard'));

        $this->assertSame('App/Home/Index', $page['component']);
    }

    public function test_home_carries_no_bid_cockpit_payload(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $props = $this->inertiaPage($this->actingAs($user)->get('/app/dashboard'))['props'];

        // These are exactly the props the cockpit is built from. Their absence is what makes Hjem
        // incapable of quietly growing a bid pipeline back.
        foreach (['cockpit', 'pipeline', 'stats', 'recentWorklistItems', 'watchProfileSummary'] as $cockpitProp) {
            $this->assertArrayNotHasKey($cockpitProp, $props, "Hjem must not carry '{$cockpitProp}'");
        }
    }

    public function test_the_three_module_cards_are_wiki_tenders_quality_and_each_links_to_its_module(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $modules = $this->inertiaPage($this->actingAs($user)->get('/app/dashboard'))['props']['modules'];

        $this->assertSame(['wiki', 'tenders', 'quality'], array_column($modules, 'key'));
        $this->assertSame(
            [route('app.wiki.index'), route('app.notices.index', ['mode' => 'saved']), route('app.quality.index')],
            array_column($modules, 'href'),
        );
    }

    public function test_quality_is_shown_as_being_built_and_carries_no_invented_numbers(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $quality = $this->moduleCard($user, 'quality');

        $this->assertSame('building', $quality['state']);
        $this->assertSame([], $quality['metrics']);
    }

    // =========================================================================
    // The numbers, and whose they are
    // =========================================================================

    public function test_the_wiki_card_counts_only_the_signed_in_customers_pages(): void
    {
        $customer = $this->createCustomer('Hjem AS');
        $otherCustomer = $this->createCustomer('Annen AS');
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->createWikiPage($customer, EnterpriseWikiPage::STATUS_APPROVED);
        $this->createWikiPage($customer, EnterpriseWikiPage::STATUS_APPROVED);
        $this->createWikiPage($customer, EnterpriseWikiPage::STATUS_PENDING_REVIEW);
        $this->createWikiPage($otherCustomer, EnterpriseWikiPage::STATUS_APPROVED);
        $this->createWikiPage($otherCustomer, EnterpriseWikiPage::STATUS_PENDING_REVIEW);

        $metrics = $this->metricsOf($this->moduleCard($user, 'wiki'));

        $this->assertSame(3, $metrics['pages']);
        $this->assertSame(1, $metrics['pending_review']);
    }

    public function test_the_tenders_card_counts_active_cases_without_the_archived_ones(): void
    {
        $customer = $this->createCustomer('Hjem AS');
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->createSavedNotice($customer, SavedNotice::BID_STATUS_IN_PROGRESS);
        $this->createSavedNotice($customer, SavedNotice::BID_STATUS_SUBMITTED);
        $this->createSavedNotice($customer, SavedNotice::BID_STATUS_SUBMITTED);
        $this->createSavedNotice($customer, SavedNotice::BID_STATUS_ARCHIVED, archived: true);

        $metrics = $this->metricsOf($this->moduleCard($user, 'tenders'));

        $this->assertSame(3, $metrics['active_cases']);
        $this->assertSame(2, $metrics['submitted_cases']);
    }

    public function test_the_tenders_card_does_not_count_another_customers_cases(): void
    {
        $customer = $this->createCustomer('Hjem AS');
        $otherCustomer = $this->createCustomer('Annen AS');
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $this->createSavedNotice($customer, SavedNotice::BID_STATUS_IN_PROGRESS);
        $this->createSavedNotice($otherCustomer, SavedNotice::BID_STATUS_IN_PROGRESS);
        $this->createSavedNotice($otherCustomer, SavedNotice::BID_STATUS_SUBMITTED);

        $metrics = $this->metricsOf($this->moduleCard($user, 'tenders'));

        $this->assertSame(1, $metrics['active_cases']);
        $this->assertSame(0, $metrics['submitted_cases']);
    }

    // =========================================================================
    // The cockpit, where it lives now
    // =========================================================================

    public function test_bid_status_still_serves_the_bid_cockpit(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer, User::BID_ROLE_SYSTEM_OWNER);

        $page = $this->inertiaPage($this->actingAs($user)->get('/app/bid-status'));

        $this->assertSame('App/Dashboard/Index', $page['component']);
        $this->assertArrayHasKey('cockpit', $page['props']);
        $this->assertArrayHasKey('pipeline', $page['props']);
    }

    public function test_both_pages_are_closed_to_a_guest(): void
    {
        $this->get('/app/dashboard')->assertRedirect(route('login'));
        $this->get('/app/bid-status')->assertRedirect(route('login'));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @return array<string, mixed>
     */
    private function moduleCard(User $user, string $key): array
    {
        $modules = $this->inertiaPage($this->actingAs($user)->get('/app/dashboard'))['props']['modules'];

        foreach ($modules as $module) {
            if ($module['key'] === $key) {
                return $module;
            }
        }

        $this->fail("No '{$key}' card on Hjem");
    }

    /**
     * @param  array<string, mixed>  $card
     * @return array<string, int>
     */
    private function metricsOf(array $card): array
    {
        return array_column($card['metrics'], 'value', 'key');
    }

    private function inertiaPage(mixed $response): array
    {
        $response->assertOk();

        $page = $response->viewData('page');

        return is_array($page)
            ? $page
            : json_decode((string) $page, true, 512, JSON_THROW_ON_ERROR);
    }

    private function createCustomer(string $name = 'Hjem Test AS'): Customer
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
            'subscription_plan' => Customer::PLAN_MAX,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'included_users' => 10,
            'included_ai_credits' => 20,
        ]);
    }

    private function createUser(Customer $customer, string $bidRole): User
    {
        return User::factory()->create([
            'role' => User::customerRoleForBidRole($bidRole),
            'bid_role' => $bidRole,
            'bid_manager_scope' => $bidRole === User::BID_ROLE_BID_MANAGER ? User::BID_MANAGER_SCOPE_COMPANY : null,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    private function createWikiPage(Customer $customer, string $status): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'hjem-'.Str::lower(Str::random(10)),
            'title' => 'Hjem-testside',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => $status,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function createSavedNotice(Customer $customer, string $bidStatus, bool $archived = false): SavedNotice
    {
        return SavedNotice::query()->create([
            'customer_id' => $customer->id,
            'external_id' => 'HJEM-'.Str::upper(Str::random(10)),
            'title' => 'Hjem-testsak',
            'buyer_name' => 'Procynia',
            'status' => 'ACTIVE',
            'bid_status' => $bidStatus,
            'archived_at' => $archived ? now() : null,
        ]);
    }
}
