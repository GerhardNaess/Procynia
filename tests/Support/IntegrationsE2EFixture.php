<?php

namespace Tests\Support;

use App\Models\ApiClient;
use App\Models\ApiClientCustomerGrant;
use App\Models\CustomerRole;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Test-only setup and cleanup for tests/e2e/customer-integrations.spec.js. Not autoloaded in
 * production (autoload-dev only) — invoked via `php artisan tinker --execute=...`.
 *
 * The run brings its own approved application and its own read role in the E2E customer, all
 * named «E2E Integrasjon <SUFFIX> …». Cleanup removes the application — its grants and change
 * history go with it — the integration identities those grants created, and the role. The access
 * trail rows the spec's own grants wrote stay: customer_access_changes is history.
 */
class IntegrationsE2EFixture
{
    public const PREFIX = 'E2E Integrasjon';

    private const SYSTEM_OWNER_EMAIL = 'e2e.systemowner@procynia.test';

    /** @return array{client: string, role: string} */
    public static function seed(string $suffix): array
    {
        self::cleanup($suffix);
        $customerId = (int) User::query()->where('email', self::SYSTEM_OWNER_EMAIL)->value('customer_id');

        return DB::transaction(function () use ($customerId, $suffix): array {
            $client = ApiClient::query()->create([
                'name' => self::name($suffix, 'Dashboards'),
                'entra_app_id' => (string) Str::uuid(),
                'home_tenant_id' => (string) Str::uuid(),
                'is_enabled' => true,
            ]);

            $role = CustomerRole::query()->create([
                'customer_id' => $customerId,
                'name' => self::name($suffix, 'Leser'),
                'is_active' => true,
            ]);
            $role->syncPermissions([CustomerPermissionCatalog::RISK_VIEW]);
            $role->syncBusinessAreas(true, []);

            return ['client' => $client->name, 'role' => $role->name];
        });
    }

    public static function cleanup(string $suffix): void
    {
        $customerId = (int) User::query()->where('email', self::SYSTEM_OWNER_EMAIL)->value('customer_id');

        DB::transaction(function () use ($customerId, $suffix): void {
            $clientIds = ApiClient::query()->where('name', 'like', self::name($suffix, '%'))->pluck('id');
            $identityIds = ApiClientCustomerGrant::query()->whereIn('api_client_id', $clientIds)->pluck('integration_user_id');

            ApiClient::query()->whereIn('id', $clientIds)->delete();
            User::integrationIdentities()->whereIn('id', $identityIds)->delete();
            CustomerRole::query()
                ->where('customer_id', $customerId)
                ->where('name', 'like', self::name($suffix, '%'))
                ->get()
                ->each(fn (CustomerRole $role) => $role->delete());
        });
    }

    private static function name(string $suffix, string $label): string
    {
        return self::PREFIX.' '.$suffix.' '.$label;
    }
}
