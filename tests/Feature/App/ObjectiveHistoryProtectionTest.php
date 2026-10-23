<?php

namespace Tests\Feature\App;

use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\CustomerRole;
use App\Models\Kpi;
use App\Models\KpiMeasurement;
use App\Models\KpiStatusChange;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\Objective;
use App\Models\ObjectiveStatusChange;
use App\Models\User;
use App\Services\Modules\ModuleEntitlementService;
use App\Services\Objectives\KpiLifecycleService;
use App\Services\Objectives\KpiMeasurementService;
use App\Services\Objectives\KpiPeriods;
use App\Services\Objectives\ObjectiveLifecycleService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\Support\ObjectiveTestDataPurge;
use Tests\TestCase;

/**
 * The history of Mål og KPI cannot be lost, phase 0 step 15 (docs/external-api-phase-0-plan.md §2k).
 *
 * Measurements and status changes were guarded by the models only. Now the database refuses the
 * same: a measurement is never deleted on its own and changes only by being withdrawn, once; status
 * changes never change; a KPI with a measurement — or an objective whose KPIs have one — is not
 * deleted, not past isDeletable() through Eloquent and not in SQL. Since A40 the same holds for an
 * objective that has been closed and a KPI that has been retired, with no measurements. An unused
 * objective or KPI — created and edited only — is deleted as before; the customer's own deletion
 * still takes everything along.
 */
class ObjectiveHistoryProtectionTest extends TestCase
{
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->withoutMiddleware(ValidateCsrfToken::class);
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    /**
     * Unchanged: an unused KPI and objective — created and edited, nothing measured, never closed or
     * retired — are deleted through the page as before. Creating and editing are not decisions.
     */
    public function test_an_unused_objective_or_kpi_is_deleted_as_before(): void
    {
        $world = $this->world();
        $mistake = $this->objective($world, 'Registrert ved en feil');
        $unused = $this->kpi($mistake, 'Aldri brukt');
        $mistake->forceFill(['title' => 'Registrert ved en feil, rettet'])->save();
        $empty = $this->kpi($world['objective'], 'Tom');

        $this->assertTrue($this->objectivePermissions($world['person'], $mistake)['can_delete']);
        $this->assertTrue($this->kpiPermissions($world['person'], $empty)['can_delete']);

        $this->actingAs($world['person'])->delete("/app/objectives/{$world['objective']->id}/kpis/{$empty->id}")->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('kpis', ['id' => $empty->id]);

        $this->actingAs($world['person'])->delete("/app/objectives/{$mistake->id}")->assertRedirect('/app/objectives');
        $this->assertDatabaseMissing('objectives', ['id' => $mistake->id]);
        $this->assertDatabaseMissing('kpis', ['id' => $unused->id]);
    }

    /**
     * A40: an objective that has been closed — Nådd, Ikke nådd or Avbrutt, reopened or not — is kept,
     * with no measurements at all. Refused by the service, the model and the database; «Slett» is not
     * offered; the closing stays in its history.
     */
    public function test_an_objective_that_has_been_closed_is_never_deleted(): void
    {
        $world = $this->world();
        $lifecycle = app(ObjectiveLifecycleService::class);
        $cases = [];

        foreach ([Objective::STATUS_ACHIEVED, Objective::STATUS_NOT_ACHIEVED, Objective::STATUS_CANCELLED] as $outcome) {
            $objective = $this->objective($world, "Lukket {$outcome}");
            $lifecycle->close($objective, $world['person'], $outcome, null);
            $cases["closed {$outcome}"] = $objective;
        }

        $reopened = $this->objective($world, 'Gjenåpnet');
        $lifecycle->close($reopened, $world['person'], Objective::STATUS_ACHIEVED, null);
        $lifecycle->reopen($reopened->fresh(), $world['person'], 'Feil lukket');
        $cases['closed and reopened'] = $reopened;

        foreach ($cases as $name => $objective) {
            $this->assertFalse($objective->fresh()->isDeletable(), $name);
            $this->assertFalse($this->objectivePermissions($world['person'], $objective)['can_delete'], "{$name}: «Slett» not offered");
            $this->actingAs($world['person'])->delete("/app/objectives/{$objective->id}")->assertSessionHas('error', 'Målet kan ikke slettes.');

            try {
                app(ObjectiveLifecycleService::class)->delete($world['person'], $objective);
                $this->fail("{$name}: the service must refuse");
            } catch (ConflictHttpException) {
                $this->addToAssertionCount(1);
            }

            try {
                $objective->fresh()->delete();
                $this->fail("{$name}: the model must refuse");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }

            $this->assertRefused(fn () => DB::table('objectives')->where('id', $objective->id)->delete(), $name);
            $this->assertDatabaseHas('objectives', ['id' => $objective->id]);
            $this->assertGreaterThan(0, ObjectiveStatusChange::query()->where('objective_id', $objective->id)->count(), $name);
        }
    }

    /**
     * A40: a KPI that has been retired, reopened or not, is kept with no measurements — and so is the
     * objective it belongs to.
     */
    public function test_a_kpi_that_has_been_retired_is_never_deleted_nor_its_objective(): void
    {
        $world = $this->world();
        $objective = $this->objective($world, 'Uten målinger');
        $retired = $this->kpi($objective, 'Avsluttet');
        $reopened = $this->kpi($objective, 'Gjenåpnet');
        $lifecycle = app(KpiLifecycleService::class);
        $lifecycle->retire($retired, $world['person'], null);
        $lifecycle->retire($reopened, $world['person'], null);
        $lifecycle->reopen($reopened->fresh(), $world['person'], 'Måles igjen');

        foreach (['retired' => $retired, 'retired and reopened' => $reopened] as $name => $kpi) {
            $this->assertFalse($kpi->fresh()->isDeletable(), $name);
            $this->assertFalse($this->kpiPermissions($world['person'], $kpi)['can_delete'], "{$name}: «Slett KPI» not offered");
            $this->actingAs($world['person'])->delete("/app/objectives/{$objective->id}/kpis/{$kpi->id}")->assertSessionHas('error');

            try {
                app(KpiLifecycleService::class)->delete($world['person'], $kpi);
                $this->fail("{$name}: the service must refuse");
            } catch (ConflictHttpException) {
                $this->addToAssertionCount(1);
            }

            try {
                $kpi->fresh()->delete();
                $this->fail("{$name}: the model must refuse");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }

            $this->assertRefused(fn () => DB::table('kpis')->where('id', $kpi->id)->delete(), $name);
            $this->assertDatabaseHas('kpis', ['id' => $kpi->id]);
        }

        // The objective itself was never closed; its KPIs' history keeps it, here and in SQL.
        $this->assertFalse($objective->fresh()->isDeletable());
        $this->assertFalse($this->objectivePermissions($world['person'], $objective)['can_delete']);
        $this->assertRefused(fn () => DB::table('objectives')->where('id', $objective->id)->delete(), 'the objective of a retired KPI');
        $this->assertSame(3, KpiStatusChange::query()->whereIn('kpi_id', [$retired->id, $reopened->id])->count());
    }

    /** A KPI with a measurement — a withdrawn one too — is refused by the service, the model and the database. */
    public function test_a_kpi_with_a_measurement_is_never_deleted(): void
    {
        $world = $this->world();
        $withdrawnOnly = $this->kpi($world['objective'], 'Bare tilbaketrukket');
        $measurement = $this->record($withdrawnOnly, $world['person']);
        app(KpiMeasurementService::class)->withdraw($measurement, $world['person'], 'Feil kilde');

        foreach (['with a measurement' => $world['kpi'], 'with a withdrawn measurement only' => $withdrawnOnly] as $name => $kpi) {
            try {
                app(KpiLifecycleService::class)->delete($world['person'], $kpi);
                $this->fail("{$name}: the service must refuse");
            } catch (ConflictHttpException) {
                $this->addToAssertionCount(1);
            }

            try {
                $kpi->fresh()->delete();
                $this->fail("{$name}: the model must refuse");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }

            $this->assertRefused(fn () => DB::table('kpis')->where('id', $kpi->id)->delete(), $name);
            $this->assertDatabaseHas('kpis', ['id' => $kpi->id]);
            $this->assertSame(1, KpiMeasurement::query()->where('kpi_id', $kpi->id)->count(), $name);
        }
    }

    /** An objective whose KPIs carry measurements is closed, never deleted — in SQL neither. */
    public function test_an_objective_whose_kpis_have_measurements_is_never_deleted(): void
    {
        $world = $this->world();

        try {
            app(ObjectiveLifecycleService::class)->delete($world['person'], $world['objective']);
            $this->fail('the service must refuse');
        } catch (ConflictHttpException) {
            $this->addToAssertionCount(1);
        }

        try {
            $world['objective']->fresh()->delete();
            $this->fail('the model must refuse');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $this->assertRefused(fn () => DB::table('objectives')->where('id', $world['objective']->id)->delete(), 'an objective with measurements');
        $this->assertDatabaseHas('objectives', ['id' => $world['objective']->id]);
        $this->assertSame(1, KpiMeasurement::query()->where('kpi_id', $world['kpi']->id)->count());
    }

    /** A measurement is withdrawn once and otherwise never changed or deleted — not even in SQL. */
    public function test_a_measurement_changes_only_by_being_withdrawn_once(): void
    {
        $world = $this->world();
        $id = $world['measurement']->id;
        $table = DB::table('kpi_measurements')->where('id', $id);

        foreach ([
            'deleting it' => fn () => DB::table('kpi_measurements')->where('id', $id)->delete(),
            'changing the value' => fn () => DB::table('kpi_measurements')->where('id', $id)->update(['value' => '10']),
            'moving the period' => fn () => DB::table('kpi_measurements')->where('id', $id)->update(['period_start' => '2026-02-01', 'period_end' => '2026-02-28']),
            'rewriting the målverdi it was judged against' => fn () => DB::table('kpi_measurements')->where('id', $id)->update(['target_min' => '1']),
            'changing who registered it' => fn () => DB::table('kpi_measurements')->where('id', $id)->update(['recorded_by_user_id' => $world['other']->id]),
            'moving it to another KPI' => fn () => DB::table('kpi_measurements')->where('id', $id)->update(['kpi_id' => $world['spare']->id]),
        ] as $what => $statement) {
            $this->assertRefused($statement, $what);
        }

        app(KpiMeasurementService::class)->withdraw($world['measurement'], $world['person'], 'Feil kilde');
        $this->assertNotNull($table->value('withdrawn_at'));

        foreach ([
            'un-withdrawing it' => ['withdrawn_at' => null, 'withdrawn_by_user_id' => null, 'withdrawal_reason' => null],
            're-dating the withdrawal' => ['withdrawn_at' => now()->subYear()],
            're-arguing the withdrawal' => ['withdrawal_reason' => 'En annen grunn'],
            'changing who withdrew it' => ['withdrawn_by_user_id' => $world['other']->id],
        ] as $what => $change) {
            $this->assertRefused(fn () => DB::table('kpi_measurements')->where('id', $id)->update($change), $what);
        }

        $this->assertSame('Feil kilde', $table->value('withdrawal_reason'));
    }

    /** Status history is never changed or deleted on its own. */
    public function test_status_history_cannot_be_deleted_or_changed(): void
    {
        $world = $this->world();
        app(KpiLifecycleService::class)->retire($world['spare'], $world['person'], 'Måles ikke lenger');
        $closing = $this->objective($world, 'Lukket');
        app(ObjectiveLifecycleService::class)->close($closing, $world['person'], Objective::STATUS_ACHIEVED, 'Nådd');

        foreach ([
            'objective_status_changes' => ObjectiveStatusChange::query()->where('objective_id', $closing->id)->sole()->id,
            'kpi_status_changes' => KpiStatusChange::query()->where('kpi_id', $world['spare']->id)->sole()->id,
        ] as $table => $id) {
            $this->assertRefused(fn () => DB::table($table)->where('id', $id)->delete(), "deleting from {$table}");
            $this->assertRefused(fn () => DB::table($table)->where('id', $id)->update(['note' => 'Omskrevet']), "rewriting {$table}");
            $this->assertRefused(fn () => DB::table($table)->where('id', $id)->update(['changed_by_user_id' => $world['other']->id]), "changing who did it in {$table}");
        }

        $this->assertRefused(fn () => DB::table('objective_status_changes')->where('objective_id', $closing->id)->update(['to_status' => Objective::STATUS_NOT_ACHIEVED]), 'changing the outcome');
    }

    /** Who did what is on every history row, and stays when the person is deleted (nameless). */
    public function test_history_keeps_its_rows_when_the_person_is_deleted(): void
    {
        $world = $this->world();
        $leaver = $this->person($world['customer'], $world['area']);
        $measurement = $this->record($world['spare'], $leaver);
        app(KpiMeasurementService::class)->withdraw($measurement, $leaver, 'Feil');
        app(KpiLifecycleService::class)->retire($world['spare'], $leaver, null);

        $leaver->forceDelete();

        $row = DB::table('kpi_measurements')->where('id', $measurement->id)->first();
        $this->assertNull($row->recorded_by_user_id);
        $this->assertNull($row->withdrawn_by_user_id);
        $this->assertSame('Feil', $row->withdrawal_reason);
        $this->assertNull(KpiStatusChange::query()->where('kpi_id', $world['spare']->id)->value('changed_by_user_id'));
    }

    /** The one deletion that takes history along: the customer's own. */
    public function test_deleting_the_customer_still_takes_objectives_and_history_along(): void
    {
        $world = $this->world();
        app(ObjectiveLifecycleService::class)->close($world['objective'], $world['person'], Objective::STATUS_ACHIEVED, null);

        DB::table('customers')->where('id', $world['customer']->id)->delete();

        $this->assertDatabaseMissing('objectives', ['id' => $world['objective']->id]);
        $this->assertDatabaseMissing('kpis', ['id' => $world['kpi']->id]);
        $this->assertDatabaseMissing('kpi_measurements', ['id' => $world['measurement']->id]);
        $this->assertSame(0, ObjectiveStatusChange::query()->where('objective_id', $world['objective']->id)->count());
    }

    /** E2E runs purge their own objectives through a test-only step; the protection is on again after it. */
    public function test_only_test_support_purges_objective_history(): void
    {
        $world = $this->world();

        $closed = $this->objective($world, 'Lukket');
        app(ObjectiveLifecycleService::class)->close($closed, $world['person'], Objective::STATUS_ACHIEVED, null);

        ObjectiveTestDataPurge::delete(Objective::query()->whereKey([$world['objective']->id, $closed->id]));

        $this->assertDatabaseMissing('objectives', ['id' => $world['objective']->id]);
        $this->assertDatabaseMissing('objectives', ['id' => $closed->id]);
        $this->assertDatabaseMissing('kpi_measurements', ['id' => $world['measurement']->id]);

        $other = $this->objective($world, 'Etter rydding');
        $kpi = $this->kpi($other, 'Måles');
        $this->record($kpi, $world['person']);
        $this->assertRefused(fn () => DB::table('kpis')->where('id', $kpi->id)->delete(), 'after the purge');
        app(ObjectiveLifecycleService::class)->close($other, $world['person'], Objective::STATUS_ACHIEVED, null);
        $this->assertRefused(fn () => DB::table('objectives')->where('id', $other->id)->delete(), 'a closed objective after the purge');
    }

    /** @return array<string, bool> */
    private function objectivePermissions(User $user, Objective $objective): array
    {
        return $this->actingAs($user)->get("/app/objectives/{$objective->id}")->assertOk()->viewData('page')['props']['permissions'];
    }

    /** @return array<string, bool> */
    private function kpiPermissions(User $user, Kpi $kpi): array
    {
        return $this->actingAs($user)->get("/app/objectives/{$kpi->objective_id}/kpis/{$kpi->id}")->assertOk()->viewData('page')['props']['permissions'];
    }

    private function assertRefused(callable $statement, string $what): void
    {
        DB::beginTransaction();

        try {
            $statement();
            $this->fail("the database must refuse {$what}");
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        } finally {
            DB::rollBack();
        }
    }

    /**
     * An objective with «Oppetid» (one measurement, January 2026) and «Svartid» (none); a person who
     * may do everything in the area, and another person of the customer.
     *
     * @return array<string, mixed>
     */
    private function world(): array
    {
        $customer = $this->customer();
        $area = BusinessArea::query()->create(['customer_id' => $customer->id, 'name' => 'Drift']);
        $person = $this->person($customer, $area);
        $other = $this->person($customer, $area);
        $world = compact('customer', 'area', 'person', 'other');

        $objective = $this->objective($world, 'Stabil drift');
        $kpi = $this->kpi($objective, 'Oppetid');
        $spare = $this->kpi($objective, 'Svartid');
        $measurement = $this->record($kpi, $person);

        return $world + compact('objective', 'kpi', 'spare', 'measurement');
    }

    private function record(Kpi $kpi, User $person): KpiMeasurement
    {
        return app(KpiMeasurementService::class)->record($kpi, $person, app(KpiPeriods::class)->fromKey(Kpi::FREQUENCY_MONTHLY, '2026-01'), '99', null);
    }

    /** @param array<string, mixed> $world */
    private function objective(array $world, string $title): Objective
    {
        return Objective::query()->create([
            'customer_id' => $world['customer']->id,
            'business_area_id' => $world['area']->id,
            'title' => $title,
            'owner_user_id' => $world['person']->id,
        ]);
    }

    private function kpi(Objective $objective, string $title): Kpi
    {
        return Kpi::query()->create([
            'customer_id' => $objective->customer_id,
            'objective_id' => $objective->id,
            'title' => $title,
            'unit' => Kpi::UNIT_PERCENT,
            'target_min' => '98',
            'frequency' => Kpi::FREQUENCY_MONTHLY,
            'reporting_grace_days' => 7,
        ]);
    }

    private function person(Customer $customer, BusinessArea $area): User
    {
        $role = CustomerRole::query()->create(['customer_id' => $customer->id, 'name' => 'Rolle '.Str::upper(Str::random(8)), 'is_active' => true]);
        $role->syncPermissions(CustomerPermissionCatalog::domains()[CustomerPermissionCatalog::DOMAIN_OBJECTIVE]);
        $role->syncBusinessAreas(false, [(int) $area->id]);

        $person = User::query()->create([
            'name' => 'Medarbeider '.Str::upper(Str::random(4)),
            'email' => 'maalhistorikk-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
        $person->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        return $person;
    }

    private function customer(): Customer
    {
        $language = Language::query()->firstOrCreate(['code' => 'no'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk']);
        $nationality = Nationality::query()->firstOrCreate(['code' => 'NO'], ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO']);

        $customer = Customer::query()->create([
            'name' => 'Målhistorikk AS',
            'slug' => 'maalhistorikk-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        app(ModuleEntitlementService::class)->activatePackage($customer, 'governance');

        return $customer;
    }
}
