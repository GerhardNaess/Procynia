<?php

namespace Tests\Support;

use App\Models\BusinessArea;
use App\Models\CustomerRole;
use App\Models\ImprovementCase;
use App\Models\ImprovementCaseActivity;
use App\Models\ImprovementCaseProcess;
use App\Models\ImprovementCaseStatusChange;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Test-only setup and cleanup for the Avvik og forbedringer E2E specs
 * (tests/e2e/improvements*.spec.js). Not autoloaded in production (autoload-dev only) — invoked via
 * `php artisan tinker --execute=...`.
 *
 * ONE NAME RULE, NO LIST OF NAMES — the same as ObjectiveE2EFixture.
 *
 * Every fagområde, role and case a spec creates is named «E2E Avvik <SUFFIX> <anything>»
 * (tests/e2e/helpers/improvements.js::improvementE2eName builds it). Cleanup matches that prefix
 * and nothing else, in the E2E customer only, plus every case that lives in one of the run's areas.
 */
class ImprovementE2EFixture
{
    public const PREFIX = 'E2E Avvik';

    private const SYSTEM_OWNER_EMAIL = 'e2e.systemowner@procynia.test';

    private const USER_EMAIL = 'e2e.user@procynia.test';

    private const SUFFIX_PATTERN = '[A-Z0-9]{6}';

    /** Leftovers from interrupted runs are only swept once they are this old, so a run in flight is never touched. */
    private const SWEEP_MIN_AGE_MINUTES = 10;

    /**
     * For the journey: one area and a role that does everything with cases there, handed to the
     * E2E user. The spec registers the case itself, through the pages.
     *
     * @return array{area_name: string}
     */
    public static function seedHandler(string $suffix): array
    {
        $customerId = self::customerId();
        $user = User::query()->where('email', self::USER_EMAIL)->firstOrFail();
        $name = self::namer($suffix);

        return DB::transaction(function () use ($customerId, $user, $name): array {
            $area = BusinessArea::query()->create(['customer_id' => $customerId, 'name' => $name('Kvalitetsområde')]);

            $role = CustomerRole::query()->create(['customer_id' => $customerId, 'name' => $name('Saksbehandler'), 'is_active' => true]);
            $role->syncPermissions([
                CustomerPermissionCatalog::IMPROVEMENT_VIEW,
                CustomerPermissionCatalog::IMPROVEMENT_EDIT,
                CustomerPermissionCatalog::IMPROVEMENT_CLOSE,
                CustomerPermissionCatalog::IMPROVEMENT_DELETE,
            ]);
            $role->syncBusinessAreas(false, [$area->id]);
            $user->customerRoles()->attach($role->id, ['customer_id' => $customerId]);

            return ['area_name' => $area->name];
        });
    }

    /**
     * For the access spec: two areas, a role that only reads the first handed to the E2E user, an
     * open case and a case under arbeid in the readable area, and one case in the hidden area.
     *
     * @return array{visible_id: int, in_progress_id: int, hidden_id: int}
     */
    public static function seedViewOnly(string $suffix): array
    {
        $customerId = self::customerId();
        $user = User::query()->where('email', self::USER_EMAIL)->firstOrFail();
        $name = self::namer($suffix);

        return DB::transaction(function () use ($customerId, $user, $name): array {
            $readable = BusinessArea::query()->create(['customer_id' => $customerId, 'name' => $name('Lesing')]);
            $hidden = BusinessArea::query()->create(['customer_id' => $customerId, 'name' => $name('Skjult område')]);

            $role = CustomerRole::query()->create(['customer_id' => $customerId, 'name' => $name('Leser'), 'is_active' => true]);
            $role->syncPermissions([CustomerPermissionCatalog::IMPROVEMENT_VIEW]);
            $role->syncBusinessAreas(false, [$readable->id]);
            $user->customerRoles()->attach($role->id, ['customer_id' => $customerId]);

            $case = fn (BusinessArea $area, string $title, string $type) => ImprovementCase::query()->create([
                'customer_id' => $customerId,
                'business_area_id' => $area->id,
                'type' => $type,
                'title' => $title,
                'description' => 'Registrert av E2E-fixturen.',
                'owner_user_id' => $user->id,
                'reported_by_user_id' => $user->id,
            ]);

            $visible = $case($readable, $name('Synlig avvik'), ImprovementCase::TYPE_DEVIATION);
            $inProgress = $case($readable, $name('Synlig forbedring'), ImprovementCase::TYPE_IMPROVEMENT);
            $secret = $case($hidden, $name('Skjult avvik'), ImprovementCase::TYPE_DEVIATION);

            // Under arbeid, the way the lifecycle would have put it there.
            ImprovementCaseStatusChange::query()->create([
                'customer_id' => $customerId,
                'improvement_case_id' => $inProgress->id,
                'from_status' => ImprovementCase::STATUS_OPEN,
                'to_status' => ImprovementCase::STATUS_IN_PROGRESS,
                'changed_by_user_id' => $user->id,
                'changed_at' => now(),
            ]);
            $inProgress->forceFill(['status' => ImprovementCase::STATUS_IN_PROGRESS])->save();

            return ['visible_id' => (int) $visible->id, 'in_progress_id' => (int) $inProgress->id, 'hidden_id' => (int) $secret->id];
        });
    }

    /**
     * What is left of one run, for checking that cleanup really emptied it.
     *
     * @return array{areas: int, roles: int, cases: int, status_changes: int, processes: int, activities: int}
     */
    public static function remaining(string $suffix): array
    {
        $customerId = self::customerId();
        $pattern = self::pattern($suffix);
        $areaIds = BusinessArea::query()->where('customer_id', $customerId)->where('name', '~', $pattern)->pluck('id');
        $caseIds = ImprovementCase::query()->where('customer_id', $customerId)
            ->where(fn (Builder $query) => $query->where('title', '~', $pattern)->orWhereIn('business_area_id', $areaIds))
            ->pluck('id');

        return [
            'areas' => $areaIds->count(),
            'roles' => CustomerRole::query()->where('customer_id', $customerId)->where('name', '~', $pattern)->count(),
            'cases' => $caseIds->count(),
            'status_changes' => ImprovementCaseStatusChange::query()->whereIn('improvement_case_id', $caseIds)->count(),
            'processes' => ImprovementCaseProcess::query()->whereIn('improvement_case_id', $caseIds)->count(),
            'activities' => ImprovementCaseActivity::query()->whereIn('improvement_case_id', $caseIds)->count(),
        ];
    }

    /**
     * Removes what one run created (by its suffix), or — with no suffix — whatever interrupted runs
     * left behind. Idempotent, and safe after a run that stopped halfway.
     */
    public static function cleanup(?string $suffix = null): void
    {
        $customerId = self::customerId();
        $pattern = $suffix === null ? '^'.preg_quote(self::PREFIX).' '.self::SUFFIX_PATTERN.'( |$)' : self::pattern($suffix);
        $sweepOnly = fn (Builder $query): Builder => $suffix === null
            ? $query->where('created_at', '<', now()->subMinutes(self::SWEEP_MIN_AGE_MINUTES))
            : $query;

        DB::transaction(function () use ($customerId, $pattern, $sweepOnly): void {
            $areaIds = $sweepOnly(BusinessArea::query()->where('customer_id', $customerId)->where('name', '~', $pattern))->pluck('id');

            // A case is the run's when its title carries the marker, or when it lives in one of the
            // run's areas — a spec may retitle it, but it cannot leave an area no one else uses. A
            // bulk delete, past the model: history rows and Kvalitet links go with the case through
            // the database cascade. Kvalitet's processes are never touched.
            $sweepOnly(ImprovementCase::query()->where('customer_id', $customerId)
                ->where(fn (Builder $query) => $query->where('title', '~', $pattern)->orWhereIn('business_area_id', $areaIds)))
                ->delete();

            // Role permissions, area grants and user-role links cascade from the role.
            $sweepOnly(CustomerRole::query()->where('customer_id', $customerId)->where('name', '~', $pattern))->delete();

            // An area still holding content the run did not create stays.
            BusinessArea::query()->whereIn('id', $areaIds)->get()
                ->reject(fn (BusinessArea $area): bool => $area->isInUse())
                ->each(fn (BusinessArea $area) => $area->delete());
        });
    }

    private static function namer(string $suffix): \Closure
    {
        return fn (string $label): string => self::PREFIX.' '.strtoupper($suffix).' '.$label;
    }

    private static function pattern(string $suffix): string
    {
        return '^'.preg_quote(self::PREFIX).' '.preg_quote(strtoupper($suffix)).'( |$)';
    }

    private static function customerId(): int
    {
        return (int) User::query()->where('email', self::SYSTEM_OWNER_EMAIL)->value('customer_id');
    }
}
