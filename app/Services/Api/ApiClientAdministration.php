<?php

namespace App\Services\Api;

use App\Models\ApiClient;
use App\Models\ApiClientChange;
use App\Models\User;
use App\Services\Security\HumanDecisionGuard;
use App\Support\CustomerContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Registering, changing, enabling and disabling client applications (D13, points 1, 4 and 5).
 *
 * Only an internal Procynia platform administrator, acting interactively, may do any of it — the
 * rule lives here, not in the Filament screens, so no other entry point can skip it. Every change
 * is recorded in api_client_changes with its actor.
 *
 * Registration gives no access to any customer's data: that takes a customer's own grant
 * (ApiClientGrantService). The Entra identity of a client (application id and home tenant) is fixed
 * once registered — grants were given to that application, so a different one is a new client.
 */
class ApiClientAdministration
{
    private const GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private readonly CustomerContext $context,
        private readonly HumanDecisionGuard $guard,
    ) {}

    public function mayAdminister(?User $actor): bool
    {
        return $actor instanceof User
            && $this->guard->allows($actor)
            && $this->context->isInternalAdmin($actor);
    }

    /**
     * @param  array<string, mixed>  $input  name, description, entra_app_id, home_tenant_id
     */
    public function register(?User $actor, array $input): ApiClient
    {
        $actor = $this->authorize($actor);

        $input['entra_app_id'] = strtolower(trim((string) ($input['entra_app_id'] ?? '')));
        $input['home_tenant_id'] = strtolower(trim((string) ($input['home_tenant_id'] ?? '')));

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'entra_app_id' => ['required', 'string', 'regex:'.self::GUID, Rule::unique('api_clients', 'entra_app_id')],
            'home_tenant_id' => ['required', 'string', 'regex:'.self::GUID],
        ])->validate();

        return DB::transaction(function () use ($actor, $validated): ApiClient {
            $client = ApiClient::query()->create($validated + [
                'is_enabled' => true,
                'created_by_user_id' => $actor->id,
            ]);

            $this->record($client, $actor, ApiClientChange::REGISTERED, null, $this->snapshot($client));

            return $client;
        });
    }

    /**
     * @param  array<string, mixed>  $input  name, description
     */
    public function update(?User $actor, ApiClient $client, array $input): ApiClient
    {
        $actor = $this->authorize($actor);

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        return DB::transaction(function () use ($actor, $client, $validated): ApiClient {
            $client = ApiClient::query()->lockForUpdate()->findOrFail($client->id);
            $before = $this->snapshot($client);

            $client->fill(['name' => $validated['name'], 'description' => $validated['description'] ?? null])->save();
            $after = $this->snapshot($client);

            if ($before != $after) {
                $this->record($client, $actor, ApiClientChange::CHANGED, $before, $after);
            }

            return $client;
        });
    }

    /**
     * Disabling takes effect on the client's next request: the token validator refuses a disabled
     * client before any grant is consulted.
     */
    public function setEnabled(?User $actor, ApiClient $client, bool $enabled): ApiClient
    {
        $actor = $this->authorize($actor);

        return DB::transaction(function () use ($actor, $client, $enabled): ApiClient {
            $client = ApiClient::query()->lockForUpdate()->findOrFail($client->id);

            if ($client->is_enabled === $enabled) {
                return $client;
            }

            $before = $this->snapshot($client);
            $client->forceFill(['is_enabled' => $enabled])->save();

            $this->record($client, $actor, $enabled ? ApiClientChange::ENABLED : ApiClientChange::DISABLED, $before, $this->snapshot($client));

            return $client;
        });
    }

    private function authorize(?User $actor): User
    {
        if (! $this->mayAdminister($actor)) {
            throw new AuthorizationException('Only an internal administrator may administer client applications.');
        }

        return $actor;
    }

    /** @return array<string, mixed> */
    private function snapshot(ApiClient $client): array
    {
        return [
            'name' => $client->name,
            'description' => $client->description,
            'entra_app_id' => $client->entra_app_id,
            'home_tenant_id' => $client->home_tenant_id,
            'is_enabled' => (bool) $client->is_enabled,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function record(ApiClient $client, User $actor, string $type, ?array $before, ?array $after): void
    {
        ApiClientChange::query()->create([
            'api_client_id' => $client->id,
            'actor_user_id' => $actor->id,
            'change_type' => $type,
            'before' => $before,
            'after' => $after,
            'created_at' => now(),
        ]);
    }
}
