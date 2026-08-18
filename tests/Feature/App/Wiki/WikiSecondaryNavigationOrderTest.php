<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Wiki submenu is ordered by the workflow a user actually follows: source material goes in
 * first (Kildedokumenter), the runs show what the system did with it (Kjøringer), the pages are the
 * result (Wiki-sider), and the graph is where the connections are explored (Grafvisning).
 *
 * Display order only. The menu is built inline in CustomerAppLayout and the project has no JSX
 * renderer, so the order is asserted at source level — the same guard style used elsewhere in this
 * suite. The default landing tab is asserted over HTTP, because it is resolved server-side and is
 * deliberately NOT tied to whichever item happens to render first.
 */
class WikiSecondaryNavigationOrderTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED_ORDER = [
        'wiki-sources' => "tw.tab_sources ?? 'Kildedokumenter'",
        'wiki-runs' => "tw.tab_runs ?? 'Kjøringer'",
        'wiki-pages' => "tw.tab_pages ?? 'Wiki-sider'",
        'wiki-graph' => "tw.tab_graph ?? 'Grafvisning'",
    ];

    public function test_the_wiki_submenu_follows_the_workflow_order(): void
    {
        $positions = [];

        foreach (array_keys(self::EXPECTED_ORDER) as $key) {
            $position = mb_strpos($this->wikiMenuBlock(), "key: '{$key}'");

            $this->assertNotFalse($position, "the {$key} menu item is missing");
            $positions[$key] = $position;
        }

        $sorted = $positions;
        asort($sorted);

        $this->assertSame(
            ['wiki-sources', 'wiki-runs', 'wiki-pages', 'wiki-graph'],
            array_keys($sorted),
            'Kildedokumenter → Kjøringer → Wiki-sider → Grafvisning',
        );
    }

    public function test_all_four_items_keep_their_labels_and_destinations(): void
    {
        $block = $this->wikiMenuBlock();

        foreach (self::EXPECTED_ORDER as $key => $label) {
            $this->assertStringContainsString($label, $block, "{$key} lost its label");
        }

        foreach ([
            "buildHref('/app/wiki', { tab: 'sources' })",
            "buildHref('/app/wiki', { tab: 'runs' })",
            "buildHref('/app/wiki', { tab: 'pages' })",
            "href: '/app/wiki/graph'",
        ] as $destination) {
            $this->assertStringContainsString($destination, $block, "a destination changed: {$destination}");
        }

        $this->assertSame(4, mb_substr_count($block, 'key: '), 'no item may be added or removed');
    }

    /**
     * The active item is derived from the tab value, never from menu position — reordering the list
     * must not be able to shift which item highlights.
     */
    public function test_the_active_item_is_still_resolved_from_the_tab_value(): void
    {
        $layout = $this->layoutSource();

        $this->assertStringContainsString('return `wiki-${wikiTab}`;', $layout);
        $this->assertStringContainsString("if (pathname.startsWith('/app/wiki/graph')) {", $layout);
        $this->assertStringContainsString("const wikiTab = searchParams.get('tab') ?? 'pages';", $layout);
    }

    public function test_the_wiki_landing_page_still_opens_on_wiki_sider(): void
    {
        $user = $this->createUser($this->createCustomer());

        foreach (['/app/wiki', '/app/wiki?tab=pages'] as $url) {
            $props = data_get($this->actingAs($user)->get($url)->assertOk()->viewData('page'), 'props', []);

            $this->assertSame('pages', $props['active_tab'], "{$url} must land on Wiki-sider");
        }

        foreach (['sources', 'runs'] as $tab) {
            $props = data_get(
                $this->actingAs($user)->get("/app/wiki?tab={$tab}")->assertOk()->viewData('page'),
                'props',
                [],
            );

            $this->assertSame($tab, $props['active_tab']);
        }
    }

    private function layoutSource(): string
    {
        return (string) file_get_contents(base_path('resources/js/Layouts/CustomerAppLayout.jsx'));
    }

    private function wikiMenuBlock(): string
    {
        $layout = $this->layoutSource();
        $start = mb_strpos($layout, "if (activeMainArea === 'wiki') {");

        $this->assertNotFalse($start, 'the Wiki menu block was not found');

        $end = mb_strpos($layout, '];', $start);

        $this->assertNotFalse($end);

        return mb_substr($layout, $start, $end - $start);
    }

    private function createCustomer(string $name = 'Wiki Meny AS'): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

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
            'name' => 'System Owner',
            'email' => Str::lower(Str::random(8)).'@wiki-meny.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }
}
