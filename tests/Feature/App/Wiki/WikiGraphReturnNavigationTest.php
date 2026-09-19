<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiPage;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\TestCase;

/**
 * An article opened from Grafvisning must offer its way back to Grafvisning, not to the Wiki page
 * list — the user is exploring a graph, and landing in a flat list instead loses the place they
 * were working in.
 *
 * WHY THE ORIGIN LIVES IN THE URL. The obvious implementation, window.history.back(), cannot keep
 * the promise: the article is routinely reloaded, opened in a new tab from the node panel, reached
 * after Inertia has already rewritten history, or pasted to a colleague. show() therefore re-derives
 * the origin from ?back_url= on every render and revalidates it through
 * PreservesWikiReviewReturnUrl::normalizeGraphReturnUrl(). The tests below pin that down as server
 * behaviour: same URL in, same navigation_origin out, however the page was reached.
 *
 * The label itself is decided by resolveWikiBackLink() from this prop — unit-tested in
 * runFindingsLogic.test.js, which also covers the finding context this must not disturb.
 */
class WikiGraphReturnNavigationTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_article_opened_from_the_wiki_list_has_no_navigation_origin(): void
    {
        [$user, $page] = $this->articleAndViewer();

        $this->actingAs($user)
            ->get("/app/wiki/{$page->slug}")
            ->assertOk()
            ->assertInertia(fn ($inertia) => $inertia->where('navigation_origin', null));
    }

    public function test_article_opened_from_the_graph_returns_to_the_graph(): void
    {
        [$user, $page] = $this->articleAndViewer();

        $this->actingAs($user)
            ->get("/app/wiki/{$page->slug}?back_url=".urlencode('/app/wiki/graph'))
            ->assertOk()
            ->assertInertia(fn ($inertia) => $inertia
                ->where('navigation_origin.context', 'graph')
                ->where('navigation_origin.back_url', '/app/wiki/graph')
            );
    }

    /**
     * The graph's scope is the one piece of its state that WikiGraphController reads back off the
     * URL, so it is the one piece a return link can actually restore. Losing it would drop a user
     * who was exploring one run's neighbourhood into the whole-wiki graph.
     */
    public function test_the_graphs_run_scope_survives_into_the_return_link(): void
    {
        [$user, $page] = $this->articleAndViewer();

        $this->actingAs($user)
            ->get("/app/wiki/{$page->slug}?back_url=".urlencode('/app/wiki/graph?run_id=24'))
            ->assertOk()
            ->assertInertia(fn ($inertia) => $inertia
                ->where('navigation_origin.back_url', '/app/wiki/graph?run_id=24')
            );
    }

    public function test_the_graphs_page_scope_survives_into_the_return_link(): void
    {
        [$user, $page] = $this->articleAndViewer();

        $this->actingAs($user)
            ->get("/app/wiki/{$page->slug}?back_url=".urlencode('/app/wiki/graph?page_id=77'))
            ->assertOk()
            ->assertInertia(fn ($inertia) => $inertia
                ->where('navigation_origin.back_url', '/app/wiki/graph?page_id=77')
            );
    }

    /**
     * A reload is simply the same URL requested again — which is the whole point of putting the
     * origin there. Asserted explicitly because this is the case history-based navigation gets
     * wrong, and the one a user hits most often.
     */
    public function test_reloading_a_graph_opened_article_keeps_the_graph_origin(): void
    {
        [$user, $page] = $this->articleAndViewer();
        $url = "/app/wiki/{$page->slug}?back_url=".urlencode('/app/wiki/graph?run_id=24');

        foreach (range(1, 3) as $_) {
            $this->actingAs($user)
                ->get($url)
                ->assertOk()
                ->assertInertia(fn ($inertia) => $inertia
                    ->where('navigation_origin.context', 'graph')
                    ->where('navigation_origin.back_url', '/app/wiki/graph?run_id=24')
                );
        }
    }

    /**
     * Every rejected shape degrades to "no origin", which renders the plain "Tilbake til Wiki" the
     * page has always had. Fail-safe rather than fail-open: this value is rendered as a link, so an
     * unrecognised origin must never become the destination.
     */
    #[DataProvider('unusableOrigins')]
    public function test_an_unusable_origin_falls_back_to_the_plain_wiki_link(string $rawBackUrl): void
    {
        [$user, $page] = $this->articleAndViewer();

        $this->actingAs($user)
            ->get("/app/wiki/{$page->slug}?back_url=".urlencode($rawBackUrl))
            ->assertOk()
            ->assertInertia(fn ($inertia) => $inertia->where('navigation_origin', null));
    }

    /** @return array<string, array{string}> */
    public static function unusableOrigins(): array
    {
        return [
            'empty' => [''],
            'whitespace only' => ['   '],
            'unknown origin name' => ['dashboard'],
            'another app page' => ['/app/notices'],
            'a wiki page, not the graph' => ['/app/wiki/some-slug'],
            // "/app/wiki/graph" is a prefix of neither — a near miss must not pass.
            'graph-like path' => ['/app/wiki/graph-data'],
            'absolute off-site url' => ['https://evil.example.com/app/wiki/graph'],
            'protocol-relative off-site url' => ['//evil.example.com/app/wiki/graph'],
            'javascript scheme' => ['javascript:alert(1)'],
        ];
    }

    /**
     * The scope parameters are rebuilt from scratch rather than echoed back, so a hand-edited
     * back_url cannot smuggle anything extra into a rendered link.
     */
    public function test_unknown_query_parameters_are_dropped_from_the_return_link(): void
    {
        [$user, $page] = $this->articleAndViewer();

        $this->actingAs($user)
            ->get("/app/wiki/{$page->slug}?back_url=".urlencode('/app/wiki/graph?run_id=24&tab=runs&redirect=https://evil.example.com'))
            ->assertOk()
            ->assertInertia(fn ($inertia) => $inertia
                ->where('navigation_origin.back_url', '/app/wiki/graph?run_id=24')
            );
    }

    public function test_a_non_numeric_scope_is_dropped_rather_than_carried(): void
    {
        [$user, $page] = $this->articleAndViewer();

        $this->actingAs($user)
            ->get("/app/wiki/{$page->slug}?back_url=".urlencode('/app/wiki/graph?run_id=not-a-number'))
            ->assertOk()
            ->assertInertia(fn ($inertia) => $inertia
                ->where('navigation_origin.context', 'graph')
                ->where('navigation_origin.back_url', '/app/wiki/graph')
            );
    }

    /**
     * The finding context is the other thing back_url carries, and it must keep working exactly as
     * before: a finding URL is not a graph origin, and the two whitelists stay disjoint.
     */
    public function test_a_finding_back_url_is_not_treated_as_a_graph_origin(): void
    {
        [$user, $page] = $this->articleAndViewer();

        $this->actingAs($user)
            ->get("/app/wiki/{$page->slug}?back_url=".urlencode('/app/wiki?tab=runs&focus_finding=lint-7'))
            ->assertOk()
            ->assertInertia(fn ($inertia) => $inertia->where('navigation_origin', null));
    }

    /** @return array{User, EnterpriseWikiPage} */
    private function articleAndViewer(): array
    {
        $customer = $this->createWikiCustomer('Graph Return Navigation AS');

        return [
            $this->createViewer($customer),
            $this->createWikiPageWithVersion($customer, 'Threat Hunting', '## Innhold'),
        ];
    }

    private function createViewer(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'Graph Navigator',
            'email' => Str::lower(Str::random(8)).'@graph-return-test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }
}
