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
 * The Kvalitet tab rendered as a blank content area. The cause was a frontend one — Index.jsx
 * called a helper name wikiQualityChecks does not export, throwing at render — but the read model
 * is the other half of that contract, so this pins the props QualityTab destructures
 * (quality_findings, quality_filters, coverage, lint_health) to the response the tab actually
 * serves. A rename or drop on the backend would blank the tab just as effectively.
 */
class WikiQualityTabRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_quality_tab_serves_the_props_the_component_renders_from(): void
    {
        $user = $this->createUser($this->createCustomer());

        $response = $this->actingAs($user)->get('/app/wiki?tab=quality');

        $response->assertOk();

        $props = data_get($response->viewData('page'), 'props');

        $this->assertSame('quality', $props['active_tab']);

        foreach (['quality_findings', 'quality_filters', 'coverage', 'lint_health'] as $prop) {
            $this->assertArrayHasKey($prop, $props, "the quality tab must serve {$prop}");
        }

        // QualityTab calls findings.map() directly, so this one may never be null.
        $this->assertIsArray($props['quality_findings']);

        // The filter bar reads these three keys off quality_filters.
        foreach (['severity', 'code', 'page_type'] as $filter) {
            $this->assertArrayHasKey($filter, $props['quality_filters']);
        }

        // LintHealthBar reads these counters.
        foreach (['error', 'warning', 'info', 'total'] as $bucket) {
            $this->assertArrayHasKey($bucket, $props['lint_health']);
        }
    }

    private function createCustomer(string $name = 'Kvalitet Tab AS'): Customer
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
            'email' => Str::lower(Str::random(8)).'@kvalitet-tab.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }
}
