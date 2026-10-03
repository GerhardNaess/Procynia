<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\User;
use Tests\TestCase;

/**
 * The outer boundary around Kvalitet: closed to a guest, and closed to a signed-in customer who has
 * not bought the module.
 *
 * This file used to assert that any signed-in customer user could open the module. That was true
 * when it was written and stopped being true one commit later, when package entitlements put every
 * `app.quality.` route behind EnsureModuleIsEnabled — so the test had been failing ever since,
 * asserting the absence of a gate that exists on purpose.
 *
 * What happens *inside* the gate belongs to QualityItemTest, which builds a real entitled customer.
 * What is left here is the part that needs no fixture at all.
 */
class QualityModulePageTest extends TestCase
{
    public function test_a_customer_without_the_module_is_sent_to_hjem(): void
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

        // Hjem is where a blocked request is sent, which is why it can never itself be gated.
        $this->actingAs($user)
            ->get(route('app.quality.index'))
            ->assertRedirect(route('app.dashboard'));
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(route('app.quality.index'))->assertRedirect(route('login'));
    }
}
