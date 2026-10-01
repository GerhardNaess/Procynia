<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\User;
use Tests\TestCase;

/**
 * The Kvalitet module is a real destination in the left rail rather than a dimmed placeholder, so
 * the route behind it has to exist and be gated like every other customer page. The page itself
 * reads nothing yet; what is worth holding is that it is reachable signed in and closed signed out.
 */
class QualityModulePageTest extends TestCase
{
    public function test_a_customer_user_can_open_the_quality_module(): void
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

        $response = $this->actingAs($user)->get(route('app.quality.index'));

        $response->assertOk();
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(route('app.quality.index'))->assertRedirect(route('login'));
    }
}
