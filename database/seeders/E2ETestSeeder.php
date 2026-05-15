<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds stable test users for Playwright E2E tests.
 *
 * All users use non-real .procynia.test emails that cannot belong to real accounts.
 * No external API keys, no Stripe data, no OpenAI credentials are created.
 * Safe to run repeatedly — all upserts are idempotent.
 *
 * Known credentials (used in tests/e2e/helpers/auth.js):
 *   Super admin:   e2e.superadmin@procynia.test  /  E2eAdmin123!
 *   System owner:  e2e.systemowner@procynia.test  /  E2eUser123!
 *   Regular user:  e2e.user@procynia.test          /  E2eUser123!
 */
class E2ETestSeeder extends Seeder
{
    private const SUPER_ADMIN_EMAIL = 'e2e.superadmin@procynia.test';
    private const SUPER_ADMIN_PASSWORD = 'E2eAdmin123!';
    private const SYSTEM_OWNER_EMAIL = 'e2e.systemowner@procynia.test';
    private const USER_EMAIL = 'e2e.user@procynia.test';
    private const E2E_PASSWORD = 'E2eUser123!';
    private const CUSTOMER_SLUG = 'e2e-test-customer';

    public function run(): void
    {
        // Internal super admin — no customer, full Filament access
        User::query()->updateOrCreate(
            ['email' => self::SUPER_ADMIN_EMAIL],
            [
                'name' => 'E2E Super Admin',
                'password' => Hash::make(self::SUPER_ADMIN_PASSWORD),
                'role' => User::ROLE_SUPER_ADMIN,
                'customer_id' => null,
                'is_active' => true,
            ],
        );

        // Minimal test customer (language + nationality required by the Customer schema)
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => '🇳🇴'],
        );

        $customer = Customer::query()->updateOrCreate(
            ['slug' => self::CUSTOMER_SLUG],
            [
                'name' => 'E2E Test Customer',
                'language_id' => $language->id,
                'nationality_id' => $nationality->id,
                'is_active' => true,
            ],
        );

        // System owner — bid_role=system_owner gives access to /app/billing
        User::query()->updateOrCreate(
            ['email' => self::SYSTEM_OWNER_EMAIL],
            [
                'name' => 'E2E System Owner',
                'password' => Hash::make(self::E2E_PASSWORD),
                'role' => User::ROLE_CUSTOMER_ADMIN,
                'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
                'customer_id' => $customer->id,
                'is_active' => true,
            ],
        );

        // Regular contributor — standard customer user without elevated permissions
        User::query()->updateOrCreate(
            ['email' => self::USER_EMAIL],
            [
                'name' => 'E2E User',
                'password' => Hash::make(self::E2E_PASSWORD),
                'role' => User::ROLE_USER,
                'bid_role' => User::BID_ROLE_CONTRIBUTOR,
                'customer_id' => $customer->id,
                'is_active' => true,
            ],
        );
    }
}
