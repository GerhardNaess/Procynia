<?php

namespace Tests\Support;

use App\Models\BusinessArea;
use App\Models\CustomerRole;
use App\Models\ImprovementAction;
use App\Models\ImprovementActionStatusChange;
use App\Models\ImprovementActionVerification;
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
 * Users a spec seeds carry the marker in their name and an «e2e.avvik.» address; the shared E2E
 * users never do.
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
     * open case and a case under arbeid in the readable area, and one case in the hidden area. The
     * open case has one tiltak under arbeid, owned by the reader — owning it gives no edit right.
     *
     * @return array{visible_id: int, in_progress_id: int, hidden_id: int, action_title: string}
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

            // A tiltak under arbeid, the way the lifecycle would have put it there.
            $action = ImprovementAction::query()->create([
                'customer_id' => $customerId,
                'improvement_case_id' => $visible->id,
                'title' => $name('Oppdater rutinen'),
                'owner_user_id' => $user->id,
                'due_date' => now()->addMonth()->toDateString(),
            ]);
            ImprovementActionStatusChange::query()->create([
                'customer_id' => $customerId,
                'improvement_action_id' => $action->id,
                'from_status' => ImprovementAction::STATUS_PLANNED,
                'to_status' => ImprovementAction::STATUS_IN_PROGRESS,
                'changed_by_user_id' => $user->id,
                'changed_at' => now(),
            ]);
            $action->forceFill(['status' => ImprovementAction::STATUS_IN_PROGRESS])->save();

            return [
                'visible_id' => (int) $visible->id,
                'in_progress_id' => (int) $inProgress->id,
                'hidden_id' => (int) $secret->id,
                'action_title' => $action->title,
            ];
        });
    }

    /**
     * For the effektverifisering journey: one area and a fresh person who does everything with cases
     * there — fresh, so nothing an earlier spec left on the shared E2E users can move a count on the
     * Trenger oppmerksomhet panel.
     *
     * @return array{area_name: string, handler_name: string, handler_email: string}
     */
    public static function seedJourney(string $suffix, string $password): array
    {
        $customerId = self::customerId();
        $name = self::namer($suffix);

        return DB::transaction(function () use ($customerId, $name, $suffix, $password): array {
            $area = BusinessArea::query()->create(['customer_id' => $customerId, 'name' => $name('Effekt')]);
            $handler = self::person($customerId, $suffix, $password, $name('Saksbehandler'), 'behandler');
            self::role($customerId, $name('Saksbehandler'), [
                CustomerPermissionCatalog::IMPROVEMENT_VIEW,
                CustomerPermissionCatalog::IMPROVEMENT_EDIT,
                CustomerPermissionCatalog::IMPROVEMENT_CLOSE,
                CustomerPermissionCatalog::IMPROVEMENT_DELETE,
            ], $area, $handler);

            return ['area_name' => $area->name, 'handler_name' => $handler->name, 'handler_email' => $handler->email];
        });
    }

    /**
     * For the attention spec: in one area, an open case past its frist and without owner, holding a
     * tiltak past its frist, a tiltak without owner and a completed tiltak nobody has judged; one
     * calm tiltak beside them; and a case full of findings in a second area nobody here can read.
     * Two fresh people read the first area: a reader (view only) and a decider (view and close, no
     * edit).
     *
     * @return array{area_name: string, case_title: string, case_id: int, case_due: string, overdue_action: string, overdue_action_due: string, ownerless_action: string, completed_action: string, completed_on: string, reader_email: string, closer_email: string}
     */
    public static function seedAttention(string $suffix, string $password): array
    {
        $customerId = self::customerId();
        $name = self::namer($suffix);

        return DB::transaction(function () use ($customerId, $name, $suffix, $password): array {
            $area = BusinessArea::query()->create(['customer_id' => $customerId, 'name' => $name('Oppfølging')]);
            $hidden = BusinessArea::query()->create(['customer_id' => $customerId, 'name' => $name('Skjult område')]);

            $reader = self::person($customerId, $suffix, $password, $name('Leser'), 'leser');
            self::role($customerId, $name('Leser'), [CustomerPermissionCatalog::IMPROVEMENT_VIEW], $area, $reader);
            $closer = self::person($customerId, $suffix, $password, $name('Beslutter'), 'beslutter');
            self::role($customerId, $name('Beslutter'), [CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::IMPROVEMENT_CLOSE], $area, $closer);

            $today = now()->startOfDay();
            $caseDue = $today->copy()->subDays(5);
            $actionDue = $today->copy()->subDays(3);
            $completedOn = $today->copy()->subDays(2)->setTime(10, 0);

            $case = fn (BusinessArea $in, string $title, ?User $owner) => ImprovementCase::query()->create([
                'customer_id' => $customerId,
                'business_area_id' => $in->id,
                'type' => ImprovementCase::TYPE_DEVIATION,
                'title' => $title,
                'description' => 'Registrert av E2E-fixturen.',
                'owner_user_id' => $owner?->id,
                'reported_by_user_id' => $reader->id,
                'due_date' => $caseDue->toDateString(),
            ]);
            $action = fn (ImprovementCase $under, string $title, ?User $owner, $due) => ImprovementAction::query()->create([
                'customer_id' => $customerId,
                'improvement_case_id' => $under->id,
                'title' => $title,
                'owner_user_id' => $owner?->id,
                'due_date' => $due->toDateString(),
            ]);

            $late = $case($area, $name('Forsinket sak uten ansvarlig'), null);
            $overdue = $action($late, $name('Forsinket tiltak'), $reader, $actionDue);
            $ownerless = $action($late, $name('Tiltak uten ansvarlig'), null, $today->copy()->addMonth());
            $action($late, $name('Tiltak i rute'), $reader, $today->copy()->addMonth());
            $completed = $action($late, $name('Fullført tiltak'), $reader, $today->copy()->addMonth());

            // Completed the way the lifecycle would have done it, two days ago.
            ImprovementActionStatusChange::query()->create([
                'customer_id' => $customerId,
                'improvement_action_id' => $completed->id,
                'from_status' => ImprovementAction::STATUS_PLANNED,
                'to_status' => ImprovementAction::STATUS_COMPLETED,
                'note' => 'Rutinen er oppdatert.',
                'changed_by_user_id' => $reader->id,
                'changed_at' => $completedOn,
            ]);
            $completed->forceFill([
                'status' => ImprovementAction::STATUS_COMPLETED,
                'completed_at' => $completedOn,
                'completed_by_user_id' => $reader->id,
                'completion_note' => 'Rutinen er oppdatert.',
            ])->save();

            // Findings nobody here may see.
            $secret = $case($hidden, $name('Skjult sak'), null);
            $action($secret, $name('Skjult tiltak'), null, $actionDue);

            return [
                'area_name' => $area->name,
                'case_title' => $late->title,
                'case_id' => (int) $late->id,
                'case_due' => $caseDue->toDateString(),
                'overdue_action' => $overdue->title,
                'overdue_action_due' => $actionDue->toDateString(),
                'ownerless_action' => $ownerless->title,
                'completed_action' => $completed->title,
                'completed_on' => $completedOn->toDateString(),
                'reader_email' => $reader->email,
                'closer_email' => $closer->email,
            ];
        });
    }

    /**
     * What is left of one run, for checking that cleanup really emptied it.
     *
     * @return array{areas: int, roles: int, users: int, cases: int, status_changes: int, actions: int, action_status_changes: int, action_verifications: int, processes: int, activities: int}
     */
    public static function remaining(string $suffix): array
    {
        $customerId = self::customerId();
        $pattern = self::pattern($suffix);
        $areaIds = BusinessArea::query()->where('customer_id', $customerId)->where('name', '~', $pattern)->pluck('id');
        $caseIds = ImprovementCase::query()->where('customer_id', $customerId)
            ->where(fn (Builder $query) => $query->where('title', '~', $pattern)->orWhereIn('business_area_id', $areaIds))
            ->pluck('id');

        $actionIds = ImprovementAction::query()->whereIn('improvement_case_id', $caseIds)->pluck('id');

        return [
            'areas' => $areaIds->count(),
            'roles' => CustomerRole::query()->where('customer_id', $customerId)->where('name', '~', $pattern)->count(),
            'users' => self::runUsers($customerId, $pattern)->count(),
            'cases' => $caseIds->count(),
            'status_changes' => ImprovementCaseStatusChange::query()->whereIn('improvement_case_id', $caseIds)->count(),
            'actions' => $actionIds->count(),
            'action_status_changes' => ImprovementActionStatusChange::query()->whereIn('improvement_action_id', $actionIds)->count(),
            'action_verifications' => ImprovementActionVerification::query()->whereIn('improvement_action_id', $actionIds)->count(),
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
            // bulk delete, past the model: history rows, tiltak with their history and effektverifisering
            // and Kvalitet links go with the case through the database cascade. Kvalitet's processes are
            // never touched.
            $sweepOnly(ImprovementCase::query()->where('customer_id', $customerId)
                ->where(fn (Builder $query) => $query->where('title', '~', $pattern)->orWhereIn('business_area_id', $areaIds)))
                ->delete();

            // Role permissions, area grants and user-role links cascade from the role.
            $sweepOnly(CustomerRole::query()->where('customer_id', $customerId)->where('name', '~', $pattern))->delete();

            // Users the run seeded carry the marker in both name and address; the shared E2E users never do.
            $sweepOnly(self::runUsers($customerId, $pattern))->delete();

            // An area still holding content the run did not create stays.
            BusinessArea::query()->whereIn('id', $areaIds)->get()
                ->reject(fn (BusinessArea $area): bool => $area->isInUse())
                ->each(fn (BusinessArea $area) => $area->delete());
        });
    }

    /** A fresh, active person of the E2E customer, marked with the run's suffix. */
    private static function person(int $customerId, string $suffix, string $password, string $name, string $mailbox): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => 'e2e.avvik.'.strtolower($suffix).'.'.$mailbox.'@procynia.test',
            'password' => bcrypt($password),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customerId,
            'is_active' => true,
        ]);
    }

    /** @param  list<string>  $permissionKeys */
    private static function role(int $customerId, string $name, array $permissionKeys, BusinessArea $area, User $holder): CustomerRole
    {
        $role = CustomerRole::query()->create(['customer_id' => $customerId, 'name' => $name, 'is_active' => true]);
        $role->syncPermissions($permissionKeys);
        $role->syncBusinessAreas(false, [$area->id]);
        $holder->customerRoles()->attach($role->id, ['customer_id' => $customerId]);

        return $role;
    }

    /** @return Builder<User> */
    private static function runUsers(int $customerId, string $pattern): Builder
    {
        return User::query()
            ->where('customer_id', $customerId)
            ->where('name', '~', $pattern)
            ->where('email', 'like', 'e2e.avvik.%@procynia.test');
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
