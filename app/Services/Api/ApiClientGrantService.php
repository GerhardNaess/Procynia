<?php

namespace App\Services\Api;

use App\Models\ApiClient;
use App\Models\ApiClientCustomerGrant;
use App\Models\CustomerAccessChange;
use App\Models\CustomerRole;
use App\Models\User;
use App\Services\Security\CustomerAccessChangeRecorder;
use App\Services\Security\HumanDecisionGuard;
use App\Support\ApiOperationCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A customer's System Owner gives an approved client application read access to the customer,
 * changes which of the customer's roles it holds, and takes the access back (D13, points 2–6).
 *
 * Only the customer's own System Owner, acting interactively, may do any of it — the rule lives
 * here, so no entry point can skip it — and only for their own customer: the customer is always the
 * System Owner's own, never one named in the request. Every change is a customer_access_changes
 * row with its actor.
 *
 * Giving access creates the grant's integration identity (role = integration), which holds the
 * chosen customer roles. What it can then read is the customer's own rules applied to it: read keys
 * only (CustomerPermissionCatalog::integrationPermissions()), the roles' fagområder, and only the
 * modules the customer has ordered. Revoking deactivates the identity, and the grant stays as
 * history; the application is refused on its next call.
 *
 * The System Owner may also switch named write operations on for a grant (ApiOperationCatalog).
 * Roles never give one: they were chosen for reading. The choice is recorded like the roles.
 */
class ApiClientGrantService
{
    public function __construct(
        private readonly HumanDecisionGuard $guard,
        private readonly CustomerAccessChangeRecorder $recorder,
    ) {}

    public function mayAdminister(?User $actor): bool
    {
        return $actor instanceof User
            && $this->guard->allows($actor)
            && $actor->canAccessCustomerFrontend()
            && $actor->isSystemOwner();
    }

    /**
     * @param  list<int>  $customerRoleIds
     */
    public function grant(?User $actor, int $apiClientId, array $customerRoleIds): ApiClientCustomerGrant
    {
        $actor = $this->authorize($actor);
        $customerId = (int) $actor->customer_id;

        return DB::transaction(function () use ($actor, $customerId, $apiClientId, $customerRoleIds): ApiClientCustomerGrant {
            $client = ApiClient::query()->whereKey($apiClientId)->lockForUpdate()->first();

            if ($client === null || ! $client->is_enabled) {
                throw ValidationException::withMessages(['api_client_id' => __('procynia.customer_env.integrations.errors.client_unavailable')]);
            }

            $exists = ApiClientCustomerGrant::query()
                ->active()
                ->where('api_client_id', $client->id)
                ->where('customer_id', $customerId)
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages(['api_client_id' => __('procynia.customer_env.integrations.errors.already_granted')]);
            }

            $roleIds = $this->validRoleIds($customerId, $customerRoleIds);

            $identity = new User;
            $identity->forceFill([
                'name' => __('procynia.customer_env.integrations.identity_name', ['client' => $client->name]),
                'email' => 'integration-'.Str::uuid().'@integrations.procynia.invalid',
                // Never used: an integration identity cannot sign in (HumanUsersScope).
                'password' => Hash::make(Str::random(64)),
                'role' => User::ROLE_INTEGRATION,
                'customer_id' => $customerId,
                'is_active' => true,
            ])->save();

            $identity->customerRoles()->sync(array_fill_keys($roleIds, ['customer_id' => $customerId]));

            $grant = ApiClientCustomerGrant::query()->create([
                'api_client_id' => $client->id,
                'customer_id' => $customerId,
                'integration_user_id' => $identity->id,
                'granted_by_user_id' => $actor->id,
                'granted_at' => now(),
            ]);

            $this->record($actor, $grant, CustomerAccessChangeRecorder::API_GRANT_CREATED, null, $this->snapshot($grant));

            return $grant;
        });
    }

    /**
     * @param  list<int>  $customerRoleIds
     */
    public function updateRoles(?User $actor, int $grantId, array $customerRoleIds): ApiClientCustomerGrant
    {
        $actor = $this->authorize($actor);

        return DB::transaction(function () use ($actor, $grantId, $customerRoleIds): ApiClientCustomerGrant {
            $grant = $this->ownActiveGrant($actor, $grantId);
            $roleIds = $this->validRoleIds((int) $grant->customer_id, $customerRoleIds);
            $before = $this->snapshot($grant);

            $grant->integrationUser->customerRoles()->sync(array_fill_keys($roleIds, ['customer_id' => (int) $grant->customer_id]));

            $this->record($actor, $grant, CustomerAccessChangeRecorder::API_GRANT_ROLES_CHANGED, $before, $this->snapshot($grant));

            return $grant;
        });
    }

    /**
     * Which write operations the grant allows (ApiOperationCatalog). The whole list is replaced; an
     * operation outside the catalog refuses the whole change. Switching one on gives nothing by
     * itself — the token still needs the write app role, and the domain still applies its rules.
     *
     * @param  list<string>  $operations
     */
    public function updateOperations(?User $actor, int $grantId, array $operations): ApiClientCustomerGrant
    {
        $actor = $this->authorize($actor);

        return DB::transaction(function () use ($actor, $grantId, $operations): ApiClientCustomerGrant {
            $grant = $this->ownActiveGrant($actor, $grantId);
            $requested = array_values(array_unique(array_map('strval', $operations)));

            foreach ($requested as $operation) {
                if (! ApiOperationCatalog::exists($operation)) {
                    throw ValidationException::withMessages(['allowed_operations' => __('procynia.customer_env.integrations.errors.invalid_operations')]);
                }
            }

            sort($requested);
            $before = $this->snapshot($grant);

            if ($before['allowed_operations'] === $requested) {
                return $grant;
            }

            $grant->forceFill(['allowed_operations' => $requested])->save();

            $this->record($actor, $grant, CustomerAccessChangeRecorder::API_GRANT_OPERATIONS_CHANGED, $before, $this->snapshot($grant));

            return $grant;
        });
    }

    public function revoke(?User $actor, int $grantId): ApiClientCustomerGrant
    {
        $actor = $this->authorize($actor);

        return DB::transaction(function () use ($actor, $grantId): ApiClientCustomerGrant {
            $grant = $this->ownActiveGrant($actor, $grantId);
            $before = $this->snapshot($grant);

            $grant->forceFill(['revoked_at' => now(), 'revoked_by_user_id' => $actor->id])->save();
            $grant->integrationUser->forceFill(['is_active' => false])->save();

            $this->record($actor, $grant, CustomerAccessChangeRecorder::API_GRANT_REVOKED, $before, $this->snapshot($grant));

            return $grant;
        });
    }

    private function authorize(?User $actor): User
    {
        if (! $this->mayAdminister($actor)) {
            throw new AuthorizationException('Only the customer\'s System Owner may administer integrations.');
        }

        return $actor;
    }

    /**
     * The actor's own customer's active grant, locked. Another customer's grant, or a revoked one, is
     * not found — the same answer as one that does not exist.
     */
    private function ownActiveGrant(User $actor, int $grantId): ApiClientCustomerGrant
    {
        $grant = ApiClientCustomerGrant::query()
            ->active()
            ->where('customer_id', (int) $actor->customer_id)
            ->whereKey($grantId)
            ->lockForUpdate()
            ->first();

        if ($grant === null) {
            throw (new ModelNotFoundException)->setModel(ApiClientCustomerGrant::class, [$grantId]);
        }

        return $grant;
    }

    /**
     * Active roles of the customer, and nothing else: a role id from another customer, an inactive
     * role or an unknown id refuses the whole change.
     *
     * @param  list<int>  $customerRoleIds
     * @return list<int>
     */
    private function validRoleIds(int $customerId, array $customerRoleIds): array
    {
        $requested = array_values(array_unique(array_map('intval', $customerRoleIds)));

        $valid = CustomerRole::query()
            ->where('customer_id', $customerId)
            ->where('is_active', true)
            ->whereIn('id', $requested === [] ? [0] : $requested)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if (count($valid) !== count($requested)) {
            throw ValidationException::withMessages(['customer_role_ids' => __('procynia.customer_env.integrations.errors.invalid_roles')]);
        }

        sort($valid);

        return $valid;
    }

    /** @return array<string, mixed> */
    private function snapshot(ApiClientCustomerGrant $grant): array
    {
        $grant->refresh();
        $identity = $grant->integrationUser;

        return [
            'api_client_id' => (int) $grant->api_client_id,
            'integration_user_id' => (int) $grant->integration_user_id,
            'customer_role_ids' => $identity->customerRoles()->pluck('customer_roles.id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all(),
            'allowed_operations' => collect((array) ($grant->allowed_operations ?? []))->map(fn (mixed $op): string => (string) $op)->sort()->values()->all(),
            'active' => $grant->revoked_at === null,
            'identity_active' => (bool) $identity->is_active,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function record(User $actor, ApiClientCustomerGrant $grant, string $change, ?array $before, ?array $after): void
    {
        $this->recorder->record(
            (int) $grant->customer_id,
            $actor,
            CustomerAccessChange::SUBJECT_API_CLIENT_GRANT,
            (int) $grant->id,
            $change,
            $before,
            $after,
        );
    }
}
