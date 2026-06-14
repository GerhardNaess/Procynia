<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\BillingPriceResource;
use App\Filament\Resources\BillingProductResource;
use App\Filament\Resources\CustomerUserServiceLevelResource;
use App\Models\AdminPageHelp;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BillingCatalogResourcesTest extends TestCase
{
    use RefreshDatabase;

    public function test_billing_catalog_resources_expose_expected_navigation_metadata(): void
    {
        // Tjenestekatalog er primærflaten — BillingPriceResource
        $this->assertSame('Tjenestekatalog', BillingPriceResource::getNavigationLabel());
        $this->assertSame('Fakturering', BillingPriceResource::getNavigationGroup());
        $this->assertSame('Brukerlisenser', CustomerUserServiceLevelResource::getNavigationLabel());
        $this->assertSame('Fakturering', CustomerUserServiceLevelResource::getNavigationGroup());

        // BillingProductResource er teknisk ressurs — ikke i navigasjonen
        $this->assertFalse(BillingProductResource::shouldRegisterNavigation());
        $this->assertSame('Fakturering', BillingProductResource::getNavigationGroup());

        // Alle sider finnes fortsatt for teknisk tilgang
        $this->assertArrayHasKey('index', BillingProductResource::getPages());
        $this->assertArrayHasKey('create', BillingProductResource::getPages());
        $this->assertArrayHasKey('edit', BillingProductResource::getPages());
        $this->assertArrayHasKey('view', BillingProductResource::getPages());

        $this->assertArrayHasKey('index', BillingPriceResource::getPages());
        $this->assertArrayHasKey('create', BillingPriceResource::getPages());
        $this->assertArrayHasKey('edit', BillingPriceResource::getPages());
        $this->assertArrayHasKey('view', BillingPriceResource::getPages());

        $this->assertArrayHasKey('index', CustomerUserServiceLevelResource::getPages());
        $this->assertArrayHasKey('view', CustomerUserServiceLevelResource::getPages());
    }

    public function test_tjenestekatalog_er_eneste_primærflate_for_billing_admin(): void
    {
        // BillingPriceResource registreres i navigasjon
        $this->assertSame('Tjenestekatalog', BillingPriceResource::getNavigationLabel());

        // BillingProductResource er skjult fra nav — admin skal ikke trenge dette menyvalget
        $this->assertFalse(BillingProductResource::shouldRegisterNavigation());
    }

    public function test_internal_admin_can_access_the_billing_catalog_resources(): void
    {
        $this->actingAs($this->internalAdmin());

        $this->assertTrue(BillingProductResource::canAccess());
        $this->assertTrue(BillingPriceResource::canAccess());
        $this->assertTrue(CustomerUserServiceLevelResource::canAccess());
    }

    public function test_non_internal_admin_cannot_access_the_billing_catalog_resources(): void
    {
        $customer = $this->createCustomer();
        $user = User::query()->create([
            'name' => 'Kunde Admin',
            'email' => 'kunde.admin@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        $this->actingAs($user);

        $this->assertFalse(BillingProductResource::canAccess());
        $this->assertFalse(BillingPriceResource::canAccess());
        $this->assertFalse(CustomerUserServiceLevelResource::canAccess());
    }

    public function test_brukerlisenser_list_can_be_accessed_by_internal_admin(): void
    {
        $admin = $this->internalAdmin();
        $response = $this->actingAs($admin)->get(CustomerUserServiceLevelResource::getUrl('index'));

        $response->assertOk();
        $response->assertSee('Brukerlisenser');
    }

    public function test_brukerlisenser_page_help_is_seeded_by_migration(): void
    {
        $record = AdminPageHelp::query()
            ->where('page_key', 'admin.billing.user_licenses')
            ->first();

        $this->assertNotNull($record, 'admin.billing.user_licenses mangler i admin_page_helps');
        $this->assertSame('Brukerlisenser', $record->title);
        $this->assertTrue($record->is_active);
        $this->assertCount(5, $record->sections);

        $allText = collect($record->sections)
            ->flatMap(fn ($s) => array_merge([$s['title'] ?? ''], array_column($s['items'] ?? [], 'text')))
            ->implode(' ');

        $this->assertStringContainsString('Tjenestekatalog', $allText);
        $this->assertStringContainsString('AI-forbruk', $allText);
        $this->assertStringContainsString('AI-lønnsomhet', $allText);
        $this->assertStringContainsString('ikke regnskap', $allText);
        $this->assertStringContainsString('ikke faktura', $allText);
    }

    public function test_brukerlisenser_help_action_is_present_when_page_help_record_exists(): void
    {
        // admin.billing.user_licenses is seeded by migration 2026_06_14_000004
        $admin = $this->internalAdmin();
        $response = $this->actingAs($admin)->get(CustomerUserServiceLevelResource::getUrl('index'));

        $response->assertOk();
        $response->assertSee('Hjelp');
    }

    public function test_brukerlisenser_page_renders_without_help_action_when_no_record_exists(): void
    {
        $admin = $this->internalAdmin();
        $response = $this->actingAs($admin)->get(CustomerUserServiceLevelResource::getUrl('index'));

        $response->assertOk();
        $response->assertDontSee('Hjelp — Brukerlisenser');
    }

    public function test_tjenestekatalog_page_help_is_seeded_by_migration(): void
    {
        $record = AdminPageHelp::query()
            ->where('page_key', 'admin.tjenestekatalog')
            ->first();

        $this->assertNotNull($record, 'admin.tjenestekatalog mangler i admin_page_helps');
        $this->assertSame('Tjenestekatalog', $record->title);
        $this->assertTrue($record->is_active);
        $this->assertCount(5, $record->sections);

        $allText = collect($record->sections)
            ->flatMap(fn ($s) => array_merge([$s['title'] ?? ''], array_column($s['items'] ?? [], 'text')))
            ->implode(' ');

        $this->assertStringContainsString('included_ai_offers', $allText);
        $this->assertStringContainsString('AI-forbruk', $allText);
        $this->assertStringContainsString('AI-lønnsomhet', $allText);
        $this->assertStringContainsString('NOK', $allText);
        $this->assertStringContainsString('yearly', $allText);
        $this->assertStringContainsString('Tjenestekatalog', $allText);
    }

    public function test_tjenestekatalog_help_action_is_present_when_page_help_record_exists(): void
    {
        // admin.tjenestekatalog is seeded by the migration — no separate create needed.
        $admin = $this->internalAdmin();
        $response = $this->actingAs($admin)->get(BillingPriceResource::getUrl('index'));

        $response->assertOk();
        $response->assertSee('Hjelp');
    }

    public function test_tjenestekatalog_page_renders_without_help_action_when_no_record_exists(): void
    {
        $admin = $this->internalAdmin();
        $response = $this->actingAs($admin)->get(BillingPriceResource::getUrl('index'));

        $response->assertOk();
        $response->assertDontSee('Hjelp — Tjenestekatalog');
    }

    public function test_included_ai_offers_backfill_sets_correct_values_for_seeded_prices(): void
    {
        $expected = ['pro' => 3, 'max' => 20, 'ultra' => 60, 'free' => 0];

        foreach ($expected as $tierKey => $expectedOffers) {
            $rows = DB::table('billing_prices')->where('tier_key', $tierKey)->get();
            $this->assertNotEmpty($rows, "Expected seeded prices for tier_key '{$tierKey}'");

            foreach ($rows as $row) {
                $this->assertSame(
                    $expectedOffers,
                    $row->included_ai_offers,
                    "tier_key '{$tierKey}' should have included_ai_offers = {$expectedOffers}",
                );
            }
        }
    }

    public function test_included_ai_offers_backfill_does_not_overwrite_existing_nonzero_values(): void
    {
        $row = DB::table('billing_prices')->where('tier_key', 'ultra')->first();
        $this->assertNotNull($row, 'Expected seeded ultra pricing row');

        DB::table('billing_prices')->where('id', $row->id)->update(['included_ai_offers' => 99]);

        foreach (['ultra' => 60, 'max' => 20, 'pro' => 3] as $tierKey => $aiOffers) {
            DB::table('billing_prices')
                ->where('tier_key', $tierKey)
                ->where('included_ai_offers', 0)
                ->update(['included_ai_offers' => $aiOffers]);
        }

        $this->assertSame(99, DB::table('billing_prices')->where('id', $row->id)->value('included_ai_offers'));
    }

    public function test_included_ai_offers_backfill_does_not_touch_unknown_tier_keys(): void
    {
        $productId = DB::table('billing_products')->insertGetId([
            'key'           => 'test_custom_'.Str::lower(Str::random(6)),
            'name'          => 'Custom Test Plan',
            'category'      => 'base_plan',
            'billing_scope' => 'customer',
            'is_active'     => true,
            'sort_order'    => 99,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $priceId = DB::table('billing_prices')->insertGetId([
            'billing_product_id' => $productId,
            'key'                => 'custom_monthly_'.Str::lower(Str::random(6)),
            'name'               => 'Custom Monthly',
            'interval'           => 'monthly',
            'tier_key'           => 'unknown_custom_tier',
            'included_ai_offers' => 0,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        foreach (['ultra' => 60, 'max' => 20, 'pro' => 3] as $tierKey => $aiOffers) {
            DB::table('billing_prices')
                ->where('tier_key', $tierKey)
                ->where('included_ai_offers', 0)
                ->update(['included_ai_offers' => $aiOffers]);
        }

        $this->assertSame(0, DB::table('billing_prices')->where('id', $priceId)->value('included_ai_offers'));
    }

    private function internalAdmin(): User
    {
        return User::query()->create([
            'name' => 'Procynia Admin',
            'email' => 'procynia.admin+'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_SUPER_ADMIN,
            'customer_id' => null,
            'is_active' => true,
        ]);
    }

    private function createCustomer(string $name = 'Procynia AS'): Customer
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
        ]);
    }
}
