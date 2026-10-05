<?php

namespace Tests\Support;

use App\Models\BusinessArea;
use App\Models\CustomerRole;
use App\Models\Kpi;
use App\Models\KpiActivity;
use App\Models\KpiMeasurement;
use App\Models\KpiProcess;
use App\Models\KpiStatusChange;
use App\Models\Objective;
use App\Models\ObjectiveStatusChange;
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
 * Every fagområde, role, objective and KPI a spec creates is named «E2E Mål <SUFFIX> <anything>»
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
     * For the KPI spec: one area and a role that reads, edits and deletes objectives there, handed to
     * the E2E user. The spec creates the objective and its KPI itself, through the pages.
     *
     * @return array{area_name: string}
     */
    public static function seedEditor(string $suffix): array
    {
        $customerId = self::customerId();
        $user = User::query()->where('email', self::USER_EMAIL)->firstOrFail();
        $name = fn (string $label): string => self::PREFIX.' '.strtoupper($suffix).' '.$label;

        return DB::transaction(function () use ($customerId, $user, $name): array {
            $area = BusinessArea::query()->create(['customer_id' => $customerId, 'name' => $name('KPI-område')]);

            $role = CustomerRole::query()->create(['customer_id' => $customerId, 'name' => $name('KPI-ansvarlig'), 'is_active' => true]);
            $role->syncPermissions([
                CustomerPermissionCatalog::OBJECTIVE_VIEW,
                CustomerPermissionCatalog::OBJECTIVE_EDIT,
                CustomerPermissionCatalog::OBJECTIVE_DELETE,
            ]);
            $role->syncBusinessAreas(false, [$area->id]);
            $user->customerRoles()->attach($role->id, ['customer_id' => $customerId]);

            return ['area_name' => $area->name];
        });
    }

    /**
     * For the measurement spec: one area and a role that reads, edits, measures and deletes there,
     * handed to the E2E user. The spec creates the objective, the KPI and the measurements itself.
     *
     * @return array{area_name: string}
     */
    public static function seedMeasurer(string $suffix): array
    {
        $customerId = self::customerId();
        $user = User::query()->where('email', self::USER_EMAIL)->firstOrFail();
        $name = fn (string $label): string => self::PREFIX.' '.strtoupper($suffix).' '.$label;

        return DB::transaction(function () use ($customerId, $user, $name): array {
            $area = BusinessArea::query()->create(['customer_id' => $customerId, 'name' => $name('Måleområde')]);

            $role = CustomerRole::query()->create(['customer_id' => $customerId, 'name' => $name('Måler'), 'is_active' => true]);
            $role->syncPermissions([
                CustomerPermissionCatalog::OBJECTIVE_VIEW,
                CustomerPermissionCatalog::OBJECTIVE_EDIT,
                CustomerPermissionCatalog::OBJECTIVE_MEASURE,
                CustomerPermissionCatalog::OBJECTIVE_DELETE,
            ]);
            $role->syncBusinessAreas(false, [$area->id]);
            $user->customerRoles()->attach($role->id, ['customer_id' => $customerId]);

            return ['area_name' => $area->name];
        });
    }

    /**
     * For the readability spec: one area and a role that does everything in Mål og KPI there and
     * reads Kvalitet, handed to the E2E user — so every panel the module has can be opened.
     *
     * @return array{area_name: string}
     */
    public static function seedJourney(string $suffix): array
    {
        $customerId = self::customerId();
        $user = User::query()->where('email', self::USER_EMAIL)->firstOrFail();
        $name = fn (string $label): string => self::PREFIX.' '.strtoupper($suffix).' '.$label;

        return DB::transaction(function () use ($customerId, $user, $name): array {
            $area = BusinessArea::query()->create(['customer_id' => $customerId, 'name' => $name('Reiseområde')]);

            $role = CustomerRole::query()->create(['customer_id' => $customerId, 'name' => $name('Alt i Mål og KPI'), 'is_active' => true]);
            $role->syncPermissions([
                CustomerPermissionCatalog::OBJECTIVE_VIEW,
                CustomerPermissionCatalog::OBJECTIVE_EDIT,
                CustomerPermissionCatalog::OBJECTIVE_MEASURE,
                CustomerPermissionCatalog::OBJECTIVE_DELETE,
                CustomerPermissionCatalog::QUALITY_VIEW,
            ]);
            $role->syncBusinessAreas(false, [$area->id]);
            $user->customerRoles()->attach($role->id, ['customer_id' => $customerId]);

            return ['area_name' => $area->name];
        });
    }

    /**
     * For the process/activity spec: one area; a role for the E2E user that reads, edits and deletes
     * objectives there and reads Kvalitet; and a fresh user — named and mailed with the run's marker
     * — whose only role reads objectives in the same area and nothing in Kvalitet. Kvalitet's own
     * processes are only read, never created or changed: the spec links to what is already there.
     *
     * @return array{area_name: string, reader_email: string}
     */
    public static function seedContext(string $suffix, string $readerPassword): array
    {
        $customerId = self::customerId();
        $user = User::query()->where('email', self::USER_EMAIL)->firstOrFail();
        $name = fn (string $label): string => self::PREFIX.' '.strtoupper($suffix).' '.$label;

        return DB::transaction(function () use ($customerId, $user, $name, $suffix, $readerPassword): array {
            $area = BusinessArea::query()->create(['customer_id' => $customerId, 'name' => $name('Prosessområde')]);

            $editor = CustomerRole::query()->create(['customer_id' => $customerId, 'name' => $name('Prosesskobler'), 'is_active' => true]);
            $editor->syncPermissions([
                CustomerPermissionCatalog::OBJECTIVE_VIEW,
                CustomerPermissionCatalog::OBJECTIVE_EDIT,
                CustomerPermissionCatalog::OBJECTIVE_DELETE,
                CustomerPermissionCatalog::QUALITY_VIEW,
            ]);
            $editor->syncBusinessAreas(false, [$area->id]);
            $user->customerRoles()->attach($editor->id, ['customer_id' => $customerId]);

            $readerRole = CustomerRole::query()->create(['customer_id' => $customerId, 'name' => $name('Leser uten Kvalitet'), 'is_active' => true]);
            $readerRole->syncPermissions([CustomerPermissionCatalog::OBJECTIVE_VIEW]);
            $readerRole->syncBusinessAreas(false, [$area->id]);

            $reader = User::query()->create([
                'name' => $name('Leser'),
                'email' => self::readerEmail($suffix),
                'password' => bcrypt($readerPassword),
                'role' => User::ROLE_USER,
                'bid_role' => User::BID_ROLE_CONTRIBUTOR,
                'customer_id' => $customerId,
                'is_active' => true,
            ]);
            $reader->customerRoles()->attach($readerRole->id, ['customer_id' => $customerId]);

            return ['area_name' => $area->name, 'reader_email' => $reader->email];
        });
    }

    /**
     * For the attention spec: two areas and two fresh users, both named and mailed with the run's
     * marker, so nothing an earlier spec left on the shared E2E users can move a count.
     *
     *  - the planner reads, edits, measures and deletes objectives in the first area only;
     *  - the reader reads objectives in the second area only, and holds objective.edit in the first
     *    through another role — a permission and an area that must never combine. The second area
     *    has one calm objective of its own.
     *
     * @return array{area_name: string, planner_name: string, planner_email: string, reader_email: string, reader_objective: string}
     */
    public static function seedAttention(string $suffix, string $password): array
    {
        $customerId = self::customerId();
        $name = fn (string $label): string => self::PREFIX.' '.strtoupper($suffix).' '.$label;
        $person = fn (string $label, string $mailbox): User => User::query()->create([
            'name' => $name($label),
            'email' => 'e2e.mal.'.strtolower($suffix).'.'.$mailbox.'@procynia.test',
            'password' => bcrypt($password),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customerId,
            'is_active' => true,
        ]);

        return DB::transaction(function () use ($customerId, $name, $person): array {
            $area = BusinessArea::query()->create(['customer_id' => $customerId, 'name' => $name('Oppfølging')]);
            $other = BusinessArea::query()->create(['customer_id' => $customerId, 'name' => $name('Annet område')]);

            $plannerRole = CustomerRole::query()->create(['customer_id' => $customerId, 'name' => $name('Planlegger'), 'is_active' => true]);
            $plannerRole->syncPermissions([
                CustomerPermissionCatalog::OBJECTIVE_VIEW,
                CustomerPermissionCatalog::OBJECTIVE_EDIT,
                CustomerPermissionCatalog::OBJECTIVE_MEASURE,
                CustomerPermissionCatalog::OBJECTIVE_DELETE,
            ]);
            $plannerRole->syncBusinessAreas(false, [$area->id]);
            $planner = $person('Planlegger', 'planlegger');
            $planner->customerRoles()->attach($plannerRole->id, ['customer_id' => $customerId]);

            $readerRole = CustomerRole::query()->create(['customer_id' => $customerId, 'name' => $name('Leser annet område'), 'is_active' => true]);
            $readerRole->syncPermissions([CustomerPermissionCatalog::OBJECTIVE_VIEW]);
            $readerRole->syncBusinessAreas(false, [$other->id]);
            $editorRole = CustomerRole::query()->create(['customer_id' => $customerId, 'name' => $name('Redigerer uten lesing'), 'is_active' => true]);
            $editorRole->syncPermissions([CustomerPermissionCatalog::OBJECTIVE_EDIT]);
            $editorRole->syncBusinessAreas(false, [$area->id]);
            $reader = $person('Leser', 'leser');
            $reader->customerRoles()->attach($readerRole->id, ['customer_id' => $customerId]);
            $reader->customerRoles()->attach($editorRole->id, ['customer_id' => $customerId]);

            $calm = Objective::query()->create([
                'customer_id' => $customerId,
                'business_area_id' => $other->id,
                'title' => $name('Rolig mål'),
                'owner_user_id' => $reader->id,
            ]);

            return [
                'area_name' => $area->name,
                'planner_name' => $planner->name,
                'planner_email' => $planner->email,
                'reader_email' => $reader->email,
                'reader_objective' => $calm->title,
            ];
        });
    }

    /**
     * Moves the creation of a run's objective and its KPIs back to the first day of the month the
     * given number of months ago, and lets the KPIs report with no grace days — so the attention
     * spec has old periods that are expected and past their deadline, without waiting for them.
     */
    public static function backdate(string $suffix, string $objectiveTitle, int $months): void
    {
        $objective = Objective::query()
            ->where('customer_id', self::customerId())
            ->where('title', $objectiveTitle)
            ->where('title', 'like', self::PREFIX.' '.strtoupper($suffix).' %')
            ->firstOrFail();
        $createdAt = now()->subMonthsNoOverflow($months)->startOfMonth();

        DB::table('objectives')->where('id', $objective->id)->update(['created_at' => $createdAt]);
        DB::table('kpis')->where('objective_id', $objective->id)->update(['created_at' => $createdAt, 'reporting_grace_days' => 0]);
    }

    /**
     * Leaves a run's objective without an owner, as when the owner's user is deleted
     * (owner_user_id is nullOnDelete). Past the model, like the deletion itself.
     */
    public static function removeOwner(string $suffix, string $objectiveTitle): void
    {
        DB::table('objectives')
            ->where('customer_id', self::customerId())
            ->where('title', $objectiveTitle)
            ->where('title', 'like', self::PREFIX.' '.strtoupper($suffix).' %')
            ->update(['owner_user_id' => null]);
    }

    /**
     * What is left of one run, for checking that cleanup really emptied it.
     *
     * @return array{areas: int, roles: int, users: int, objectives: int, kpis: int, kpi_processes: int, kpi_activities: int, kpi_measurements: int, kpi_status_changes: int, objective_status_changes: int}
     */
    public static function remaining(string $suffix): array
    {
        $customerId = self::customerId();
        $pattern = '^'.preg_quote(self::PREFIX).' '.preg_quote(strtoupper($suffix)).'( |$)';
        $objectiveIds = Objective::query()->where('customer_id', $customerId)->where('title', '~', $pattern)->pluck('id');
        $kpiIds = Kpi::query()->where('customer_id', $customerId)
            ->where(fn (Builder $query) => $query->where('title', '~', $pattern)->orWhereIn('objective_id', $objectiveIds))
            ->pluck('id');

        return [
            'areas' => BusinessArea::query()->where('customer_id', $customerId)->where('name', '~', $pattern)->count(),
            'roles' => CustomerRole::query()->where('customer_id', $customerId)->where('name', '~', $pattern)->count(),
            'users' => self::runUsers($customerId, $pattern)->count(),
            'objectives' => $objectiveIds->count(),
            'kpis' => $kpiIds->count(),
            'kpi_processes' => KpiProcess::query()->whereIn('kpi_id', $kpiIds)->count(),
            'kpi_activities' => KpiActivity::query()->whereIn('kpi_id', $kpiIds)->count(),
            'kpi_measurements' => KpiMeasurement::query()->whereIn('kpi_id', $kpiIds)->count(),
            'kpi_status_changes' => KpiStatusChange::query()->whereIn('kpi_id', $kpiIds)->count(),
            'objective_status_changes' => ObjectiveStatusChange::query()->whereIn('objective_id', $objectiveIds)->count(),
        ];
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

            // A KPI carrying the marker goes even if a spec put it under someone else's objective;
            // the rest go with their objectives below, and their history with them. A bulk delete,
            // past the models: measurement history and process/activity links go with their KPI
            // through the database cascade. Kvalitet's processes are never touched.
            $sweepOnly(Kpi::query()->where('customer_id', $customerId)->where('title', '~', $pattern))->delete();

            // An objective is the run's when its title carries the marker, or when it lives in one of
            // the run's areas — a spec may retitle it, but it cannot leave an area no one else uses.
            $sweepOnly(Objective::query()->where('customer_id', $customerId)
                ->where(fn (Builder $query) => $query->where('title', '~', $pattern)->orWhereIn('business_area_id', $areaIds)))
                ->delete();

            // Role permissions, area grants and user-role links (also on the seeded E2E users)
            // cascade from the role.
            $sweepOnly(CustomerRole::query()->where('customer_id', $customerId)->where('name', '~', $pattern))->delete();

            // Users the run seeded carry the marker in both name and address; the shared E2E users never do.
            $sweepOnly(self::runUsers($customerId, $pattern))->delete();

            // An area still holding content the run did not create stays.
            BusinessArea::query()->whereIn('id', $areaIds)->get()
                ->reject(fn (BusinessArea $area): bool => $area->isInUse())
                ->each(fn (BusinessArea $area) => $area->delete());
        });
    }

    /** @return Builder<User> */
    private static function runUsers(int $customerId, string $pattern): Builder
    {
        return User::query()
            ->where('customer_id', $customerId)
            ->where('name', '~', $pattern)
            ->where('email', 'like', 'e2e.mal.%@procynia.test');
    }

    private static function readerEmail(string $suffix): string
    {
        return 'e2e.mal.'.strtolower($suffix).'.leser@procynia.test';
    }

    private static function customerId(): int
    {
        return (int) User::query()->where('email', self::SYSTEM_OWNER_EMAIL)->value('customer_id');
    }
}
