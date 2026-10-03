<?php

namespace Tests\Concerns;

use App\Models\Customer;
use App\Models\CustomerRole;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Support\Str;

/**
 * Give a user a customer role holding the whole Wiki permission catalogue.
 *
 * Enterprise Wiki is gated by the customer's own roles on top of its own authority model (see
 * WikiPermissionTest, which is where the permissions themselves are varied). Tests that are about
 * that authority model — ownership, the reviewer handover, the four-eyes rule, statuses — start
 * from a person the customer has given Wiki work to, and the behaviour under test is whatever the
 * Wiki's own rules then decide.
 */
trait GrantsWikiPermissions
{
    protected function grantWikiPermissions(Customer|int $customer, User $user): User
    {
        $customerId = $customer instanceof Customer ? (int) $customer->id : $customer;

        $role = CustomerRole::query()->create([
            'customer_id' => $customerId,
            'name' => 'Wiki '.Str::upper(Str::random(8)),
            'is_active' => true,
        ]);

        $role->syncPermissions([
            CustomerPermissionCatalog::WIKI_VIEW,
            CustomerPermissionCatalog::WIKI_EDIT,
            CustomerPermissionCatalog::WIKI_REVIEW,
            CustomerPermissionCatalog::WIKI_APPROVE,
            CustomerPermissionCatalog::WIKI_DELETE,
            CustomerPermissionCatalog::WIKI_SOURCE_MANAGE,
        ]);

        $user->customerRoles()->attach($role->id, ['customer_id' => $customerId]);

        return $user;
    }
}
