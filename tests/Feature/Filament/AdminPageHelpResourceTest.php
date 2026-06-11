<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\AdminPageHelpResource;
use App\Filament\Resources\AdminPageHelpResource\Pages\CreateAdminPageHelp;
use App\Filament\Resources\AdminPageHelpResource\Pages\EditAdminPageHelp;
use App\Filament\Resources\AdminPageHelpResource\Pages\ListAdminPageHelps;
use App\Models\AdminPageHelp;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminPageHelpResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_page_help_resource_navigation_metadata(): void
    {
        $this->assertSame('Hjelpetekster', AdminPageHelpResource::getNavigationLabel());
        $this->assertSame('Drift', AdminPageHelpResource::getNavigationGroup());
        $this->assertSame(9, AdminPageHelpResource::getNavigationSort());
    }

    public function test_admin_page_help_resource_exposes_expected_pages(): void
    {
        $this->assertArrayHasKey('index', AdminPageHelpResource::getPages());
        $this->assertArrayHasKey('create', AdminPageHelpResource::getPages());
        $this->assertArrayHasKey('edit', AdminPageHelpResource::getPages());
    }

    public function test_internal_admin_can_access_admin_page_help_resource(): void
    {
        $this->actingAs($this->internalAdmin());

        $this->assertTrue(AdminPageHelpResource::canAccess());
    }

    public function test_customer_admin_cannot_access_admin_page_help_resource(): void
    {
        $customer = $this->createCustomer('Testkunde');
        $user = User::factory()->create([
            'role'        => User::ROLE_CUSTOMER_ADMIN,
            'customer_id' => $customer->id,
            'is_active'   => true,
        ]);

        $this->actingAs($user);

        $this->assertFalse(AdminPageHelpResource::canAccess());
    }

    public function test_internal_admin_can_list_admin_page_helps(): void
    {
        $this->actingAs($this->internalAdmin());

        $response = $this->get(ListAdminPageHelps::getUrl());

        $response->assertOk();
        $response->assertSee('Hjelpetekster');
    }

    public function test_internal_admin_can_create_admin_page_help(): void
    {
        $this->actingAs($this->internalAdmin());

        $response = $this->get(CreateAdminPageHelp::getUrl());

        $response->assertOk();
    }

    public function test_internal_admin_can_edit_admin_page_help(): void
    {
        $record = AdminPageHelp::create([
            'page_key'  => 'admin.test_page',
            'title'     => 'Testhjelpetekst',
            'sections'  => [],
            'is_active' => true,
        ]);

        $this->actingAs($this->internalAdmin());

        $response = $this->get(EditAdminPageHelp::getUrl(['record' => $record->id]));

        $response->assertOk();
        $response->assertSee('Testhjelpetekst');
    }

    public function test_admin_page_help_model_casts_sections_as_array(): void
    {
        $record = AdminPageHelp::create([
            'page_key' => 'admin.cast_test',
            'title'    => 'Cast test',
            'sections' => [
                ['title' => 'Seksjon 1', 'items' => [['title' => 'Punkt', 'text' => 'Tekst']]],
            ],
            'is_active' => true,
        ]);

        $fresh = AdminPageHelp::find($record->id);

        $this->assertIsArray($fresh->sections);
        $this->assertCount(1, $fresh->sections);
        $this->assertSame('Seksjon 1', $fresh->sections[0]['title']);
    }

    public function test_admin_page_help_query_by_page_key_and_active(): void
    {
        AdminPageHelp::create([
            'page_key'  => 'admin.ai_forbruk',
            'title'     => 'AI-forbruk hjelp',
            'sections'  => [],
            'is_active' => true,
        ]);
        AdminPageHelp::create([
            'page_key'  => 'admin.other_page',
            'title'     => 'Annen side',
            'sections'  => [],
            'is_active' => false,
        ]);

        $result = AdminPageHelp::query()
            ->where('page_key', 'admin.ai_forbruk')
            ->where('is_active', true)
            ->first();

        $this->assertNotNull($result);
        $this->assertSame('AI-forbruk hjelp', $result->title);

        $inactive = AdminPageHelp::query()
            ->where('page_key', 'admin.other_page')
            ->where('is_active', true)
            ->first();

        $this->assertNull($inactive);
    }

    private function internalAdmin(): User
    {
        return User::factory()->create([
            'role'        => User::ROLE_SUPER_ADMIN,
            'customer_id' => null,
            'is_active'   => true,
        ]);
    }

    private function createCustomer(string $name): Customer
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
            'name'           => $name,
            'slug'           => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id'    => $language->id,
            'nationality_id' => $nationality->id,
            'is_active'      => true,
        ]);
    }
}
