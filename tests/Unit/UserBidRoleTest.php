<?php

namespace Tests\Unit;

use App\Models\User;
use Tests\TestCase;

class UserBidRoleTest extends TestCase
{
    public function test_bid_role_label_returns_expected_label(): void
    {
        $user = new User([
            'bid_role' => User::BID_ROLE_BID_MANAGER,
        ]);

        $this->assertSame('Bid Manager', $user->bid_role_label);
    }

    public function test_system_owner_role_returns_expected_label(): void
    {
        $user = new User([
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
        ]);

        $this->assertSame('System Owner', $user->bid_role_label);
    }

    public function test_missing_bid_role_resolves_to_contributor(): void
    {
        $user = new User();

        $this->assertSame(User::BID_ROLE_CONTRIBUTOR, $user->resolvedBidRole());
        $this->assertSame('Contributor', $user->bid_role_label);
    }
}
