<?php

namespace Tests\Support;

use App\Models\BusinessArea;
use App\Models\CustomerRole;
use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\RiskTreatmentAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Test-only fixture for tests/e2e/risk-attention.spec.js. Not autoloaded in production
 * (autoload-dev only) — invoked via `php artisan tinker --execute=...` from the Playwright spec,
 * mirroring the Wiki E2E fixtures.
 *
 * Everything it creates carries the run's six-character suffix, and cleanup only ever matches
 * the exact names this fixture produces, in the E2E customer — never other customer data.
 */
class RiskAttentionE2EFixture
{
    private const SYSTEM_OWNER_EMAIL = 'e2e.systemowner@procynia.test';

    private const SUFFIX_PATTERN = '[A-Z0-9]{6}';

    public static function seed(string $suffix, string $password): string
    {
        $customerId = self::customerId();
        $email = self::email($suffix);

        DB::transaction(function () use ($customerId, $suffix, $email, $password): void {
            $visible = BusinessArea::create(['customer_id' => $customerId, 'name' => "E2E Oppmerksomhet {$suffix}"]);
            $hidden = BusinessArea::create(['customer_id' => $customerId, 'name' => "E2E Skjult område {$suffix}"]);

            $user = User::create(['name' => "E2E Oppmerksomhet {$suffix}", 'email' => $email, 'password' => bcrypt($password),
                'role' => 'user', 'bid_role' => 'contributor', 'customer_id' => $customerId, 'is_active' => true]);
            $role = CustomerRole::create(['customer_id' => $customerId, 'name' => "E2E Oppmerksomhet {$suffix}", 'is_active' => true]);
            $role->syncPermissions(['risk.view']);
            $role->syncBusinessAreas(false, [$visible->id]);
            $user->customerRoles()->attach($role->id, ['customer_id' => $customerId]);

            $risk = fn (BusinessArea $area, string $title, ?int $interval = null): Risk => Risk::create([
                'customer_id' => $customerId, 'business_area_id' => $area->id, 'title' => "{$title} {$suffix}",
                'cause' => 'manglende rutiner', 'event' => 'en hendelse', 'consequence' => 'tap', 'status' => 'identified',
                'review_interval_months' => $interval]);
            $assess = fn (Risk $r, $at, ?array $residual): RiskAssessment => RiskAssessment::create([
                'customer_id' => $customerId, 'risk_id' => $r->id, 'assessed_at' => $at,
                'rationale' => 'E2E', 'criteria_key' => 'standard_5x5_v1', 'inherent_likelihood' => 4, 'inherent_consequence' => 4,
                'residual_likelihood' => $residual[0] ?? null, 'residual_consequence' => $residual[1] ?? null]);
            $action = fn (Risk $r, string $due): RiskTreatmentAction => RiskTreatmentAction::create([
                'customer_id' => $customerId, 'risk_id' => $r->id, 'title' => 'E2E tiltak', 'due_at' => $due, 'status' => 'open']);

            $assess($risk($visible, 'E2E Høy restrisiko'), now(), [4, 4]);
            $assess($risk($visible, 'E2E Uten restrisiko'), now(), null);
            $assess($risk($visible, 'E2E Forfalt vurdering', 1), now()->subMonths(3), [1, 1]);
            $withAction = $risk($visible, 'E2E Forfalt tiltak');
            $assess($withAction, now(), [1, 1]);
            $action($withAction, now()->subDays(10)->toDateString());
            $assess($risk($visible, 'E2E Rolig'), now(), [1, 1]);
            $secret = $risk($hidden, 'E2E Skjult risiko', 1);
            $assess($secret, now()->subYear(), [5, 5]);
            $action($secret, now()->subDays(30)->toDateString());
            $risk($hidden, 'E2E Skjult uten vurdering');
        });

        return $email;
    }

    /**
     * Removes what seed() created for one suffix, or — with no suffix — whatever earlier runs of
     * this spec left behind. Idempotent, and safe after a seed that stopped halfway.
     */
    public static function cleanup(?string $suffix = null): void
    {
        $customerId = self::customerId();
        $suffixRegex = $suffix === null ? self::SUFFIX_PATTERN : preg_quote(strtoupper($suffix));

        DB::transaction(function () use ($customerId, $suffixRegex): void {
            $areaIds = BusinessArea::query()
                ->where('customer_id', $customerId)
                ->where('name', '~', "^E2E (Oppmerksomhet|Skjult område) {$suffixRegex}$")
                ->pluck('id');

            // risks.business_area_id restricts, so the risks go first. Deleting a risk row cascades
            // in the database to its assessments, acceptances, treatment actions, controls and
            // process/activity context — the immutable history models are never deleted one by one.
            Risk::query()->where('customer_id', $customerId)->whereIn('business_area_id', $areaIds)->delete();

            // Role permissions, area grants and user-role links cascade from the role.
            self::matching(CustomerRole::query(), $customerId, 'name', "^E2E Oppmerksomhet {$suffixRegex}$")->delete();
            BusinessArea::query()->whereIn('id', $areaIds)->delete();
            self::matching(User::query(), $customerId, 'email', '^e2e\.attention\.'.strtolower($suffixRegex).'@procynia\.test$')->delete();
        });
    }

    private static function matching(Builder $query, int $customerId, string $column, string $regex): Builder
    {
        return $query->where('customer_id', $customerId)->where($column, '~', $regex);
    }

    private static function email(string $suffix): string
    {
        return 'e2e.attention.'.strtolower($suffix).'@procynia.test';
    }

    private static function customerId(): int
    {
        return (int) User::query()->where('email', self::SYSTEM_OWNER_EMAIL)->value('customer_id');
    }
}
