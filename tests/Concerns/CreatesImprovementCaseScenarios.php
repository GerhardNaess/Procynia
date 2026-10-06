<?php

namespace Tests\Concerns;

use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\CustomerRole;
use App\Models\ImprovementAction;
use App\Models\ImprovementCase;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Support\Str;

/**
 * The customers, fagområder, roles, people and cases the Avvik og forbedringer feature tests build
 * on. Every grant goes through a real customer role, so the tests exercise the same
 * (permission, area) pairing the product does.
 */
trait CreatesImprovementCaseScenarios
{
    /** @return array<string, mixed> */
    private function payload(BusinessArea $area, User $owner, string $title = 'Ny sak', string $type = ImprovementCase::TYPE_DEVIATION): array
    {
        return [
            'type' => $type,
            'title' => $title,
            'description' => 'Hva skjedde, og hva var forventet.',
            'business_area_id' => $area->id,
            'owner_user_id' => $owner->id,
            'occurred_at' => null,
            'due_date' => null,
        ];
    }

    /**
     * A member who reads and edits cases in the area, plus any extra keys there.
     *
     * @param  list<string>  $extra
     */
    private function editor(Customer $customer, BusinessArea $area, array $extra = []): User
    {
        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::IMPROVEMENT_EDIT, ...$extra], [$area]);

        return $user;
    }

    /** A member who may do everything with cases in the area. */
    private function handler(Customer $customer, BusinessArea $area): User
    {
        return $this->editor($customer, $area, [CustomerPermissionCatalog::IMPROVEMENT_CLOSE, CustomerPermissionCatalog::IMPROVEMENT_DELETE]);
    }

    private function improvementCase(Customer $customer, BusinessArea $area, string $title, ?User $owner = null, string $type = ImprovementCase::TYPE_DEVIATION): ImprovementCase
    {
        return ImprovementCase::query()->create([
            'customer_id' => $customer->id,
            'business_area_id' => $area->id,
            'type' => $type,
            'title' => $title,
            'description' => 'Beskrivelse av '.$title,
            'owner_user_id' => $owner?->id,
        ]);
    }

    /**
     * A tiltak written straight to the database — planned, as every new one is.
     */
    private function improvementAction(ImprovementCase $case, string $title, ?User $owner = null, string $dueDate = '2030-01-31'): ImprovementAction
    {
        return ImprovementAction::query()->create([
            'customer_id' => $case->customer_id,
            'improvement_case_id' => $case->id,
            'title' => $title,
            'owner_user_id' => $owner?->id,
            'due_date' => $dueDate,
        ]);
    }

    /** @return array<string, mixed> */
    private function actionPayload(User $owner, string $title = 'Nytt tiltak', string $dueDate = '2030-01-31'): array
    {
        return [
            'title' => $title,
            'description' => 'Hva tiltaket innebærer.',
            'owner_user_id' => $owner->id,
            'due_date' => $dueDate,
        ];
    }

    /**
     * @param  list<string>  $permissionKeys
     * @param  list<BusinessArea>  $areas
     */
    private function grant(Customer $customer, User $user, array $permissionKeys, array $areas = []): CustomerRole
    {
        $role = $this->role($customer, $permissionKeys);
        $role->syncBusinessAreas(false, array_map(fn (BusinessArea $area): int => (int) $area->id, $areas));
        $user->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        return $role;
    }

    /** @param  list<string>  $permissionKeys */
    private function grantAll(Customer $customer, User $user, array $permissionKeys): CustomerRole
    {
        $role = $this->role($customer, $permissionKeys);
        $role->syncBusinessAreas(true, []);
        $user->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        return $role;
    }

    /** @param  list<string>  $permissionKeys */
    private function role(Customer $customer, array $permissionKeys): CustomerRole
    {
        $role = CustomerRole::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Rolle '.Str::upper(Str::random(8)),
            'is_active' => true,
        ]);

        $role->syncPermissions($permissionKeys);

        return $role;
    }

    private function area(Customer $customer, string $name): BusinessArea
    {
        return BusinessArea::query()->create(['customer_id' => $customer->id, 'name' => $name]);
    }

    private function member(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'Medarbeider '.Str::upper(Str::random(6)),
            'email' => 'avvik-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    /**
     * A customer holding the given package (every step of the ladder from Basis carries Avvik og forbedringer),
     * or none, and its System Owner.
     *
     * @return array{customer: Customer, owner: User}
     */
    private function context(?string $package = 'basis'): array
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        $customer = Customer::query()->create([
            'name' => 'Avvik AS',
            'slug' => 'avvik-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        if ($package !== null) {
            CustomerPackageEntitlement::query()->updateOrCreate(
                ['customer_id' => $customer->id, 'package_key' => $package],
                ['status' => CustomerPackageEntitlement::STATUS_ACTIVE, 'activated_at' => now()],
            );
        }

        $owner = User::query()->create([
            'name' => 'System Owner',
            'email' => 'avvik-eier-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        return ['customer' => $customer, 'owner' => $owner];
    }
}
