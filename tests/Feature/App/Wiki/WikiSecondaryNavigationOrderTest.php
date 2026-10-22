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
 * The menu is built by resources/js/Support/appNavigation.js since the sidebar was unified
 * (6b0f13cb). Its order, labels, destinations and active item are asserted there, by driving the
 * real navigation function (resources/js/Layouts/headerNavigation.test.js, «the Wiki items keep
 * their order, labels and destinations» and «a module without them — Wiki — keeps its tabs»). This
 * test keeps what only the server can answer: which tab /app/wiki opens on — deliberately NOT tied
 * to whichever item happens to render first.
 */
class WikiSecondaryNavigationOrderTest extends TestCase
{
    use RefreshDatabase;

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
