<?php

namespace Tests\Support;

use App\Models\CustomerRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Test-only setup and cleanup for tests/e2e/roles-on-user-edit.spec.js. Not autoloaded in production
 * (autoload-dev only) — invoked via `php artisan tinker --execute=...`.
 *
 * The spec used to tick two roles that existed only in a long-lived development database
 * («Kvalitetsleder», «Wiki-redaktør») on the shared E2E user, and left them assigned. In the isolated
 * E2E environment the roles do not exist, and on the shared user they would change what every other
 * spec sees. So the run brings its own: two roles and one ordinary person in the E2E customer, all
 * named «E2E Roller <SUFFIX> …», and cleanup removes exactly those.
 */
class RolesOnUserEditE2EFixture
{
    public const PREFIX = 'E2E Roller';

    private const SYSTEM_OWNER_EMAIL = 'e2e.systemowner@procynia.test';

    /** @return array{email: string, roles: list<string>} */
    public static function seed(string $suffix): array
    {
        self::cleanup($suffix);
        $customerId = (int) User::query()->where('email', self::SYSTEM_OWNER_EMAIL)->value('customer_id');

        return DB::transaction(function () use ($customerId, $suffix): array {
            $roles = [];

            foreach (['Kvalitetsleder', 'Wiki-redaktør'] as $label) {
                $role = CustomerRole::query()->create([
                    'customer_id' => $customerId,
                    'name' => self::name($suffix, $label),
                    'is_active' => true,
                ]);
                $roles[] = $role->name;
            }

            $person = User::query()->create([
                'name' => self::name($suffix, 'Bruker'),
                'email' => 'e2e.roller.'.Str::lower($suffix).'@procynia.test',
                'password' => bcrypt(Str::random(24)),
                'customer_id' => $customerId,
                'role' => User::ROLE_USER,
                'bid_role' => User::BID_ROLE_CONTRIBUTOR,
                'is_active' => true,
            ]);

            return ['email' => $person->email, 'roles' => $roles];
        });
    }

    public static function cleanup(string $suffix): void
    {
        $customerId = (int) User::query()->where('email', self::SYSTEM_OWNER_EMAIL)->value('customer_id');
        $pattern = '^'.preg_quote(self::PREFIX).' '.preg_quote(strtoupper($suffix)).'( |$)';

        DB::transaction(function () use ($customerId, $pattern): void {
            // User-role links cascade from both sides.
            CustomerRole::query()->where('customer_id', $customerId)->where('name', '~', $pattern)->delete();
            User::query()->where('customer_id', $customerId)->where('name', '~', $pattern)->delete();
        });
    }

    private static function name(string $suffix, string $label): string
    {
        return self::PREFIX.' '.strtoupper($suffix).' '.$label;
    }
}
