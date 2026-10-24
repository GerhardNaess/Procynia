<?php

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Users are people. An integration identity (User::ROLE_INTEGRATION) is a users row only so the
 * services that take a User can serve the external API (docs/external-api-architecture.md §4.3).
 *
 * Every Eloquent read of users leaves it out — owner and reviewer pickers, user lists, notification
 * recipients, licence counts, sign-in (password, Entra, the session) and every relation to a user.
 * One rule instead of one filter per query: a new picker cannot forget it.
 *
 * Only the integration's own administration reads it, explicitly, through
 * User::integrationIdentities() and ApiClientCustomerGrant::integrationUser().
 */
class HumanUsersScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('role'), '!=', User::ROLE_INTEGRATION);
    }
}
