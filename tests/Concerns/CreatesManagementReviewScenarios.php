<?php

namespace Tests\Concerns;

use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\CustomerRole;
use App\Models\Language;
use App\Models\ManagementReview;
use App\Models\Nationality;
use App\Models\User;
use App\Services\ManagementReview\ManagementReviewService;
use App\Services\Modules\ModuleEntitlementService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Support\Str;

/**
 * The customers, fagområder, roles, people and reviews the Ledelsens gjennomgåelse tests build on.
 * Every grant goes through a real customer role, so the tests exercise the same (permission, area)
 * pairing — and the same System Owner rules — the product does.
 */
trait CreatesManagementReviewScenarios
{
    /**
     * A customer holding the given packages (Basis carries the module), and its System Owner.
     *
     * @param  list<string>  $packages  package or bundle keys
     * @return array{customer: Customer, owner: User}
     */
    private function mrContext(array $packages = ['basis']): array
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        $customer = Customer::query()->create([
            'name' => 'Ledelse AS',
            'slug' => 'ledelse-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        foreach ($packages as $package) {
            app(ModuleEntitlementService::class)->activatePackage($customer, $package);
        }

        $owner = User::query()->create([
            'name' => 'System Owner '.Str::upper(Str::random(4)),
            'email' => 'lg-eier-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        return ['customer' => $customer, 'owner' => $owner];
    }

    private function mrMember(Customer $customer, string $name = ''): User
    {
        return User::query()->create([
            'name' => $name !== '' ? $name : 'Medarbeider '.Str::upper(Str::random(6)),
            'email' => 'lg-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    /**
     * @param  list<string>  $permissionKeys
     * @param  list<BusinessArea>|true  $areas  the role's fagområder, or true for «Alle»
     */
    private function mrGrant(Customer $customer, User $user, array $permissionKeys, array|bool $areas = []): CustomerRole
    {
        $role = CustomerRole::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Rolle '.Str::upper(Str::random(8)),
            'is_active' => true,
        ]);
        $role->syncPermissions($permissionKeys);
        $role->syncBusinessAreas($areas === true, $areas === true ? [] : array_map(fn (BusinessArea $area): int => (int) $area->id, $areas));
        $user->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        return $role;
    }

    private function mrArea(Customer $customer, string $name): BusinessArea
    {
        return BusinessArea::query()->create(['customer_id' => $customer->id, 'name' => $name]);
    }

    /** A person who reads, edits and finalizes reviews, plus any extra keys (and their fagområder). */
    private function mrManager(Customer $customer, array $extra = [], array|bool $areas = []): User
    {
        $user = $this->mrMember($customer);
        $this->mrGrant($customer, $user, [
            CustomerPermissionCatalog::MANAGEMENT_REVIEW_VIEW,
            CustomerPermissionCatalog::MANAGEMENT_REVIEW_EDIT,
            CustomerPermissionCatalog::MANAGEMENT_REVIEW_FINALIZE,
            CustomerPermissionCatalog::MANAGEMENT_REVIEW_DELETE,
            ...$extra,
        ], $areas);

        return $user;
    }

    private function mrReader(Customer $customer, array $extra = [], array|bool $areas = []): User
    {
        $user = $this->mrMember($customer);
        $this->mrGrant($customer, $user, [CustomerPermissionCatalog::MANAGEMENT_REVIEW_VIEW, ...$extra], $areas);

        return $user;
    }

    /** @return array<string, mixed> */
    private function mrPayload(User $owner, array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Ledelsens gjennomgåelse 2026',
            'purpose' => null,
            'period_start' => '2026-01-01',
            'period_end' => '2026-06-30',
            'meeting_date' => '2026-07-01',
            'all_business_areas' => true,
            'business_area_ids' => [],
            'frameworks' => [],
            'owner_user_id' => $owner->id,
        ];
    }

    private function mrReview(User $actor, array $overrides = []): ManagementReview
    {
        return app(ManagementReviewService::class)->create($actor, $this->mrPayload($actor, $overrides));
    }
}
