<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Department;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdvaniaLocalDevSeeder extends Seeder
{
    private const DEFAULT_PASSWORD = 'Opaque01';

    private const CUSTOMER_NAME = 'Advania Norge AS';

    private const CUSTOMER_SLUG = 'advania-norge-as';

    public function run(): void
    {
        if (! app()->environment(['local', 'development'])) {
            return;
        }

        DB::transaction(function (): void {
            $language = $this->resolveLanguage();
            $nationality = $this->resolveNationality();
            $customer = $this->resolveCustomer($language, $nationality);
            $departments = $this->resolveDepartments($customer);

            $this->seedSuperAdmin();
            $this->seedCustomerUser(
                customer: $customer,
                department: $departments['MSP'],
                language: $language,
                nationality: $nationality,
                name: 'Alisan Senel',
                email: 'alisan@advania.no',
                bidRole: User::BID_ROLE_SYSTEM_OWNER,
            );
            $this->seedCustomerUser(
                customer: $customer,
                department: $departments['MSP'],
                language: $language,
                nationality: $nationality,
                name: 'Henriette Ask',
                email: 'henriette@advania.no',
                bidRole: User::BID_ROLE_BID_MANAGER,
                bidManagerScope: User::BID_MANAGER_SCOPE_COMPANY,
            );
            $this->seedCustomerUser(
                customer: $customer,
                department: $departments['MSP'],
                language: $language,
                nationality: $nationality,
                name: 'Gerhard Næss',
                email: 'gerhard@advania.no',
                bidRole: User::BID_ROLE_CONTRIBUTOR,
            );
            $this->seedCustomerUser(
                customer: $customer,
                department: $departments['Consulting'],
                language: $language,
                nationality: $nationality,
                name: 'Raymond Dammen',
                email: 'raymond@advania.no',
                bidRole: User::BID_ROLE_CONTRIBUTOR,
            );
        });
    }

    private function resolveLanguage(): Language
    {
        return Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );
    }

    private function resolveNationality(): Nationality
    {
        return Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => '🇳🇴'],
        );
    }

    private function resolveCustomer(Language $language, Nationality $nationality): Customer
    {
        $attributes = [
            'name' => self::CUSTOMER_NAME,
            'slug' => self::CUSTOMER_SLUG,
            'nationality_id' => $nationality->id,
            'language_id' => $language->id,
            'is_active' => true,
            'permission_settings' => Customer::DEFAULT_PERMISSION_SETTINGS,
        ];

        $customer = Customer::query()->where('slug', self::CUSTOMER_SLUG)->first();

        if (! $customer instanceof Customer) {
            $customer = Customer::query()->where('name', self::CUSTOMER_NAME)->first();
        }

        if ($customer instanceof Customer) {
            $customer->fill($attributes);
            $customer->save();

            return $customer;
        }

        return Customer::query()->create($attributes);
    }

    /**
     * @return array<string, Department>
     */
    private function resolveDepartments(Customer $customer): array
    {
        $departments = [];

        foreach ([
            'MSP' => 'Drift og forvaltning',
            'Consulting' => 'Rådgivning og tilbudsarbeid',
            'Products' => 'Produkter og løsninger',
        ] as $name => $description) {
            $departments[$name] = Department::query()->updateOrCreate(
                [
                    'customer_id' => $customer->id,
                    'name' => $name,
                ],
                [
                    'description' => $description,
                    'is_active' => true,
                ],
            );
        }

        return $departments;
    }

    private function seedSuperAdmin(): User
    {
        return $this->upsertUser(
            'superadmin@procynia.no',
            [
                'name' => 'Superadmin',
                'password' => Hash::make(self::DEFAULT_PASSWORD),
                'role' => User::ROLE_SUPER_ADMIN,
                'bid_role' => User::BID_ROLE_CONTRIBUTOR,
                'bid_manager_scope' => null,
                'primary_affiliation_scope' => null,
                'primary_department_id' => null,
                'is_active' => true,
                'customer_id' => null,
                'department_id' => null,
                'nationality_id' => null,
                'preferred_language_id' => null,
            ],
        );
    }

    private function seedCustomerUser(
        Customer $customer,
        Department $department,
        Language $language,
        Nationality $nationality,
        string $name,
        string $email,
        string $bidRole,
        ?string $bidManagerScope = null,
    ): User {
        $user = $this->upsertUser(
            $email,
            [
                'name' => Str::squish($name),
                'password' => Hash::make(self::DEFAULT_PASSWORD),
                'role' => User::customerRoleForBidRole($bidRole),
                'bid_role' => $bidRole,
                'bid_manager_scope' => $bidManagerScope,
                'primary_affiliation_scope' => User::PRIMARY_AFFILIATION_SCOPE_DEPARTMENT,
                'primary_department_id' => $department->id,
                'is_active' => true,
                'customer_id' => $customer->id,
                'department_id' => $department->id,
                'nationality_id' => $nationality->id,
                'preferred_language_id' => $language->id,
            ],
        );

        $user->departments()->sync([$department->id]);

        return $user;
    }

    private function upsertUser(string $email, array $attributes): User
    {
        $normalizedEmail = Str::lower(trim($email));

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
            ->first();

        $payload = array_merge($attributes, ['email' => $normalizedEmail]);

        if ($user instanceof User) {
            $user->fill($payload);
            $user->save();

            return $user;
        }

        return User::query()->create($payload);
    }
}
