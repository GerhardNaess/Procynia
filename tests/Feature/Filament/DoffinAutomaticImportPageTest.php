<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\DoffinAutomaticImport;
use App\Models\Customer;
use App\Models\DoffinImportSetting;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

class DoffinAutomaticImportPageTest extends TestCase
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

    public function test_internal_admin_can_open_the_page(): void
    {
        $admin = $this->internalAdmin();

        $this->actingAs($admin)
            ->get(DoffinAutomaticImport::getUrl())
            ->assertOk()
            ->assertSee('Doffin automatisering');
    }

    public function test_customer_admin_cannot_access_the_page(): void
    {
        $customer = $this->createMinimalCustomer();

        $user = User::query()->create([
            'name' => 'Customer Admin',
            'email' => 'customer.admin+'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        $this->actingAs($user);

        $this->assertFalse(DoffinAutomaticImport::canAccess());
    }

    public function test_saving_the_toggle_persists_the_setting(): void
    {
        $admin = $this->internalAdmin();

        DoffinImportSetting::query()->create([
            'scheduled_import_enabled' => false,
            'watch_inbox_discovery_enabled' => false,
        ]);

        Livewire::actingAs($admin)
            ->test(DoffinAutomaticImport::class)
            ->set('data.scheduled_import_enabled', true)
            ->set('data.watch_inbox_discovery_enabled', true)
            ->call('save');

        $setting = DoffinImportSetting::first();

        $this->assertNotNull($setting);
        $this->assertTrue($setting->scheduled_import_enabled);
        $this->assertTrue($setting->watch_inbox_discovery_enabled);
        $this->assertSame($admin->id, $setting->updated_by);
    }

    private function internalAdmin(): User
    {
        return User::query()->create([
            'name' => 'Procynia Admin',
            'email' => 'procynia.admin+'.Str::lower(Str::random(8)).'@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_SUPER_ADMIN,
            'customer_id' => null,
            'is_active' => true,
        ]);
    }

    private function createMinimalCustomer(string $name = 'Procynia AS'): Customer
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
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
    }
}
