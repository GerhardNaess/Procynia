<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\BillingPriceResource;
use App\Filament\Resources\BillingProductResource;
use App\Filament\Resources\CustomerUserServiceLevelResource;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
