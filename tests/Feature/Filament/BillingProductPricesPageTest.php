<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\BillingPriceResource;
use App\Filament\Resources\BillingPriceResource\Pages\EditBillingPrice;
use App\Filament\Resources\BillingProductResource;
use App\Models\BillingPrice;
use App\Models\BillingProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class BillingProductPricesPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
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

    public function test_tjenestekatalog_list_page_shows_sellable_line_items_and_statuses(): void
    {
        $admin = $this->internalAdmin();

        $product = $this->createProduct([
            'key' => 'base_ultra',
            'name' => 'Ultra',
            'description' => 'Grunnabonnement for større tilbudsteam.',
            'category' => BillingProduct::CATEGORY_BASE_PLAN,
            'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $addon = $this->createProduct([
            'key' => 'onboarding',
            'name' => 'Onboarding',
            'description' => 'Engangstjeneste for oppstart og innføring.',
            'category' => BillingProduct::CATEGORY_ONE_OFF,
            'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $this->createPrice($product, [
            'key' => 'ultra_monthly_'.Str::lower(Str::random(8)),
            'name' => 'Ultra månedlig',
            'interval' => BillingPrice::INTERVAL_MONTHLY,
            'currency' => 'nok',
            'unit_amount' => 649000,
            'stripe_price_id' => 'price_ultra_monthly',
            'tier_key' => 'ultra',
            'is_recurring' => true,
            'is_active' => true,
            'included_quantity' => 15,
        ]);

        $this->createPrice($product, [
            'key' => 'ultra_yearly_'.Str::lower(Str::random(8)),
            'name' => 'Ultra årlig',
            'interval' => BillingPrice::INTERVAL_YEARLY,
            'currency' => 'nok',
            'unit_amount' => 5192000,
            'stripe_price_id' => 'price_ultra_yearly',
            'tier_key' => 'ultra',
            'is_recurring' => true,
            'is_active' => false,
            'included_quantity' => 15,
        ]);

        $this->createPrice($addon, [
            'key' => 'onboarding_one_time_'.Str::lower(Str::random(8)),
            'name' => 'Onboarding',
            'interval' => BillingPrice::INTERVAL_ONE_TIME,
            'currency' => 'nok',
            'unit_amount' => 490000,
            'stripe_price_id' => null,
            'tier_key' => 'standard',
            'is_recurring' => false,
            'is_active' => true,
            'included_quantity' => 0,
        ]);

        $response = $this->actingAs($admin)->get(BillingPriceResource::getUrl('index'));

        $response->assertOk();
        $response->assertSee('Tjenestekatalog');
        $response->assertSee('Ny varelinje');
        $response->assertSee('Ultra månedlig');
        $response->assertSee('Ultra årlig');
        $response->assertSee('Onboarding');
        $response->assertSee('Abonnementsplan');
        $response->assertSee('Engangstjeneste');
        $response->assertSee('Månedlig');
        $response->assertSee('Årlig');
        $response->assertSee('Engangskjøp');
        $response->assertSee('6 490 kr / mnd');
        $response->assertSee('51 920 kr / år');
        $response->assertSee('4 900 kr');
        $response->assertSee('15 brukere');
        $response->assertSee('–');
        $response->assertSee('Aktiv');
        $response->assertSee('Inaktiv');
    }

    public function test_varelinje_detail_page_shows_information_stripe_and_status_cards(): void
    {
        $admin = $this->internalAdmin();

        $product = $this->createProduct([
            'key' => 'base_ultra_detail',
            'name' => 'Ultra detalj',
            'description' => 'Grunnabonnement for større tilbudsteam.',
            'category' => BillingProduct::CATEGORY_BASE_PLAN,
            'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $price = $this->createPrice($product, [
            'key' => 'ultra_detail_monthly_'.Str::lower(Str::random(8)),
            'name' => 'Ultra månedlig detalj',
            'interval' => BillingPrice::INTERVAL_MONTHLY,
            'currency' => 'nok',
            'unit_amount' => 649000,
            'stripe_price_id' => null,
            'tier_key' => 'ultra',
            'is_recurring' => true,
            'is_active' => true,
            'included_quantity' => 15,
        ]);

        $response = $this->actingAs($admin)->get(BillingPriceResource::getUrl('view', ['record' => $price]));

        $response->assertOk();
        $response->assertSee('Ultra månedlig detalj');
        $response->assertSee('Abonnementsplan');
        $response->assertSee('Tilhører plan');
        $response->assertSee('Faktureringsintervall');
        $response->assertSee('Pris');
        $response->assertSee('Inkludert antall');
        $response->assertSee('Beskrivelse');
        $response->assertSee('Opprettet');
        $response->assertSee('Sist oppdatert');
        $response->assertSee('Stripe Price ID');
        $response->assertSee('Ikke koblet');
        $response->assertSee('Status for Stripe-kobling');
        $response->assertSee('Aktiv');
        $response->assertSee('Synlig for salg');
        $response->assertSee('Ja');
        $response->assertSee('Rediger');
        $response->assertSee('Dupliser');
        $response->assertSee('Sett inaktiv');
    }

    public function test_varelinje_edit_page_lager_endringer(): void
    {
        $admin = $this->internalAdmin();

        $product = $this->createProduct([
            'key' => 'base_ultra_edit',
            'name' => 'Ultra rediger',
            'description' => 'Grunnabonnement for større tilbudsteam.',
            'category' => BillingProduct::CATEGORY_BASE_PLAN,
            'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $price = $this->createPrice($product, [
            'key' => 'ultra_edit_monthly_'.Str::lower(Str::random(8)),
            'name' => 'Ultra månedlig rediger',
            'interval' => BillingPrice::INTERVAL_MONTHLY,
            'currency' => 'nok',
            'unit_amount' => 649000,
            'stripe_price_id' => 'price_ultra_edit_monthly',
            'tier_key' => 'ultra',
            'is_recurring' => true,
            'is_active' => true,
            'included_quantity' => 15,
        ]);

        $component = Livewire::actingAs($admin)
            ->test(EditBillingPrice::class, [
                'record' => $price->getKey(),
            ])
            ->set('data.name', 'Ultra månedlig oppdatert')
            ->set('data.unit_amount', 699000)
            ->set('data.included_quantity', 20)
            ->set('data.is_active', false)
            ->call('save');

        $component->assertHasNoErrors();
        $component->assertRedirect(BillingPriceResource::getUrl('view', ['record' => $price]));

        $this->assertDatabaseHas('billing_prices', [
            'id' => $price->id,
            'name' => 'Ultra månedlig oppdatert',
            'unit_amount' => 699000,
            'included_quantity' => 20,
            'is_active' => false,
        ]);
    }

    public function test_create_varelinje_page_prefills_selected_plan(): void
    {
        $admin = $this->internalAdmin();

        $product = $this->createProduct([
            'key' => 'addon_priority_support_prefill',
            'name' => 'Prioritert support',
            'description' => 'Kundespesifikk støttepakke.',
            'category' => BillingProduct::CATEGORY_ADDON,
            'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $response = $this->actingAs($admin)->get(BillingPriceResource::getUrl('create', [
            'billing_product_id' => $product,
        ]));

        $response->assertOk();
        $response->assertSee('Forhåndsvalgt fra tjenestekatalogen.');
        $response->assertSee('Prioritert support');
        $response->assertSee('Plan / tjeneste');
    }

    public function test_plan_side_no_longer_shows_price_section(): void
    {
        $admin = $this->internalAdmin();

        $product = $this->createProduct([
            'key' => 'base_ultra_no_prices',
            'name' => 'Ultra uten prisblokk',
            'description' => 'Grunnabonnement for større tilbudsteam.',
            'category' => BillingProduct::CATEGORY_BASE_PLAN,
            'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->createPrice($product, [
            'key' => 'ultra_no_prices_monthly_'.Str::lower(Str::random(8)),
            'name' => 'Ultra månedlig uten prisblokk',
            'interval' => BillingPrice::INTERVAL_MONTHLY,
            'currency' => 'nok',
            'unit_amount' => 649000,
            'stripe_price_id' => 'price_ultra_no_prices_monthly',
            'tier_key' => 'ultra',
            'is_recurring' => true,
            'is_active' => true,
            'included_quantity' => 15,
        ]);

        $editResponse = $this->actingAs($admin)->get(BillingProductResource::getUrl('edit', ['record' => $product]));
        $viewResponse = $this->actingAs($admin)->get(BillingProductResource::getUrl('view', ['record' => $product]));

        $editResponse->assertOk();
        $viewResponse->assertOk();
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createProduct(array $attributes): BillingProduct
    {
        return BillingProduct::query()->create([
            'description' => '',
            'metadata' => [],
            'name' => 'Tjeneste',
            'key' => 'service_'.Str::lower(Str::random(8)),
            'category' => BillingProduct::CATEGORY_ADDON,
            'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
            'is_active' => true,
            'sort_order' => 0,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createPrice(BillingProduct $product, array $attributes): BillingPrice
    {
        return BillingPrice::query()->create([
            'billing_product_id' => $product->id,
            'metadata' => [],
            'currency' => 'nok',
            'unit_amount' => 0,
            'included_quantity' => 0,
            'is_recurring' => true,
            'is_active' => true,
            'key' => $product->key.'_'.Str::lower(Str::random(8)),
            'name' => 'Pris',
            'interval' => BillingPrice::INTERVAL_MONTHLY,
            ...$attributes,
        ]);
    }

    private function useProjectPostgresConnection(): void
    {
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => 'procynia_test',
            'database.connections.pgsql.host' => '127.0.0.1',
            'database.connections.pgsql.port' => '5432',
            'database.connections.pgsql.username' => 'gehard',
            'database.connections.pgsql.password' => '',
            'database.connections.pgsql.search_path' => 'public',
        ]);

        DB::purge('pgsql');
        DB::setDefaultConnection('pgsql');
        DB::reconnect('pgsql');
    }
}
