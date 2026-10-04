<?php

namespace Tests\Support;

use App\Models\BusinessArea;
use App\Models\CustomerRole;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Test-only cleanup for the Risk E2E specs (tests/e2e/risk-*.spec.js). Not autoloaded in
 * production (autoload-dev only) — invoked via `php artisan tinker --execute=...`, mirroring the
 * Wiki E2E fixtures.
 *
 * The specs create their fagområder, roles and risks through the UI, each named from a fixed
 * template plus the run's six-character suffix. Cleanup only matches those exact templates, in the
 * E2E customer, so nothing a person created there — or another spec — can be hit.
 */
class RiskE2EFixture
{
    private const SYSTEM_OWNER_EMAIL = 'e2e.systemowner@procynia.test';

    private const SUFFIX_PATTERN = '[A-Z0-9]{6}';

    /** Leftovers from interrupted runs are only swept once they are this old, so a run still in flight is never touched. */
    private const SWEEP_MIN_AGE_MINUTES = 10;

    private const AREA_NAMES = [
        'E2E Beredskap', 'E2E Drift', 'E2E Eget område', 'E2E Kontekst', 'E2E Lønn',
        'E2E Oppmerksomhet', 'E2E Skjult område',
    ];

    private const ROLE_NAMES = [
        'E2E Egen risikorolle', 'E2E Risikoansvarlig', 'E2E Risikobehandling', 'E2E Risikobeskrivelse',
        'E2E Risikobeslutning', 'E2E Risikogjennomgang', 'E2E Risikoleser alle', 'E2E Risiko og kontekst',
        'E2E Risiko og kontroll', 'E2E Risiko og tiltak', 'E2E Risikovurderer', 'E2E Oppmerksomhet',
    ];

    private const RISK_TITLES = [
        'E2E Datasenter', 'E2E Feil i godkjenning', 'E2E Feil lønnsutbetaling', 'E2E Serverbrann',
        'E2E Strømbrudd i datasenter', 'E2E Strømbrudd', 'E2E Svikt i backup',
        'E2E Høy restrisiko', 'E2E Uten restrisiko', 'E2E Forfalt vurdering', 'E2E Forfalt tiltak',
        'E2E Rolig', 'E2E Skjult risiko', 'E2E Skjult uten vurdering',
    ];

    /** Users are only created by the attention spec's seed; the others reuse the seeded E2E users. */
    private const USER_EMAIL_PREFIX = 'e2e\.attention\.';

    /**
     * Removes what one run created (by its suffix), or — with no suffix — whatever interrupted
     * runs left behind. Idempotent, and safe after a run that stopped halfway.
     */
    public static function cleanup(?string $suffix = null): void
    {
        $customerId = (int) User::query()->where('email', self::SYSTEM_OWNER_EMAIL)->value('customer_id');
        $suffixRegex = $suffix === null ? self::SUFFIX_PATTERN : preg_quote(strtoupper($suffix));
        $sweepOnly = fn (Builder $query): Builder => $suffix === null
            ? $query->where('created_at', '<', now()->subMinutes(self::SWEEP_MIN_AGE_MINUTES))
            : $query;

        DB::transaction(function () use ($customerId, $suffixRegex, $sweepOnly): void {
            $areaIds = $sweepOnly(self::named(BusinessArea::query(), $customerId, 'name', self::AREA_NAMES, $suffixRegex))->pluck('id');

            // A risk is only removed when both its title and its fagområde carry the run's markers.
            // Deleting the risk row cascades in the database to its assessments, acceptances,
            // treatment actions, control links and process/activity context — the immutable
            // history models are never deleted one by one.
            $sweepOnly(self::named(Risk::query(), $customerId, 'title', self::RISK_TITLES, $suffixRegex))
                ->whereIn('business_area_id', $areaIds)
                ->delete();

            // Role permissions, area grants and user-role links (also on the seeded E2E users)
            // cascade from the role.
            $sweepOnly(self::named(CustomerRole::query(), $customerId, 'name', self::ROLE_NAMES, $suffixRegex))->delete();

            // risks.business_area_id restricts: an area still holding an unrecognised risk stays.
            BusinessArea::query()->whereIn('id', $areaIds)->whereDoesntHave('risks')->delete();

            $sweepOnly(User::query()->where('customer_id', $customerId)
                ->where('email', '~', '^'.self::USER_EMAIL_PREFIX.strtolower($suffixRegex).'@procynia\.test$'))
                ->delete();
        });
    }

    /**
     * @param  list<string>  $templates
     */
    private static function named(Builder $query, int $customerId, string $column, array $templates, string $suffixRegex): Builder
    {
        $alternatives = implode('|', array_map(fn (string $name): string => preg_quote($name), $templates));

        return $query->where('customer_id', $customerId)->where($column, '~', "^({$alternatives}) {$suffixRegex}$");
    }
}
