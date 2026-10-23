<?php

namespace Tests\Concerns;

use App\Models\ApiClient;
use App\Models\ApiClientCustomerGrant;
use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\CustomerRole;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use Illuminate\Support\Str;

/**
 * Customers, integration identities and grants for external API tests.
 *
 * Grants are written directly: no administration path creates them yet (D13 is open), so the
 * tests own the rows they rely on.
 */
trait CreatesApiCustomerGrants
{
    protected function apiCustomer(string $name = 'Kunde', bool $withRisk = true, bool $active = true): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        $customer = Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => $active,
        ]);

        if ($withRisk) {
            app(ModuleEntitlementService::class)->activatePackage($customer, 'governance');
        }

        return $customer;
    }

    protected function integrationIdentity(Customer $customer, array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'name' => 'Integrasjon: Siggjo dashboards',
            'email' => 'integration-'.Str::uuid().'@integrations.procynia.invalid',
            'password' => bcrypt(Str::random(64)),
            'role' => User::ROLE_INTEGRATION,
            'customer_id' => $customer->id,
            'is_active' => true,
        ], $overrides));
    }

    protected function grantTo(ApiClient $client, Customer $customer, ?User $identity = null, array $overrides = []): ApiClientCustomerGrant
    {
        return ApiClientCustomerGrant::query()->create(array_merge([
            'api_client_id' => $client->id,
            'customer_id' => $customer->id,
            'integration_user_id' => ($identity ?? $this->integrationIdentity($customer))->id,
            'granted_at' => now(),
        ], $overrides));
    }

    /**
     * A customer role with the given keys, assigned to the user. «Alle» fagområder when $areas is
     * null, otherwise exactly those.
     *
     * @param  list<string>  $permissionKeys
     * @param  list<BusinessArea>|null  $areas
     */
    protected function giveRole(User $user, array $permissionKeys, ?array $areas = null): CustomerRole
    {
        $role = CustomerRole::query()->create([
            'customer_id' => $user->customer_id,
            'name' => 'Rolle '.Str::upper(Str::random(8)),
            'is_active' => true,
        ]);

        $role->syncPermissions($permissionKeys);
        $role->syncBusinessAreas($areas === null, $areas === null ? [] : array_map(fn (BusinessArea $area): int => (int) $area->id, $areas));
        $user->customerRoles()->attach($role->id, ['customer_id' => $user->customer_id]);

        return $role;
    }

    protected function businessArea(Customer $customer, string $name): BusinessArea
    {
        return BusinessArea::query()->create(['customer_id' => $customer->id, 'name' => $name]);
    }

    /** @return array<string, string> */
    protected function asCustomer(string $token, Customer|int $customer): array
    {
        return [
            'Authorization' => 'Bearer '.$token,
            'Procynia-Customer' => (string) ($customer instanceof Customer ? $customer->id : $customer),
        ];
    }
}
