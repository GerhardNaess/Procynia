<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Tests\TestCase;

class CustomerFrontendAccessTest extends TestCase
{
    public function test_customer_user_is_redirected_to_customer_notices_from_root(): void
    {
        $user = new User([
            'id' => 23,
            'name' => 'Customer Admin',
            'email' => 'customer.admin@procynia.local',
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'customer_id' => 1,
            'is_active' => true,
        ]);
        $user->setRelation('customer', new Customer([
            'id' => 1,
            'name' => 'Procynia AS',
        ]));
        $user->setRelation('department', null);

        $response = $this->actingAs($user)->get('/');

        $response->assertRedirect(route('app.notices.index'));
    }

    public function test_super_admin_is_redirected_to_admin_dashboard_from_root(): void
    {
        $user = new User([
            'id' => 4,
            'name' => 'Super Admin',
            'email' => 'gerhardnaess@gmail.com',
            'role' => User::ROLE_SUPER_ADMIN,
            'customer_id' => null,
            'is_active' => true,
        ]);
        $user->setRelation('customer', null);
        $user->setRelation('department', null);

        $response = $this->actingAs($user)->get('/');

        $response->assertRedirect(route('filament.admin.pages.dashboard'));
    }

    public function test_super_admin_cannot_access_customer_notices(): void
    {
        $user = new User([
            'id' => 4,
            'name' => 'Super Admin',
            'email' => 'gerhardnaess@gmail.com',
            'role' => User::ROLE_SUPER_ADMIN,
            'customer_id' => null,
            'is_active' => true,
        ]);
        $user->setRelation('customer', null);
        $user->setRelation('department', null);

        $response = $this->actingAs($user)->get('/app/notices');

        $response->assertForbidden();
    }
}
