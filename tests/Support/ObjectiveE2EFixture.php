<?php

namespace Tests\Support;

use App\Models\BusinessArea;
use App\Models\CustomerRole;
use App\Models\Objective;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Test-only setup and cleanup for the Mål og KPI E2E specs (tests/e2e/objectives-*.spec.js). Not
 * autoloaded in production (autoload-dev only) — invoked via `php artisan tinker --execute=...`.
 *
 * ONE NAME RULE, NO LIST OF NAMES.
 *
 * Every fagområde, role and objective a spec creates is named «E2E Mål <SUFFIX> <anything>»
 * (tests/e2e/helpers/objectives.js::objectiveE2eName builds it). Cleanup matches that prefix and
 * nothing else, in the E2E customer only. Unlike the Risk fixture there is no list of exact names to
 * keep in step with the specs, so a spec that invents a new name is still cleaned up — the
 * failure mode Risk had.
 */
class ObjectiveE2EFixture
{
    public const PREFIX = 'E2E Mål';

    private const SYSTEM_OWNER_EMAIL = 'e2e.systemowner@procynia.test';

    private const USER_EMAIL = 'e2e.user@procynia.test';

    private const SUFFIX_PATTERN = '[A-Z0-9]{6}';

    /** Leftovers from interrupted runs are only swept once they are this old, so a run still in flight is never touched. */
    private const SWEEP_MIN_AGE_MINUTES = 10;

    /**
     * For the view-only spec: two areas, a role that reads one of them handed to the E2E user, and
     * one objective in each. Returns the ids the spec needs.
     *
     * @return array{visible_id: int, hidden_id: int}
     */
    public static function seedViewOnly(string $suffix): array
    {
        $customerId = self::customerId();
        $user = User::query()->where('email', self::USER_EMAIL)->firstOrFail();
        $name = fn (string $label): string => self::PREFIX.' '.strtoupper($suffix).' '.$label;

        return DB::transaction(function () use ($customerId, $user, $name): array {
            $readable = BusinessArea::query()->create(['customer_id' => $customerId, 'name' => $name('Lesing')]);
            $hidden = BusinessArea::query()->create(['customer_id' => $customerId, 'name' => $name('Skjult område')]);

            $role = CustomerRole::query()->create(['customer_id' => $customerId, 'name' => $name('Leser'), 'is_active' => true]);
            $role->syncPermissions([CustomerPermissionCatalog::OBJECTIVE_VIEW]);
            $role->syncBusinessAreas(false, [$readable->id]);
            $user->customerRoles()->attach($role->id, ['customer_id' => $customerId]);

            $visible = Objective::query()->create([
                'customer_id' => $customerId,
                'business_area_id' => $readable->id,
                'title' => $name('Synlig mål'),
                'owner_user_id' => $user->id,
            ]);

            $secret = Objective::query()->create([
                'customer_id' => $customerId,
                'business_area_id' => $hidden->id,
                'title' => $name('Skjult mål'),
            ]);

            return ['visible_id' => (int) $visible->id, 'hidden_id' => (int) $secret->id];
        });
    }

    /**
     * Removes what one run created (by its suffix), or — with no suffix — whatever interrupted runs
     * left behind. Idempotent, and safe after a run that stopped halfway.
     */
    public static function cleanup(?string $suffix = null): void
    {
        $customerId = self::customerId();
        $pattern = '^'.preg_quote(self::PREFIX).' '.($suffix === null ? self::SUFFIX_PATTERN : preg_quote(strtoupper($suffix))).'( |$)';
        $sweepOnly = fn (Builder $query): Builder => $suffix === null
            ? $query->where('created_at', '<', now()->subMinutes(self::SWEEP_MIN_AGE_MINUTES))
            : $query;

        DB::transaction(function () use ($customerId, $pattern, $sweepOnly): void {
            $areaIds = $sweepOnly(BusinessArea::query()->where('customer_id', $customerId)->where('name', '~', $pattern))->pluck('id');

            // An objective is the run's when its title carries the marker, or when it lives in one of
            // the run's areas — a spec may retitle it, but it cannot leave an area no one else uses.
            $sweepOnly(Objective::query()->where('customer_id', $customerId)
                ->where(fn (Builder $query) => $query->where('title', '~', $pattern)->orWhereIn('business_area_id', $areaIds)))
                ->delete();

            // Role permissions, area grants and user-role links (also on the seeded E2E users)
            // cascade from the role.
            $sweepOnly(CustomerRole::query()->where('customer_id', $customerId)->where('name', '~', $pattern))->delete();

            // An area still holding content the run did not create stays.
            BusinessArea::query()->whereIn('id', $areaIds)->get()
                ->reject(fn (BusinessArea $area): bool => $area->isInUse())
                ->each(fn (BusinessArea $area) => $area->delete());
        });
    }

    private static function customerId(): int
    {
        return (int) User::query()->where('email', self::SYSTEM_OWNER_EMAIL)->value('customer_id');
    }
}
