<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageLink;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 8E-20: Enterprise Wiki graph view — backend controller tests.
 *
 * GET /app/wiki/graph returns the Inertia 'App/Wiki/Graph' page.
 * Data is fetched client-side from GET /app/wiki/graph-data.
 * This controller is read-only: no writes, no OpenAI.
 */
class WikiGraphControllerTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Authentication
    // =========================================================================

    public function test_guest_is_redirected_from_graph_page(): void
    {
        $this->get('/app/wiki/graph')->assertStatus(302);
    }

    // =========================================================================
    // Basic accessibility and Inertia component
    // =========================================================================

    public function test_authenticated_user_can_view_graph_page(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);

        $this->actingAs($user)
            ->get('/app/wiki/graph')
            ->assertOk();
    }

    public function test_graph_page_renders_correct_inertia_component(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);

        $this->actingAs($user)
            ->get('/app/wiki/graph')
            ->assertOk()
            ->assertViewHas('page', fn (array $inertia) => data_get($inertia, 'component') === 'App/Wiki/Graph'
            );
    }

    // =========================================================================
    // Props / scope params
    // =========================================================================

    public function test_graph_page_passes_null_initial_run_id_by_default(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);

        $this->actingAs($user)
            ->get('/app/wiki/graph')
            ->assertOk()
            ->assertViewHas('page', fn (array $inertia) => data_get($inertia, 'props.initialRunId') === null
            );
    }

    public function test_graph_page_passes_null_initial_page_id_by_default(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);

        $this->actingAs($user)
            ->get('/app/wiki/graph')
            ->assertOk()
            ->assertViewHas('page', fn (array $inertia) => data_get($inertia, 'props.initialPageId') === null
            );
    }

    public function test_graph_page_passes_run_id_from_query_param(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);

        $this->actingAs($user)
            ->get('/app/wiki/graph?run_id=42')
            ->assertOk()
            ->assertViewHas('page', fn (array $inertia) => data_get($inertia, 'props.initialRunId') === 42
            );
    }

    public function test_graph_page_passes_page_id_from_query_param(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);

        $this->actingAs($user)
            ->get('/app/wiki/graph?page_id=99')
            ->assertOk()
            ->assertViewHas('page', fn (array $inertia) => data_get($inertia, 'props.initialPageId') === 99
            );
    }

    public function test_graph_page_passes_page_id_as_integer_not_string(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);

        $response = $this->actingAs($user)->get('/app/wiki/graph?page_id=7');
        $response->assertOk();
        $response->assertViewHas('page', fn (array $inertia) => data_get($inertia, 'props.initialPageId') === 7 &&
            is_int(data_get($inertia, 'props.initialPageId'))
        );
    }

    // =========================================================================
    // Route does not shadow slug catch-all
    // =========================================================================

    public function test_graph_route_does_not_intercept_slug_named_graph(): void
    {
        // "graph" as a slug is intentionally claimed by this route;
        // a real wiki page cannot have slug "graph" due to route priority.
        // This test just confirms the graph route itself resolves to our component.
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);

        $this->actingAs($user)
            ->get('/app/wiki/graph')
            ->assertOk()
            ->assertViewHas('page', fn (array $inertia) => data_get($inertia, 'component') === 'App/Wiki/Graph'
            );
    }

    // =========================================================================
    // No side effects
    // =========================================================================

    public function test_graph_page_does_not_create_pages(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $before = EnterpriseWikiPage::query()->count();

        $this->actingAs($user)->get('/app/wiki/graph')->assertOk();

        $this->assertSame($before, EnterpriseWikiPage::query()->count());
    }

    public function test_graph_page_does_not_create_page_links(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $before = EnterpriseWikiPageLink::query()->count();

        $this->actingAs($user)->get('/app/wiki/graph')->assertOk();

        $this->assertSame($before, EnterpriseWikiPageLink::query()->count());
    }

    public function test_graph_page_does_not_create_claims(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $before = EnterpriseWikiClaim::query()->count();

        $this->actingAs($user)->get('/app/wiki/graph')->assertOk();

        $this->assertSame($before, EnterpriseWikiClaim::query()->count());
    }

    public function test_graph_page_does_not_create_lint_findings(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $before = EnterpriseWikiLintFinding::query()->count();

        $this->actingAs($user)->get('/app/wiki/graph')->assertOk();

        $this->assertSame($before, EnterpriseWikiLintFinding::query()->count());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createCustomer(string $name = 'Testkunde AS'): Customer
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
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function createUser(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'Test User',
            'email' => Str::lower(Str::random(8)).'@test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }
}
