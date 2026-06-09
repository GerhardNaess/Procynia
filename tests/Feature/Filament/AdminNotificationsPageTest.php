<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\AdminNotifications;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminNotificationsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_admin_can_open_admin_notifications(): void
    {
        $admin = $this->internalAdmin();

        $this->actingAs($admin)
            ->get(AdminNotifications::getUrl())
            ->assertOk()
            ->assertSee('Admin-varsler')
            ->assertSee('Ingen uleste varsler.');
    }

    public function test_customer_admin_cannot_open_admin_notifications(): void
    {
        $user = $this->customerAdmin();

        $this->actingAs($user);

        $this->assertFalse(AdminNotifications::canAccess());

        $this->actingAs($user)
            ->get(AdminNotifications::getUrl())
            ->assertForbidden();
    }

    private function internalAdmin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_SUPER_ADMIN,
            'customer_id' => null,
            'is_active' => true,
        ]);
    }

    private function customerAdmin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => null,
            'is_active' => true,
        ]);
    }
}
