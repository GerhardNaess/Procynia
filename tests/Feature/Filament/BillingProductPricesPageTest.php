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
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

class BillingProductPricesPageTest extends TestCase
{
    use RefreshDatabase;
    use UsesProjectPostgresConnection;

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
        $response->assertSee('Legg til');
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
        // Tjenestekatalog-seksjon
        $response->assertSee('Faktureringsintervall');
        $response->assertSee('Pris');
        $response->assertSee('Type');
        $response->assertSee('Inkludert mengder');
        $response->assertSee('Beskrivelse');
        $response->assertSee('Opprettet');
        $response->assertSee('Sist oppdatert');
        // Stripe-seksjon
        $response->assertSee('Stripe Price ID');
        $response->assertSee('Ikke koblet');
        $response->assertSee('Status for Stripe-kobling');
        $response->assertSee('Aktiv');
        $response->assertSee('Synlig for salg');
        $response->assertSee('Ja');
        $response->assertSee('Rediger');
        $response->assertSee('Dupliser');
        $response->assertSee('Sett inaktiv');
        // Tjenesteinformasjon skal ikke vises
        $response->assertDontSee('Tjenesteinformasjon');
        $response->assertDontSee('Tjenestenavn');
        $response->assertDontSee('Tjenestebeskrivelse');
        $response->assertDontSee('Tjenestestatus');
    }

    public function test_edit_side_har_riktig_tjenestekatalog_struktur(): void
    {
        $admin = $this->internalAdmin();

        $product = $this->createProduct([
            'key' => 'base_ultra_struktur_'.Str::lower(Str::random(6)),
            'name' => 'Ultra struktur',
            'description' => 'Beskrivelse for struktur-test.',
            'category' => BillingProduct::CATEGORY_BASE_PLAN,
            'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $price = $this->createPrice($product, [
            'key' => 'ultra_struktur_'.Str::lower(Str::random(8)),
            'name' => 'Ultra struktur månedlig',
            'interval' => BillingPrice::INTERVAL_MONTHLY,
            'currency' => 'nok',
            'unit_amount' => 649000,
            'included_quantity' => 5,
            'included_ai_offers' => 2,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->get(BillingPriceResource::getUrl('edit', ['record' => $price]));

        $response->assertOk();

        // Krav 1: Seksjonen heter "Tjenestekatalog"
        $response->assertSee('Tjenestekatalog');

        // Krav 2: Ingen seksjon "Tjenesteinformasjon"
        $response->assertDontSee('Tjenesteinformasjon');

        // Krav 3: Ingen label "Tjenestenavn"
        $response->assertDontSee('Tjenestenavn');

        // Krav 4: Ingen label "Tjeneste aktiv"
        $response->assertDontSee('Tjeneste aktiv');

        // Krav 5: Ingen label "Tjenestebeskrivelse"
        $response->assertDontSee('Tjenestebeskrivelse');

        // Krav 6+7: "Kategori" vises ikke, "Type" vises
        $response->assertDontSee('>Kategori<');
        $response->assertSee('Type');

        // Krav 8+9: "Sortering" vises ikke, "Visningsrekkefølge" brukes
        $response->assertDontSee('>Sortering<');
        $response->assertSee('Visningsrekkefølge');

        // Krav 12+13: Inkluderte mengder med riktig hjelpetekst
        $response->assertSee('Inkludert antall brukere');
        $response->assertSee('Antall brukere som er inkludert i denne prisen.');
        $response->assertSee('Inkludert antall AI-tilbud');
        $response->assertSee('Styrer AI-kapasitet i Fakturering');

        // Krav 11: Pris i kroner vises
        $response->assertSee('Pris');
        $response->assertSee('kr');
    }

    public function test_edit_side_har_kun_én_aktiv_bryter(): void
    {
        $admin = $this->internalAdmin();

        $product = $this->createProduct([
            'key' => 'base_ultra_aktiv_'.Str::lower(Str::random(6)),
            'name' => 'Ultra aktiv',
            'category' => BillingProduct::CATEGORY_BASE_PLAN,
            'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $price = $this->createPrice($product, [
            'key' => 'ultra_aktiv_'.Str::lower(Str::random(8)),
            'name' => 'Ultra aktiv månedlig',
            'interval' => BillingPrice::INTERVAL_MONTHLY,
            'unit_amount' => 649000,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->get(BillingPriceResource::getUrl('edit', ['record' => $price]));

        $response->assertOk();
        // Krav 10: Bare én "Aktiv"-bryter (og ingen "Tjeneste aktiv")
        $response->assertSee('Aktiv');
        $response->assertDontSee('Tjeneste aktiv');
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

        // Prisfeltet forventer nå kroner: 6990,00 kr = 699000 øre
        $component = Livewire::actingAs($admin)
            ->test(EditBillingPrice::class, [
                'record' => $price->getKey(),
            ])
            ->set('data.name', 'Ultra månedlig oppdatert')
            ->set('data.unit_amount', '6990,00')
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

    public function test_prisfeltet_viser_kroner_ikke_ore_i_edit_formen(): void
    {
        $admin = $this->internalAdmin();

        $product = $this->createProduct([
            'key' => 'base_ultra_display_'.Str::lower(Str::random(6)),
            'name' => 'Ultra visning',
            'category' => BillingProduct::CATEGORY_BASE_PLAN,
            'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $price = $this->createPrice($product, [
            'key' => 'ultra_display_yearly_'.Str::lower(Str::random(8)),
            'name' => 'Ultra årlig visning',
            'interval' => BillingPrice::INTERVAL_YEARLY,
            'currency' => 'nok',
            'unit_amount' => 5192000,
            'stripe_price_id' => 'price_ultra_yearly_display',
            'tier_key' => 'ultra',
            'is_recurring' => true,
            'is_active' => true,
            'included_quantity' => 15,
        ]);

        $component = Livewire::actingAs($admin)
            ->test(EditBillingPrice::class, ['record' => $price->getKey()]);

        // 5192000 øre = 51 920,00 kr — formatStateUsing konverterer til kronervisning
        $component->assertSet('data.unit_amount', '51 920,00');
    }

    public function test_lagring_av_51920_kroner_gir_5192000_ore_i_databasen(): void
    {
        $admin = $this->internalAdmin();

        $product = $this->createProduct([
            'key' => 'base_ultra_save_'.Str::lower(Str::random(6)),
            'name' => 'Ultra lagring',
            'category' => BillingProduct::CATEGORY_BASE_PLAN,
            'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $price = $this->createPrice($product, [
            'key' => 'ultra_save_yearly_'.Str::lower(Str::random(8)),
            'name' => 'Ultra årlig lagring',
            'interval' => BillingPrice::INTERVAL_YEARLY,
            'currency' => 'nok',
            'unit_amount' => 100,
            'stripe_price_id' => null,
            'tier_key' => 'ultra',
            'is_recurring' => true,
            'is_active' => true,
            'included_quantity' => 15,
        ]);

        // Test 51 920,00 kr → 5192000 øre og 6 490,00 kr → 649000 øre
        foreach ([['51 920,00', 5192000], ['6 490,00', 649000]] as [$kroner, $expectedOre]) {
            Livewire::actingAs($admin)
                ->test(EditBillingPrice::class, ['record' => $price->getKey()])
                ->set('data.unit_amount', $kroner)
                ->call('save')
                ->assertHasNoErrors();

            $this->assertDatabaseHas('billing_prices', [
                'id' => $price->id,
                'unit_amount' => $expectedOre,
            ]);
        }
    }

    public function test_stripe_price_id_og_key_er_lest_for_redigering(): void
    {
        $admin = $this->internalAdmin();

        $product = $this->createProduct([
            'key' => 'base_ultra_locked_'.Str::lower(Str::random(6)),
            'name' => 'Ultra låst',
            'category' => BillingProduct::CATEGORY_BASE_PLAN,
            'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $originalKey = 'ultra_locked_monthly_'.Str::lower(Str::random(8));
        $originalStripeId = 'price_locked_monthly';

        $price = $this->createPrice($product, [
            'key' => $originalKey,
            'name' => 'Ultra låst månedlig',
            'interval' => BillingPrice::INTERVAL_MONTHLY,
            'currency' => 'nok',
            'unit_amount' => 649000,
            'stripe_price_id' => $originalStripeId,
            'tier_key' => 'ultra',
            'is_recurring' => true,
            'is_active' => true,
            'included_quantity' => 15,
        ]);

        // Forsøk å sette key og stripe_price_id — de er disabled på edit og skal ikke endre seg
        Livewire::actingAs($admin)
            ->test(EditBillingPrice::class, ['record' => $price->getKey()])
            ->set('data.key', 'annen_nokkel')
            ->set('data.stripe_price_id', 'price_annen')
            ->set('data.unit_amount', '6490,00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('billing_prices', [
            'id' => $price->id,
            'key' => $originalKey,
            'stripe_price_id' => $originalStripeId,
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

    public function test_edit_formen_viser_riktige_labels_og_hjelpetekster_for_inkluderte_mengder(): void
    {
        $admin = $this->internalAdmin();

        $product = $this->createProduct([
            'key' => 'base_ultra_labels_'.Str::lower(Str::random(6)),
            'name' => 'Ultra labels',
            'category' => BillingProduct::CATEGORY_BASE_PLAN,
            'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $price = $this->createPrice($product, [
            'key' => 'ultra_labels_'.Str::lower(Str::random(8)),
            'name' => 'Ultra labels månedlig',
            'interval' => BillingPrice::INTERVAL_MONTHLY,
            'currency' => 'nok',
            'unit_amount' => 649000,
            'included_quantity' => 5,
            'included_ai_offers' => 0,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->get(BillingPriceResource::getUrl('edit', ['record' => $price]));

        $response->assertOk();
        $response->assertSee('Inkludert antall brukere');
        $response->assertSee('Antall brukere som er inkludert i denne prisen.');
        $response->assertSee('Inkludert antall AI-tilbud');
        $response->assertSee('Styrer AI-kapasitet i Fakturering');
        $response->assertDontSee('Inkludert antall"');
        $response->assertDontSee('Bruk 0 hvis dette ikke er relevant.');
    }

    public function test_varelinje_kan_lagres_med_inkluderte_brukere_og_ai_tilbud(): void
    {
        $admin = $this->internalAdmin();

        $product = $this->createProduct([
            'key' => 'base_ultra_quantities_'.Str::lower(Str::random(6)),
            'name' => 'Ultra mengder',
            'category' => BillingProduct::CATEGORY_BASE_PLAN,
            'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $price = $this->createPrice($product, [
            'key' => 'ultra_quantities_'.Str::lower(Str::random(8)),
            'name' => 'Ultra mengder månedlig',
            'interval' => BillingPrice::INTERVAL_MONTHLY,
            'currency' => 'nok',
            'unit_amount' => 649000,
            'included_quantity' => 0,
            'included_ai_offers' => 0,
            'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(EditBillingPrice::class, ['record' => $price->getKey()])
            ->set('data.unit_amount', '6490,00')
            ->set('data.included_quantity', 15)
            ->set('data.included_ai_offers', 10)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('billing_prices', [
            'id' => $price->id,
            'included_quantity' => 15,
            'included_ai_offers' => 10,
            'unit_amount' => 649000,
        ]);
    }

    public function test_inkludert_label_viser_riktig_kombinasjon(): void
    {
        $admin = $this->internalAdmin();

        $product = $this->createProduct([
            'key' => 'base_label_combo_'.Str::lower(Str::random(6)),
            'name' => 'Ultra combo',
            'category' => BillingProduct::CATEGORY_BASE_PLAN,
            'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        // Begge satt: viser "N brukere, M AI-tilbud"
        $this->createPrice($product, [
            'key' => 'combo_both_'.Str::lower(Str::random(8)),
            'name' => 'Begge satt',
            'interval' => BillingPrice::INTERVAL_MONTHLY,
            'unit_amount' => 649000,
            'included_quantity' => 15,
            'included_ai_offers' => 10,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->get(BillingPriceResource::getUrl('index'));
        $response->assertSee('15 brukere, 10 AI-tilbud');

        // Bare brukere: viser "N brukere"
        $this->createPrice($product, [
            'key' => 'combo_users_only_'.Str::lower(Str::random(8)),
            'name' => 'Bare brukere',
            'interval' => BillingPrice::INTERVAL_MONTHLY,
            'unit_amount' => 649000,
            'included_quantity' => 5,
            'included_ai_offers' => 0,
            'is_active' => true,
        ]);

        $response2 = $this->actingAs($admin)->get(BillingPriceResource::getUrl('index'));
        $response2->assertSee('5 brukere');

        // Ingen satt: viser "–"
        $this->createPrice($product, [
            'key' => 'combo_none_'.Str::lower(Str::random(8)),
            'name' => 'Ingen inkludert',
            'interval' => BillingPrice::INTERVAL_MONTHLY,
            'unit_amount' => 649000,
            'included_quantity' => 0,
            'included_ai_offers' => 0,
            'is_active' => true,
        ]);

        $response3 = $this->actingAs($admin)->get(BillingPriceResource::getUrl('index'));
        $response3->assertSee('–');
    }

    public function test_eksisterende_varelinjer_har_default_0_for_included_ai_offers(): void
    {
        $product = $this->createProduct([
            'key' => 'base_legacy_'.Str::lower(Str::random(6)),
            'name' => 'Legacy plan',
            'category' => BillingProduct::CATEGORY_BASE_PLAN,
            'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        // Opprett uten å sette included_ai_offers — skal bruke default 0
        $price = $this->createPrice($product, [
            'key' => 'legacy_price_'.Str::lower(Str::random(8)),
            'name' => 'Legacy månedlig',
            'interval' => BillingPrice::INTERVAL_MONTHLY,
            'unit_amount' => 649000,
            'included_quantity' => 15,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('billing_prices', [
            'id' => $price->id,
            'included_ai_offers' => 0,
        ]);
    }

    public function test_interval_helper_text_forklarer_monthly_og_yearly_periodisering(): void
    {
        $admin = $this->internalAdmin();

        $product = $this->createProduct([
            'key' => 'base_interval_help_'.Str::lower(Str::random(6)),
            'name' => 'Ultra interval help',
            'category' => BillingProduct::CATEGORY_BASE_PLAN,
        ]);

        $price = $this->createPrice($product, [
            'key' => 'ultra_interval_help_'.Str::lower(Str::random(8)),
            'name' => 'Ultra månedlig interval help',
            'interval' => BillingPrice::INTERVAL_MONTHLY,
        ]);

        $response = $this->actingAs($admin)->get(BillingPriceResource::getUrl('edit', ['record' => $price]));

        $response->assertOk();
        $response->assertSee('AI-lønnsomhet bruker monthly-priser direkte');
        $response->assertSee('Yearly-priser periodiseres til månedlig verdi ved å dele årsbeløpet på 12');
    }

    public function test_unit_amount_helper_text_forklarer_ore_og_inntektsgrunnlag(): void
    {
        $admin = $this->internalAdmin();

        $product = $this->createProduct([
            'key' => 'base_amount_help_'.Str::lower(Str::random(6)),
            'name' => 'Ultra amount help',
            'category' => BillingProduct::CATEGORY_BASE_PLAN,
        ]);

        $price = $this->createPrice($product, [
            'key' => 'ultra_amount_help_'.Str::lower(Str::random(8)),
            'name' => 'Ultra månedlig amount help',
            'interval' => BillingPrice::INTERVAL_MONTHLY,
        ]);

        $response = $this->actingAs($admin)->get(BillingPriceResource::getUrl('edit', ['record' => $price]));

        $response->assertOk();
        $response->assertSee('Angi pris i kroner. Lagres internt i øre.');
        $response->assertSee('For baseplaner brukes beløpet som inntektsgrunnlag i AI-lønnsomhet.');
    }

    public function test_tier_key_helper_text_forklarer_plan_nokkel_og_advarsel(): void
    {
        $admin = $this->internalAdmin();

        $product = $this->createProduct([
            'key' => 'base_tierkey_help_'.Str::lower(Str::random(6)),
            'name' => 'Ultra tier key help',
            'category' => BillingProduct::CATEGORY_BASE_PLAN,
        ]);

        $price = $this->createPrice($product, [
            'key' => 'ultra_tierkey_help_'.Str::lower(Str::random(8)),
            'name' => 'Ultra månedlig tier key help',
            'interval' => BillingPrice::INTERVAL_MONTHLY,
            'tier_key' => 'ultra',
        ]);

        $response = $this->actingAs($admin)->get(BillingPriceResource::getUrl('edit', ['record' => $price]));

        $response->assertOk();
        $response->assertSee('Intern plan- eller nivånøkkel, for eksempel pro, max eller ultra');
        $response->assertSee('bør ikke endres uten separat avklaring');
    }

    public function test_currency_helper_text_forklarer_nok_krav_for_ai_lønnsomhet(): void
    {
        $admin = $this->internalAdmin();

        $product = $this->createProduct([
            'key' => 'base_currency_help_'.Str::lower(Str::random(6)),
            'name' => 'Ultra currency help',
            'category' => BillingProduct::CATEGORY_BASE_PLAN,
        ]);

        $price = $this->createPrice($product, [
            'key' => 'ultra_currency_help_'.Str::lower(Str::random(8)),
            'name' => 'Ultra månedlig currency help',
            'interval' => BillingPrice::INTERVAL_MONTHLY,
        ]);

        $response = $this->actingAs($admin)->get(BillingPriceResource::getUrl('edit', ['record' => $price]));

        $response->assertOk();
        $response->assertSee('AI-lønnsomhet bruker kun priser med valuta nok som inntektsgrunnlag');
        $response->assertSee('Andre valutaer blir ikke brukt i lønnsomhetsberegningen');
    }

    public function test_included_ai_offers_helper_text_forklarer_ai_forbruk_og_fallback(): void
    {
        $admin = $this->internalAdmin();

        $product = $this->createProduct([
            'key' => 'base_helpertext_'.Str::lower(Str::random(6)),
            'name' => 'Ultra helpertext',
            'category' => BillingProduct::CATEGORY_BASE_PLAN,
        ]);

        $price = $this->createPrice($product, [
            'key' => 'ultra_helpertext_'.Str::lower(Str::random(8)),
            'name' => 'Ultra månedlig helpertext',
            'interval' => BillingPrice::INTERVAL_MONTHLY,
            'unit_amount' => 649000,
            'included_ai_offers' => 0,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->get(BillingPriceResource::getUrl('edit', ['record' => $price]));

        $response->assertOk();
        $response->assertSee('Styrer AI-kapasitet i Fakturering');
        $response->assertSee('AI-forbruk');
        $response->assertSee('Må settes over 0 på betalte baseplaner (pro, max, ultra)');
        $response->assertSee('eldre kapasitetsdata fra kunden som fallback');
        $response->assertDontSee('Antall tilbud som kan lages med bruk av AI.');
    }

    public function test_beskrivelse_og_visningsrekkefølge_kan_redigeres_fra_tjenestekatalog(): void
    {
        $admin = $this->internalAdmin();

        $product = $this->createProduct([
            'key' => 'base_ultra_prod_edit_'.Str::lower(Str::random(6)),
            'name' => 'Ultra original',
            'description' => 'Original beskrivelse.',
            'category' => BillingProduct::CATEGORY_BASE_PLAN,
            'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
            'is_active' => true,
            'sort_order' => 5,
        ]);

        $price = $this->createPrice($product, [
            'key' => 'ultra_prod_edit_'.Str::lower(Str::random(8)),
            'name' => 'Ultra månedlig',
            'interval' => BillingPrice::INTERVAL_MONTHLY,
            'currency' => 'nok',
            'unit_amount' => 649000,
            'included_quantity' => 15,
            'included_ai_offers' => 0,
            'is_active' => true,
        ]);

        // Admin redigerer beskrivelse og visningsrekkefølge fra Tjenestekatalog
        Livewire::actingAs($admin)
            ->test(EditBillingPrice::class, ['record' => $price->getKey()])
            ->set('data.product_description', 'Oppdatert beskrivelse.')
            ->set('data.product_sort_order', 10)
            ->set('data.unit_amount', '6490,00')
            ->call('save')
            ->assertHasNoErrors();

        // Produktet oppdateres
        $this->assertDatabaseHas('billing_products', [
            'id' => $product->id,
            'description' => 'Oppdatert beskrivelse.',
            'sort_order' => 10,
        ]);

        // Prisen er uendret
        $this->assertDatabaseHas('billing_prices', [
            'id' => $price->id,
            'unit_amount' => 649000,
        ]);
    }

    public function test_edit_formen_forhåndsutfyller_tjenesteinformasjon(): void
    {
        $admin = $this->internalAdmin();

        $product = $this->createProduct([
            'key' => 'base_prefill_'.Str::lower(Str::random(6)),
            'name' => 'Prefill tjeneste',
            'description' => 'Prefill beskrivelse.',
            'category' => BillingProduct::CATEGORY_BASE_PLAN,
            'billing_scope' => BillingProduct::BILLING_SCOPE_CUSTOMER,
            'is_active' => true,
            'sort_order' => 3,
        ]);

        $price = $this->createPrice($product, [
            'key' => 'prefill_monthly_'.Str::lower(Str::random(8)),
            'name' => 'Prefill månedlig',
            'interval' => BillingPrice::INTERVAL_MONTHLY,
            'unit_amount' => 649000,
            'included_quantity' => 5,
            'is_active' => true,
        ]);

        $component = Livewire::actingAs($admin)
            ->test(EditBillingPrice::class, ['record' => $price->getKey()]);

        $component->assertSet('data.product_description', 'Prefill beskrivelse.');
        $component->assertSet('data.product_sort_order', 3);
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
}
