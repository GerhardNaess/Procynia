<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\DepartmentResource;
use App\Filament\Resources\NoticeAttentionResource;
use App\Filament\Resources\NoticeResource;
use App\Filament\Resources\WatchProfileResource;
use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class HiddenAdminResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_hidden_resources_remain_hidden_from_navigation(): void
    {
        $this->assertFalse(NoticeResource::shouldRegisterNavigation());
        $this->assertFalse(DepartmentResource::shouldRegisterNavigation());
        $this->assertFalse(WatchProfileResource::shouldRegisterNavigation());
        $this->assertFalse(NoticeAttentionResource::shouldRegisterNavigation());
    }

    public function test_internal_admin_can_open_hidden_resources_via_direct_url(): void
    {
        $admin = $this->internalAdmin();

        $this->actingAs($admin);

        foreach ($this->hiddenResources() as $resource) {
            $this->assertTrue($resource::canAccess());

            $this->actingAs($admin)
                ->get($resource::getUrl('index'))
                ->assertOk();
        }
    }

    public function test_customer_admin_is_forbidden_from_hidden_resources_via_direct_url(): void
    {
        $customerAdmin = $this->customerAdmin();

        $this->actingAs($customerAdmin);

        foreach ($this->hiddenResources() as $resource) {
            $this->assertFalse($resource::canAccess());

            $this->actingAs($customerAdmin)
                ->get($resource::getUrl('index'))
                ->assertForbidden();
        }
    }

    /**
     * @return array<int, class-string>
     */
    private function hiddenResources(): array
    {
        return [
            NoticeResource::class,
            DepartmentResource::class,
            WatchProfileResource::class,
            NoticeAttentionResource::class,
        ];
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

    private function customerAdmin(): User
    {
        $customer = $this->createCustomer();

        return User::query()->create([
            'name' => 'Kunde Admin',
            'email' => 'kunde.admin+'.Str::lower(Str::random(8)).'@example.test',
            'password' => bcrypt('SecretPass123!'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
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
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
    }
}
