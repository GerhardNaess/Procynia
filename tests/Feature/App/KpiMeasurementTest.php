<?php

namespace Tests\Feature\App;

use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\CustomerRole;
use App\Models\Kpi;
use App\Models\KpiMeasurement;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\Objective;
use App\Models\User;
use App\Services\Objectives\KpiLifecycleService;
use App\Services\Objectives\KpiMeasurementResolver;
use App\Services\Objectives\KpiMeasurementSchedule;
use App\Services\Objectives\KpiPeriod;
use App\Services\Objectives\KpiPeriods;
use App\Services\Objectives\KpiSchedule;
use App\Services\Objectives\ObjectiveLifecycleService;
use App\Support\CustomerPermissionCatalog;
use App\Support\Objectives\KpiPeriodFormatter;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * KPI measurements: immutable history, correction by a new row, withdrawal once with a reason, the
 * current measurement per period and per KPI, today's status against today's target and the
 * history's against the snapshot, the reporting schedule («Måling mangler»), objective.measure,
 * and deletion once history exists.
 *
 * Dates are fixed with travelTo(): the schedule is calendar arithmetic, and a test that depends on
 * the day it runs is not a test.
 */
class KpiMeasurementTest extends TestCase
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

    // ---------------------------------------------------------------------
    // The measurement row
    // ---------------------------------------------------------------------

    public function test_a_measurement_snapshots_the_kpis_target_and_ignores_target_fields_from_the_form(): void
    {
        $this->at('2026-10-10');
        ['customer' => $customer, 'kpi' => $kpi, 'measurer' => $user] = $this->scenario();

        $this->actingAs($user)->post($this->measureUrl($kpi), [
            'period' => '2026-09',
            'value' => '98,7',
            'target_min' => '1',
            'target_max' => '2',
            'tolerance' => '50',
            'recorded_by_user_id' => 999999,
        ])->assertRedirect($this->kpiUrl($kpi))->assertSessionHas('success', 'Målingen er registrert.');

        $measurement = KpiMeasurement::query()->where('kpi_id', $kpi->id)->sole();

        $this->assertSame((int) $customer->id, (int) $measurement->customer_id);
        $this->assertSame('2026-09-01', $measurement->period_start->format('Y-m-d'));
        $this->assertSame('2026-09-30', $measurement->period_end->format('Y-m-d'));
        // Exact decimals, never a float.
        $this->assertSame('98.7000', $measurement->value);
        $this->assertSame('98.0000', $measurement->target_min);
        $this->assertNull($measurement->target_max);
        $this->assertNull($measurement->tolerance);
        $this->assertSame((int) $user->id, (int) $measurement->recorded_by_user_id);
        $this->assertSame('2026-10-10', $measurement->recorded_at->format('Y-m-d'));
        $this->assertNull($measurement->withdrawn_at);
        $this->assertNotContains('withdrawn_at', $measurement->getFillable());
    }

    public function test_values_are_exact_decimals_on_the_tolerance_edge(): void
    {
        $this->at('2026-10-10');
        ['kpi' => $kpi, 'measurer' => $user] = $this->scenario(['target_min' => '99.5', 'tolerance' => '1']);

        $this->record($kpi, $user, '2026-07', '98.5');
        $this->record($kpi, $user, '2026-08', '98.4999');
        $this->record($kpi, $user, '2026-09', '99.5');

        $rows = collect($this->kpiProps($kpi, $user)['measurements'])->keyBy('period_label');
        $this->assertSame('attention', $rows['Juli 2026']['result']);
        $this->assertSame('off_target', $rows['August 2026']['result']);
        $this->assertSame('on_target', $rows['September 2026']['result']);
        $this->assertSame('98.4999', KpiMeasurement::query()->where('period_end', '2026-08-31')->value('value'));

        $this->actingAs($user)->post($this->measureUrl($kpi), ['period' => '2026-06', 'value' => '1.23456'])
            ->assertSessionHasErrors(['value' => 'Verdi må være et tall med høyst fire desimaler.']);
        $this->actingAs($user)->post($this->measureUrl($kpi), ['period' => '2026-06', 'value' => ''])
            ->assertSessionHasErrors(['value' => 'Verdi må fylles ut.']);
    }

    public function test_a_measurement_is_immutable_and_never_deleted(): void
    {
        $this->at('2026-10-10');
        ['kpi' => $kpi, 'measurer' => $user] = $this->scenario();
        $this->record($kpi, $user, '2026-09', '98.7');
        $measurement = KpiMeasurement::query()->where('kpi_id', $kpi->id)->sole();

        foreach ([
            'value' => '99',
            'period_start' => '2026-08-01',
            'target_min' => '10',
            'comment' => 'Endret',
            'recorded_by_user_id' => null,
        ] as $column => $value) {
            try {
                $fresh = $measurement->fresh();
                $fresh->forceFill([$column => $value])->save();
                $this->fail("Changing {$column} must be refused.");
            } catch (LogicException) {
            }
        }

        // Withdrawing is the only change, and it needs a reason.
        try {
            $measurement->fresh()->forceFill(['withdrawn_at' => now()])->save();
            $this->fail('A withdrawal without a reason must be refused.');
        } catch (LogicException) {
        }

        try {
            $measurement->fresh()->delete();
            $this->fail('A measurement must never be deleted.');
        } catch (LogicException) {
        }

        $withdrawn = $measurement->fresh();
        $withdrawn->withdraw($user, 'Feil KPI');

        // After a withdrawal the row is final — the withdrawal itself included.
        foreach (['withdrawal_reason' => 'Ny grunn', 'value' => '1'] as $column => $value) {
            try {
                $withdrawn->fresh()->forceFill([$column => $value])->save();
                $this->fail("Changing {$column} after withdrawal must be refused.");
            } catch (LogicException) {
            }
        }

        $this->assertSame('98.7000', $measurement->fresh()->value);
        $this->assertSame('Feil KPI', $measurement->fresh()->withdrawal_reason);
    }

    public function test_the_model_and_the_database_keep_the_customer_boundary_and_the_row_rules(): void
    {
        $this->at('2026-10-10');
        ['customer' => $customer, 'kpi' => $kpi, 'measurer' => $user] = $this->scenario();
        ['kpi' => $theirs] = $this->scenario();

        try {
            KpiMeasurement::query()->create($this->rawRow($customer->id, $theirs->id) + ['recorded_by_user_id' => $user->id]);
            $this->fail('A measurement under another customer\'s KPI must be refused by the model.');
        } catch (DomainException) {
        }

        $broken = [
            'other customer' => ['kpi_id' => $theirs->id],
            'period backwards' => ['period_start' => '2026-09-30', 'period_end' => '2026-09-01'],
            'no target bound' => ['target_min' => null, 'target_max' => null],
            'negative tolerance' => ['tolerance' => '-1'],
            'withdrawn without reason' => ['withdrawn_at' => now()],
            'reason without withdrawal' => ['withdrawal_reason' => 'Feil'],
            'blank reason' => ['withdrawn_at' => now(), 'withdrawal_reason' => '   '],
        ];

        foreach ($broken as $label => $override) {
            DB::statement('SAVEPOINT broken_row');

            try {
                DB::table('kpi_measurements')->insert($override + $this->rawRow($customer->id, $kpi->id));
                $this->fail("The database must refuse: {$label}.");
            } catch (QueryException) {
                DB::statement('ROLLBACK TO SAVEPOINT broken_row');
            }
        }

        $this->assertSame(0, KpiMeasurement::query()->whereIn('kpi_id', [$kpi->id, $theirs->id])->count());
    }

    // ---------------------------------------------------------------------
    // Periods
    // ---------------------------------------------------------------------

    public function test_only_ended_calendar_periods_are_registered_and_the_period_is_normalized(): void
    {
        $this->at('2026-10-10');
        ['kpi' => $kpi, 'measurer' => $user] = $this->scenario();
        $url = $this->measureUrl($kpi);

        // The running month and any later one have no result yet.
        $this->actingAs($user)->post($url, ['period' => '2026-10', 'value' => '99'])
            ->assertSessionHasErrors(['period' => 'Perioden er ikke avsluttet ennå. Resultatet kan registreres når perioden er slutt.']);
        $this->actingAs($user)->post($url, ['period' => '2027-01', 'value' => '99'])->assertSessionHasErrors('period');
        // Free dates and malformed keys are not periods.
        foreach (['2026-13', '2026-9', '2026-09-01', '', 'september'] as $key) {
            $this->actingAs($user)->post($url, ['period' => $key, 'value' => '99'])
                ->assertSessionHasErrors(['period' => 'Velg en gyldig periode.']);
        }
        $this->actingAs($user)->post($url, ['period_start' => '2026-09-03', 'period_end' => '2026-09-20', 'value' => '99'])
            ->assertSessionHasErrors('period');

        $this->assertSame(0, KpiMeasurement::query()->where('kpi_id', $kpi->id)->count());

        // Weekly, quarterly and yearly use their own calendar keys.
        foreach ([
            ['weekly', '2026-W40', '2026-09-28', '2026-10-04', '2026-W41'],
            ['quarterly', '2026-Q3', '2026-07-01', '2026-09-30', '2026-Q4'],
            ['yearly', '2025', '2025-01-01', '2025-12-31', '2026'],
        ] as [$frequency, $key, $start, $end, $running]) {
            $other = $this->kpi($kpi->objective, ['title' => $frequency, 'frequency' => $frequency]);

            $this->actingAs($user)->post($this->measureUrl($other), ['period' => $running, 'value' => '99'])->assertSessionHasErrors('period');
            $this->actingAs($user)->post($this->measureUrl($other), ['period' => $key, 'value' => '99'])->assertSessionHasNoErrors();

            $row = KpiMeasurement::query()->where('kpi_id', $other->id)->sole();
            $this->assertSame([$start, $end], [$row->period_start->format('Y-m-d'), $row->period_end->format('Y-m-d')]);
        }
    }

    public function test_a_kpi_without_frequency_is_measured_on_a_day_up_to_today(): void
    {
        $this->at('2026-10-10');
        ['kpi' => $kpi, 'measurer' => $user] = $this->scenario(['frequency' => null]);
        $url = $this->measureUrl($kpi);

        $this->actingAs($user)->post($url, ['measured_on' => '2026-10-11', 'value' => '99'])
            ->assertSessionHasErrors(['measured_on' => 'Datoen kan ikke være i fremtiden.']);
        $this->actingAs($user)->post($url, ['measured_on' => '2026-02-30', 'value' => '99'])
            ->assertSessionHasErrors(['measured_on' => 'Velg en gyldig dato.']);
        // A period key means nothing without a frequency.
        $this->actingAs($user)->post($url, ['period' => '2026-09', 'value' => '99'])->assertSessionHasErrors('measured_on');

        $this->actingAs($user)->post($url, ['measured_on' => '2026-10-10', 'value' => '99'])->assertSessionHasNoErrors();

        $row = KpiMeasurement::query()->where('kpi_id', $kpi->id)->sole();
        $this->assertSame('2026-10-10', $row->period_start->format('Y-m-d'));
        $this->assertSame('2026-10-10', $row->period_end->format('Y-m-d'));

        // Same day again is a correction.
        $this->actingAs($user)->post($url, ['measured_on' => '2026-10-10', 'value' => '98'])->assertSessionHasErrors('comment');

        $props = $this->kpiProps($kpi, $user);
        $this->assertSame('date', $props['measurement_form']['mode']);
        $this->assertSame('2026-10-10', $props['measurement_form']['today']);
        $this->assertSame(['2026-10-10' => "99\u{00A0}%"], $props['measurement_form']['existing_by_date']);
        $this->assertSame('10. oktober 2026', $props['measurements'][0]['period_label']);
        $this->assertFalse($props['kpi']['schedule']['measurement_missing']);
    }

    public function test_period_labels_read_the_way_people_say_them(): void
    {
        $periods = new KpiPeriods;
        $formatter = new KpiPeriodFormatter($periods, 'no');
        $english = new KpiPeriodFormatter($periods, 'en');

        $this->assertSame('Uke 40, 2026', $formatter->label($periods->fromKey('weekly', '2026-W40')));
        $this->assertSame('Uke 1, 2026', $formatter->label($periods->fromKey('weekly', '2026-W01')));
        $this->assertSame('September 2026', $formatter->label($periods->fromKey('monthly', '2026-09')));
        $this->assertSame('3. kvartal 2026', $formatter->label($periods->fromKey('quarterly', '2026-Q3')));
        $this->assertSame('2026', $formatter->label($periods->fromKey('yearly', '2026')));
        $this->assertSame('5. oktober 2026', $formatter->label($periods->day(CarbonImmutable::parse('2026-10-05'))));
        $this->assertSame('Week 40, 2026', $english->label($periods->fromKey('weekly', '2026-W40')));
        $this->assertSame('September 2026', $english->label($periods->fromKey('monthly', '2026-09')));
        $this->assertSame('Q3 2026', $english->label($periods->fromKey('quarterly', '2026-Q3')));
        $this->assertSame('5 October 2026', $english->label($periods->day(CarbonImmutable::parse('2026-10-05'))));
    }

    public function test_a_closed_objective_or_a_retired_kpi_takes_no_measurement(): void
    {
        $this->at('2026-10-10');
        ['kpi' => $kpi, 'objective' => $objective, 'measurer' => $user, 'editor' => $editor] = $this->scenario();
        $this->record($kpi, $user, '2026-08', '99');
        $measurement = KpiMeasurement::query()->where('kpi_id', $kpi->id)->sole();

        app(KpiLifecycleService::class)->retire($kpi, $editor, null);

        $this->actingAs($user)->post($this->measureUrl($kpi), ['period' => '2026-09', 'value' => '99'])
            ->assertSessionHas('error', 'Målinger registreres bare for en aktiv KPI under et aktivt mål.');
        $this->actingAs($user)->post($this->withdrawUrl($measurement), ['reason' => 'Feil'])
            ->assertSessionHas('error', 'Målinger registreres bare for en aktiv KPI under et aktivt mål.');
        $this->assertNull($this->kpiProps($kpi, $user)['measurement_form']);
        $this->assertFalse($this->kpiProps($kpi, $user)['permissions']['can_measure']);
        $this->assertFalse($this->kpiProps($kpi, $user)['measurements'][0]['can_withdraw']);

        app(KpiLifecycleService::class)->reopen($kpi->fresh(), $editor, 'Tilbake');
        app(ObjectiveLifecycleService::class)->close($objective, $editor, Objective::STATUS_ACHIEVED, null);

        $this->actingAs($user)->post($this->measureUrl($kpi), ['period' => '2026-09', 'value' => '99'])
            ->assertSessionHas('error', 'Målinger registreres bare for en aktiv KPI under et aktivt mål.');
        $this->assertSame(1, KpiMeasurement::query()->where('kpi_id', $kpi->id)->count());
        $this->assertNull($measurement->fresh()->withdrawn_at);
    }

    // ---------------------------------------------------------------------
    // Correction
    // ---------------------------------------------------------------------

    public function test_a_correction_is_a_new_row_with_a_comment_and_supersedes_the_old_one(): void
    {
        $this->at('2026-10-10');
        ['kpi' => $kpi, 'measurer' => $user] = $this->scenario();
        $this->record($kpi, $user, '2026-09', '98.7');

        // Without a comment the correction is refused, and nothing is written.
        $this->actingAs($user)->post($this->measureUrl($kpi), ['period' => '2026-09', 'value' => '99.1', 'comment' => '  '])
            ->assertSessionHasErrors(['comment' => 'Perioden har allerede en verdi. Forklar hvorfor den korrigeres.']);
        $this->assertSame(1, KpiMeasurement::query()->where('kpi_id', $kpi->id)->count());

        $this->record($kpi, $user, '2026-09', '99.1', 'Feil tall fra rapporten');
        // Corrections in the same second are ordered by id.
        $this->record($kpi, $user, '2026-09', '99.2', 'Revidert rapport');

        $rows = KpiMeasurement::query()->where('kpi_id', $kpi->id)->orderBy('id')->get();
        $this->assertSame(['98.7000', '99.1000', '99.2000'], $rows->pluck('value')->all());

        $props = $this->kpiProps($kpi, $user);
        $this->assertSame(['current', 'superseded', 'superseded'], array_column($props['measurements'], 'state'));
        $this->assertSame(["99,2\u{00A0}%", "99,1\u{00A0}%", "98,7\u{00A0}%"], array_column($props['measurements'], 'value_display'));
        $this->assertSame('Revidert rapport', $props['measurements'][0]['comment']);
        $this->assertSame("99,2\u{00A0}%", $props['kpi']['latest_value_display']);
        $this->assertSame('September 2026', $props['kpi']['latest_period_label']);

        // The form says the period has a value, so the page can call the next one a correction.
        $september = collect($props['measurement_form']['period_options'])->firstWhere('key', '2026-09');
        $this->assertSame("99,2\u{00A0}%", $september['current_value_display']);
        $this->assertNull(collect($props['measurement_form']['period_options'])->firstWhere('key', '2026-08')['current_value_display']);
    }

    public function test_the_latest_measurement_is_the_period_that_ends_last_not_the_last_registered(): void
    {
        $this->at('2026-10-10');
        ['kpi' => $kpi, 'measurer' => $user] = $this->scenario();
        $this->record($kpi, $user, '2026-09', '98');
        $this->record($kpi, $user, '2026-07', '50');

        $resolver = app(KpiMeasurementResolver::class);
        $this->assertSame('98.0000', $resolver->latest($kpi->measurements()->get())->value);
        $this->assertSame('98.0000', $resolver->latestForKpis([$kpi])[(int) $kpi->id]->value);

        $other = $this->kpi($kpi->objective, ['title' => 'Uten målinger']);
        $this->assertArrayNotHasKey((int) $other->id, $resolver->latestForKpis([$kpi, $other]));
    }

    // ---------------------------------------------------------------------
    // Withdrawal
    // ---------------------------------------------------------------------

    public function test_withdrawing_needs_a_reason_happens_once_and_restores_the_previous_value(): void
    {
        $this->at('2026-10-10');
        ['kpi' => $kpi, 'measurer' => $user] = $this->scenario();
        $this->record($kpi, $user, '2026-09', '98.7');
        $this->record($kpi, $user, '2026-09', '99.9', 'Korrigert');
        [$first, $correction] = KpiMeasurement::query()->where('kpi_id', $kpi->id)->orderBy('id')->get()->all();

        $this->actingAs($user)->post($this->withdrawUrl($correction), ['reason' => ' '])
            ->assertSessionHasErrors(['reason' => 'Begrunnelse må fylles ut.']);
        $this->assertNull($correction->fresh()->withdrawn_at);

        $this->actingAs($user)->post($this->withdrawUrl($correction), ['reason' => 'Feil periode'])
            ->assertSessionHas('success', 'Målingen er trukket tilbake.');

        $correction->refresh();
        $this->assertNotNull($correction->withdrawn_at);
        $this->assertSame((int) $user->id, (int) $correction->withdrawn_by_user_id);
        $this->assertSame('Feil periode', $correction->withdrawal_reason);
        $this->assertSame('99.9000', $correction->value);

        // Once only.
        $this->actingAs($user)->post($this->withdrawUrl($correction), ['reason' => 'Igjen'])
            ->assertSessionHasErrors(['reason' => 'Målingen er allerede trukket tilbake. Last siden på nytt.']);
        $this->assertSame('Feil periode', $correction->fresh()->withdrawal_reason);

        // The earlier measurement counts again.
        $props = $this->kpiProps($kpi, $user);
        $this->assertSame(['withdrawn', 'current'], array_column($props['measurements'], 'state'));
        $this->assertFalse($props['measurements'][0]['can_withdraw']);
        $this->assertTrue($props['measurements'][1]['can_withdraw']);
        $this->assertSame("98,7\u{00A0}%", $props['kpi']['latest_value_display']);
        $this->assertSame('Feil periode', $props['measurements'][0]['withdrawal_reason']);

        // With nothing left, the period has no measurement.
        $this->actingAs($user)->post($this->withdrawUrl($first), ['reason' => 'Feil KPI'])->assertSessionHasNoErrors();
        $props = $this->kpiProps($kpi, $user);
        $this->assertSame(['withdrawn', 'withdrawn'], array_column($props['measurements'], 'state'));
        $this->assertNull($props['kpi']['latest_value_display']);
        $this->assertSame('not_measured', $props['kpi']['result']);

        // A withdrawn-only period is not a correction: no comment needed.
        $this->record($kpi, $user, '2026-09', '97');
        $this->assertSame('current', $this->kpiProps($kpi, $user)['measurements'][0]['state']);
    }

    public function test_a_measurement_is_only_found_under_its_own_kpi(): void
    {
        $this->at('2026-10-10');
        ['kpi' => $kpi, 'measurer' => $user] = $this->scenario();
        $other = $this->kpi($kpi->objective, ['title' => 'Annen']);
        $this->record($kpi, $user, '2026-09', '98');
        $measurement = KpiMeasurement::query()->where('kpi_id', $kpi->id)->sole();

        $this->actingAs($user)
            ->post("/app/objectives/{$other->objective_id}/kpis/{$other->id}/measurements/{$measurement->id}/withdraw", ['reason' => 'x'])
            ->assertNotFound();
        $this->assertNull($measurement->fresh()->withdrawn_at);
    }

    // ---------------------------------------------------------------------
    // Today's status and the history's status
    // ---------------------------------------------------------------------

    public function test_today_judges_against_todays_target_and_the_history_against_its_snapshot(): void
    {
        $this->at('2026-10-10');
        ['kpi' => $kpi, 'measurer' => $user, 'editor' => $editor] = $this->scenario(['target_min' => '98', 'tolerance' => null]);
        $this->record($kpi, $user, '2026-09', '98.7');

        $props = $this->kpiProps($kpi, $user);
        $this->assertSame('on_target', $props['kpi']['result']);
        $this->assertSame('on_target', $props['measurements'][0]['result']);

        // The target is tightened later; the unit is locked, the target is not.
        $this->actingAs($editor)->patch($this->kpiUrl($kpi), $this->payload(['target_min' => '99,5']))->assertSessionHasNoErrors();
        $this->assertSame('99.5000', $kpi->fresh()->target_min);

        $props = $this->kpiProps($kpi, $user);
        $this->assertSame('off_target', $props['kpi']['result']);
        $this->assertSame("98,7\u{00A0}%", $props['kpi']['latest_value_display']);
        $this->assertSame('on_target', $props['measurements'][0]['result']);
        $this->assertSame("≥\u{00A0}98\u{00A0}%", $props['measurements'][0]['target_display']);
        $this->assertSame("≥\u{00A0}99,5\u{00A0}%", $props['kpi']['target_display']);

        // The same on the objective's KPI list.
        $row = $this->objectiveProps($kpi->objective, $user)['kpis'][0];
        $this->assertSame('off_target', $row['result']);
        $this->assertSame("98,7\u{00A0}%", $row['latest_value_display']);

        // A new measurement snapshots the new target.
        $this->record($kpi, $user, '2026-08', '99');
        $this->assertSame('99.5000', KpiMeasurement::query()->where('period_end', '2026-08-31')->value('target_min'));
    }

    public function test_the_unit_is_locked_after_the_first_measurement_withdrawn_or_not(): void
    {
        $this->at('2026-10-10');
        ['kpi' => $kpi, 'measurer' => $user, 'editor' => $editor] = $this->scenario(['unit' => 'count', 'unit_label' => 'saker', 'target_min' => null, 'target_max' => '3']);
        $payload = fn (array $override): array => $this->payload($override + ['unit' => 'count', 'unit_label' => 'saker', 'target_min' => '', 'target_max' => '3']);

        // Before any measurement the unit may change.
        $this->actingAs($editor)->patch($this->kpiUrl($kpi), $payload(['unit_label' => 'hendelser']))->assertSessionHasNoErrors();
        $this->assertFalse($this->kpiProps($kpi, $user)['kpi']['unit_locked']);

        $this->record($kpi, $user, '2026-09', '2');
        KpiMeasurement::query()->where('kpi_id', $kpi->id)->sole()->withdraw($user, 'Feil');

        $locked = 'Enheten kan ikke endres etter at KPI-en har fått målinger. Da ville historikken skifte betydning.';
        $this->actingAs($editor)->patch($this->kpiUrl($kpi), $payload(['unit_label' => 'saker']))
            ->assertSessionHasErrors(['unit_label' => $locked]);
        $this->actingAs($editor)->patch($this->kpiUrl($kpi), $payload(['unit_label' => 'hendelser', 'unit' => 'number']))
            ->assertSessionHasErrors(['unit' => $locked]);
        $this->actingAs($editor)->patch($this->kpiUrl($kpi), $payload(['unit' => 'currency', 'currency_code' => 'NOK', 'unit_label' => '']))
            ->assertSessionHasErrors(['unit', 'currency_code', 'unit_label']);
        $this->assertSame('hendelser', $kpi->fresh()->unit_label);

        // Target, tolerance, title and frequency stay editable.
        $this->actingAs($editor)->patch($this->kpiUrl($kpi), $payload(['unit_label' => 'hendelser', 'target_max' => '5', 'tolerance' => '1', 'title' => 'Nytt navn', 'frequency' => 'quarterly']))
            ->assertSessionHasNoErrors();
        $this->assertSame('5.0000', $kpi->fresh()->target_max);
        $this->assertTrue($this->kpiProps($kpi, $user)['kpi']['unit_locked']);

        // The model refuses the same, past the form.
        $this->expectException(DomainException::class);
        $kpi->fresh()->forceFill(['unit_label' => 'brukere'])->save();
    }

    // ---------------------------------------------------------------------
    // Schedule and «Måling mangler»
    // ---------------------------------------------------------------------

    public function test_the_first_expected_period_is_the_one_the_kpi_was_created_in_and_the_deadline_day_is_not_missing(): void
    {
        $this->at('2026-09-15');
        ['kpi' => $kpi] = $this->scenario(['reporting_grace_days' => 7]);

        // September ends on the 30th; with 7 grace days it is due by 7 October.
        $schedule = $this->schedule($kpi, '2026-10-07');
        $this->assertFalse($schedule->measurementMissing());
        $this->assertSame(['2026-09'], $this->keys($schedule->pending));
        $this->assertSame('2026-09', $schedule->suggested()->key);
        $this->assertSame('2026-10', $schedule->upcoming->key);

        $schedule = $this->schedule($kpi, '2026-10-08');
        $this->assertTrue($schedule->measurementMissing());
        $this->assertSame('2026-09', $schedule->oldestMissing()->key);

        // August, before the KPI existed, is never expected.
        $this->assertSame(['2026-09'], $this->keys($this->schedule($kpi, '2026-10-20')->missing));
    }

    public function test_with_zero_grace_days_the_day_after_the_period_ends_is_missing(): void
    {
        $this->at('2026-09-15');
        ['kpi' => $kpi] = $this->scenario(['reporting_grace_days' => 0]);

        $this->assertFalse($this->schedule($kpi, '2026-09-30')->measurementMissing());
        $this->assertSame([], $this->schedule($kpi, '2026-09-30')->pending);
        $this->assertSame(['2026-09'], $this->keys($this->schedule($kpi, '2026-10-01')->missing));
    }

    public function test_weekly_quarterly_and_yearly_kpis_follow_their_own_calendar(): void
    {
        $this->at('2026-09-15');
        ['objective' => $objective] = $this->scenario();
        $weekly = $this->kpi($objective, ['frequency' => 'weekly', 'reporting_grace_days' => 2]);
        $quarterly = $this->kpi($objective, ['frequency' => 'quarterly', 'reporting_grace_days' => 10]);
        $yearly = $this->kpi($objective, ['frequency' => 'yearly', 'reporting_grace_days' => 30]);

        // 15.09.2026 is in week 38 (14–20 September); due 22 September.
        $this->assertSame([], $this->schedule($weekly, '2026-09-22')->missing);
        $this->assertSame(['2026-W38'], $this->keys($this->schedule($weekly, '2026-09-23')->missing));
        $this->assertSame(['2026-W38', '2026-W39', '2026-W40'], $this->keys($this->schedule($weekly, '2026-10-08')->missing));

        $this->assertSame(['2026-Q3'], $this->keys($this->schedule($quarterly, '2026-10-10')->pending));
        $this->assertSame(['2026-Q3'], $this->keys($this->schedule($quarterly, '2026-10-11')->missing));

        $this->assertSame([], $this->schedule($yearly, '2027-01-30')->missing);
        $this->assertSame(['2026'], $this->keys($this->schedule($yearly, '2027-01-31')->missing));
    }

    public function test_several_missing_periods_name_the_oldest_and_a_measurement_fills_its_period(): void
    {
        $this->at('2026-06-10');
        ['kpi' => $kpi, 'measurer' => $user] = $this->scenario();

        $schedule = $this->schedule($kpi, '2026-10-20');
        $this->assertSame(['2026-06', '2026-07', '2026-08', '2026-09'], $this->keys($schedule->missing));
        $this->assertSame('2026-06', $schedule->oldestMissing()->key);

        $this->at('2026-10-20');
        $this->record($kpi, $user, '2026-06', '99');
        $this->record($kpi, $user, '2026-08', '99');
        $this->assertSame(['2026-07', '2026-09'], $this->keys($this->schedule($kpi, '2026-10-20')->missing));

        // A correction keeps the period filled; withdrawing every row empties it again.
        $this->record($kpi, $user, '2026-08', '98', 'Korrigert');
        $this->assertSame(['2026-07', '2026-09'], $this->keys($this->schedule($kpi, '2026-10-20')->missing));
        KpiMeasurement::query()->where('kpi_id', $kpi->id)->where('period_end', '2026-08-31')->get()
            ->each(fn (KpiMeasurement $measurement) => $measurement->withdraw($user, 'Feil'));
        $this->assertSame(['2026-07', '2026-08', '2026-09'], $this->keys($this->schedule($kpi, '2026-10-20')->missing));

        $props = $this->kpiProps($kpi, $user);
        $this->assertTrue($props['kpi']['schedule']['measurement_missing']);
        $this->assertSame('juli 2026', $props['kpi']['schedule']['missing_label']);
        $this->assertSame(3, $props['kpi']['schedule']['missing_count']);
        $this->assertSame('3 perioder mangler måling.', $props['kpi']['schedule']['missing_count_display']);
        $this->assertSame('2026-07', $props['measurement_form']['suggested_key']);
    }

    public function test_periods_ending_while_the_kpi_was_retired_are_not_expected_and_a_reopening_resumes(): void
    {
        $this->at('2026-05-10');
        ['kpi' => $kpi, 'editor' => $editor] = $this->scenario();
        $lifecycle = app(KpiLifecycleService::class);

        // Retired 20 June, before June ended: June is not expected; May is.
        $this->at('2026-06-20');
        $lifecycle->retire($kpi, $editor, null);

        $this->at('2026-09-12');
        $this->assertSame(['2026-05'], $this->keys($this->schedule($kpi->fresh(), '2026-09-12')->missing));
        // Retired now: nothing is «missing» for the page, though the history knows May.
        $this->assertFalse($this->schedule($kpi->fresh(), '2026-09-12')->measurementMissing());
        $this->assertNull($this->schedule($kpi->fresh(), '2026-09-12')->upcoming);

        // Reopened 12 September: September is expected (active on the 30th), July and August not.
        $lifecycle->reopen($kpi->fresh(), $editor, 'Måles igjen');
        $schedule = $this->schedule($kpi->fresh(), '2026-10-20');
        $this->assertSame(['2026-05', '2026-09'], $this->keys($schedule->missing));
        $this->assertTrue($schedule->measurementMissing());
    }

    public function test_a_closed_objective_has_no_missing_measurement_and_its_closed_months_are_not_expected(): void
    {
        $this->at('2026-07-05');
        ['kpi' => $kpi, 'objective' => $objective, 'editor' => $editor] = $this->scenario();
        $lifecycle = app(ObjectiveLifecycleService::class);

        $this->at('2026-08-15');
        $lifecycle->close($objective, $editor, Objective::STATUS_CANCELLED, null);
        $this->assertFalse($this->schedule($kpi->fresh(), '2026-08-20')->measurementMissing());

        $this->at('2026-09-03');
        $lifecycle->reopen($objective->fresh(), $editor, 'Videre');
        $this->assertSame(['2026-07', '2026-09'], $this->keys($this->schedule($kpi->fresh(), '2026-10-20')->missing));
    }

    public function test_a_change_of_frequency_starts_a_new_rhythm_after_the_last_old_period(): void
    {
        $this->at('2026-01-05');
        ['kpi' => $kpi, 'measurer' => $user] = $this->scenario();

        $this->at('2026-08-05');
        foreach (['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07'] as $month) {
            $this->record($kpi, $user, $month, '99');
        }

        $kpi->forceFill(['frequency' => Kpi::FREQUENCY_QUARTERLY])->save();

        // Q1 and Q2 were measured month by month; only Q3, after July, is the new rhythm.
        $this->assertSame([], $this->schedule($kpi->fresh(), '2026-08-05')->missing);
        $this->assertSame(['2026-Q3'], $this->keys($this->schedule($kpi->fresh(), '2026-10-20')->missing));
    }

    public function test_a_kpi_without_frequency_has_no_schedule(): void
    {
        $this->at('2026-01-05');
        ['kpi' => $kpi] = $this->scenario(['frequency' => null]);

        $schedule = $this->schedule($kpi, '2026-10-20');
        $this->assertSame([], $schedule->missing);
        $this->assertNull($schedule->suggested());
        $this->assertFalse($schedule->measurementMissing());
    }

    // ---------------------------------------------------------------------
    // Access
    // ---------------------------------------------------------------------

    public function test_measure_without_edit_registers_and_withdraws_but_edits_nothing(): void
    {
        $this->at('2026-10-10');
        ['customer' => $customer, 'kpi' => $kpi, 'area' => $area] = $this->scenario();
        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_MEASURE], [$area]);

        $this->record($kpi, $user, '2026-09', '99');
        $this->actingAs($user)->post($this->withdrawUrl(KpiMeasurement::query()->where('kpi_id', $kpi->id)->sole()), ['reason' => 'Feil'])
            ->assertSessionHasNoErrors();

        $props = $this->kpiProps($kpi, $user);
        $this->assertTrue($props['permissions']['can_measure']);
        $this->assertFalse($props['permissions']['can_edit']);
        $this->assertNotNull($props['measurement_form']);
        $this->actingAs($user)->patch($this->kpiUrl($kpi), $this->payload())->assertForbidden();
    }

    public function test_edit_without_measure_cannot_register_or_withdraw(): void
    {
        $this->at('2026-10-10');
        ['kpi' => $kpi, 'measurer' => $measurer, 'editor' => $editor] = $this->scenario();
        $this->record($kpi, $measurer, '2026-09', '99');
        $measurement = KpiMeasurement::query()->where('kpi_id', $kpi->id)->sole();

        $this->actingAs($editor)->post($this->measureUrl($kpi), ['period' => '2026-08', 'value' => '99'])->assertForbidden();
        $this->actingAs($editor)->post($this->withdrawUrl($measurement), ['reason' => 'Feil'])->assertForbidden();

        $props = $this->kpiProps($kpi, $editor);
        $this->assertFalse($props['permissions']['can_measure']);
        $this->assertNull($props['measurement_form']);
        $this->assertFalse($props['measurements'][0]['can_withdraw']);
        $this->assertSame(1, KpiMeasurement::query()->where('kpi_id', $kpi->id)->count());
        $this->assertNull($measurement->fresh()->withdrawn_at);
    }

    public function test_measure_counts_only_in_its_own_area_and_alle_reaches_every_area(): void
    {
        $this->at('2026-10-10');
        ['customer' => $customer, 'kpi' => $kpi, 'area' => $area] = $this->scenario();
        $other = $this->area($customer, 'Økonomi');

        // Reads the objective's area, but measures elsewhere: forbidden.
        $elsewhere = $this->member($customer);
        $this->grant($customer, $elsewhere, [CustomerPermissionCatalog::OBJECTIVE_VIEW], [$area]);
        $this->grant($customer, $elsewhere, [CustomerPermissionCatalog::OBJECTIVE_MEASURE], [$other]);
        $this->actingAs($elsewhere)->post($this->measureUrl($kpi), ['period' => '2026-09', 'value' => '99'])->assertForbidden();

        // Measures only elsewhere and cannot see the objective: absent.
        $outsider = $this->member($customer);
        $this->grant($customer, $outsider, [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_MEASURE], [$other]);
        $this->actingAs($outsider)->post($this->measureUrl($kpi), ['period' => '2026-09', 'value' => '99'])->assertNotFound();

        $everywhere = $this->member($customer);
        $this->grantAll($customer, $everywhere, [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_MEASURE]);
        $this->record($kpi, $everywhere, '2026-09', '99');

        $this->assertSame(1, KpiMeasurement::query()->where('kpi_id', $kpi->id)->count());
    }

    public function test_system_owner_without_a_role_and_another_customer_cannot_measure(): void
    {
        $this->at('2026-10-10');
        ['customer' => $customer, 'owner' => $owner, 'kpi' => $kpi, 'area' => $area] = $this->scenario();

        $this->actingAs($owner)->post($this->measureUrl($kpi), ['period' => '2026-09', 'value' => '99'])->assertNotFound();
        $this->grant($customer, $owner, [CustomerPermissionCatalog::OBJECTIVE_VIEW], [$area]);
        $this->actingAs($owner)->post($this->measureUrl($kpi), ['period' => '2026-09', 'value' => '99'])->assertForbidden();
        $this->assertFalse($this->kpiProps($kpi, $owner)['permissions']['can_measure']);

        ['measurer' => $stranger] = $this->scenario();
        $this->actingAs($stranger)->post($this->measureUrl($kpi), ['period' => '2026-09', 'value' => '99'])->assertNotFound();

        $this->assertSame(0, KpiMeasurement::query()->where('kpi_id', $kpi->id)->count());
    }

    // ---------------------------------------------------------------------
    // Deletion
    // ---------------------------------------------------------------------

    public function test_a_kpi_with_any_measurement_history_is_retired_not_deleted(): void
    {
        $this->at('2026-10-10');
        ['kpi' => $kpi, 'objective' => $objective, 'measurer' => $measurer, 'area' => $area, 'customer' => $customer] = $this->scenario();
        $deleter = $this->member($customer);
        $this->grant($customer, $deleter, [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_DELETE], [$area]);

        $empty = $this->kpi($objective, ['title' => 'Tom']);
        $this->assertTrue($empty->isDeletable());
        $this->assertTrue($this->kpiProps($empty, $deleter)['permissions']['can_delete']);
        $this->actingAs($deleter)->delete($this->kpiUrl($empty))->assertRedirect("/app/objectives/{$objective->id}");
        $this->assertNull($empty->fresh());

        $this->record($kpi, $measurer, '2026-09', '99');
        KpiMeasurement::query()->where('kpi_id', $kpi->id)->sole()->withdraw($measurer, 'Feil');

        // A withdrawn measurement is still history.
        $this->assertFalse($kpi->fresh()->isDeletable());
        $this->assertFalse($this->kpiProps($kpi, $deleter)['permissions']['can_delete']);
        $this->actingAs($deleter)->delete($this->kpiUrl($kpi))
            ->assertSessionHas('error', 'KPI-en kan ikke slettes. Avslutt den i stedet.');
        $this->assertNotNull($kpi->fresh());
    }

    public function test_an_objective_with_measurement_history_is_closed_not_deleted(): void
    {
        $this->at('2026-10-10');
        ['kpi' => $kpi, 'objective' => $objective, 'measurer' => $measurer, 'area' => $area, 'customer' => $customer] = $this->scenario();
        $deleter = $this->member($customer);
        $this->grant($customer, $deleter, [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_DELETE], [$area]);

        // KPIs without measurements do not stop a deletion.
        $this->assertTrue($objective->isDeletable());
        $this->assertTrue($this->objectiveProps($objective, $deleter)['permissions']['can_delete']);

        $this->record($kpi, $measurer, '2026-09', '99');
        $this->assertFalse($objective->fresh()->isDeletable());
        $this->assertFalse($this->objectiveProps($objective, $deleter)['permissions']['can_delete']);
        $this->actingAs($deleter)->delete("/app/objectives/{$objective->id}")
            ->assertSessionHas('error', 'Målet kan ikke slettes.');
        $this->assertNotNull($objective->fresh());

        // Database cleanup (customer or E2E) still removes everything, history included.
        Objective::query()->whereKey($objective->id)->delete();
        $this->assertSame(0, KpiMeasurement::query()->where('kpi_id', $kpi->id)->count());
    }

    // ---------------------------------------------------------------------
    // Pages
    // ---------------------------------------------------------------------

    public function test_the_kpi_page_shows_the_latest_result_the_schedule_and_the_form(): void
    {
        $this->at('2026-08-03');
        ['kpi' => $kpi, 'measurer' => $user] = $this->scenario(['target_min' => '99.5', 'tolerance' => '1', 'reporting_grace_days' => 5]);

        $this->at('2026-10-03');
        $this->record($kpi, $user, '2026-08', '99.7');

        $props = $this->kpiProps($kpi, $user);
        $this->assertSame('on_target', $props['kpi']['result']);
        $this->assertSame('August 2026', $props['kpi']['latest_period_label']);
        // September is due 5 October: pending, not missing.
        $this->assertFalse($props['kpi']['schedule']['measurement_missing']);
        $this->assertSame('September 2026', $props['kpi']['schedule']['pending_label']);
        $this->assertSame('5. oktober 2026', $props['kpi']['schedule']['pending_deadline']);
        $this->assertSame('Oktober 2026', $props['kpi']['schedule']['upcoming_label']);
        $this->assertSame('1. november 2026', $props['kpi']['schedule']['upcoming_from']);

        $form = $props['measurement_form'];
        $this->assertSame('period', $form['mode']);
        $this->assertSame('2026-09', $form['suggested_key']);
        // Newest ended period first; the running month is never offered.
        $this->assertSame(['key' => '2026-09', 'label' => 'September 2026', 'current_value_display' => null], $form['period_options'][0]);
        $this->assertNotContains('2026-10', array_column($form['period_options'], 'key'));
        $this->assertCount(24, $form['period_options']);

        $row = $props['measurements'][0];
        $this->assertSame('August 2026', $row['period_label']);
        $this->assertSame($user->name, $row['recorded_by_name']);
        $this->assertSame("≥\u{00A0}99,5\u{00A0}%", $row['target_display']);

        $this->at('2026-10-06');
        $props = $this->kpiProps($kpi, $user);
        $this->assertTrue($props['kpi']['schedule']['measurement_missing']);
        $this->assertSame('september 2026', $props['kpi']['schedule']['missing_label']);
    }

    public function test_the_objective_page_and_the_register_count_active_kpis_on_target(): void
    {
        $this->at('2026-10-10');
        ['kpi' => $backup, 'objective' => $objective, 'measurer' => $user] = $this->scenario(['title' => 'Vellykket backup', 'target_min' => '99.5', 'tolerance' => '1']);
        $deviations = $this->kpi($objective, ['title' => 'Kritiske avvik', 'unit' => 'count', 'target_min' => null, 'target_max' => '3', 'tolerance' => null]);
        $satisfaction = $this->kpi($objective, ['title' => 'Kundetilfredshet', 'target_min' => '90', 'tolerance' => null]);
        $retired = $this->kpi($objective, ['title' => 'Zz gammel', 'target_min' => '1']);

        $this->record($backup, $user, '2026-09', '98.7');
        $this->record($deviations, $user, '2026-09', '2');
        $this->record($retired, $user, '2026-09', '5');
        $retired->forceFill(['status' => Kpi::STATUS_RETIRED])->save();

        $props = $this->objectiveProps($objective, $user);
        $rows = collect($props['kpis'])->keyBy('title');
        $this->assertSame("98,7\u{00A0}%", $rows['Vellykket backup']['latest_value_display']);
        $this->assertSame('attention', $rows['Vellykket backup']['result']);
        $this->assertSame('2', $rows['Kritiske avvik']['latest_value_display']);
        $this->assertSame('on_target', $rows['Kritiske avvik']['result']);
        $this->assertNull($rows['Kundetilfredshet']['latest_value_display']);
        $this->assertSame('not_measured', $rows['Kundetilfredshet']['result']);
        // The retired KPI is on target but does not count: 1 of the 3 active ones.
        $this->assertSame('on_target', $rows['Zz gammel']['result']);
        $this->assertSame(['on_target' => 1, 'total' => 3], $props['kpi_indicator']);

        $this->record($satisfaction, $user, '2026-09', '91');
        $this->assertSame(['on_target' => 2, 'total' => 3], $this->objectiveProps($objective, $user)['kpi_indicator']);

        $register = $this->actingAs($user)->get('/app/objectives')->assertOk()->viewData('page')['props'];
        $this->assertSame(['on_target' => 2, 'total' => 3], collect($register['objectives'])->firstWhere('id', $objective->id)['kpi_indicator']);
    }

    public function test_the_register_loads_kpi_indicators_without_a_query_per_objective(): void
    {
        $this->at('2026-10-10');
        ['customer' => $customer, 'area' => $area, 'measurer' => $user, 'objective' => $first, 'kpi' => $kpi] = $this->scenario();
        $this->record($kpi, $user, '2026-09', '99');

        $queries = function () use ($user): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($user)->get('/app/objectives')->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $baseline = $queries();

        foreach (range(1, 4) as $i) {
            $objective = $this->objective($customer, $area, "Mål {$i}", $user);
            $other = $this->kpi($objective, ['title' => "KPI {$i}"]);
            $this->record($other, $user, '2026-09', '99');
        }

        $this->assertSame($baseline, $queries());
        $this->assertCount(5, collect($this->actingAs($user)->get('/app/objectives')->viewData('page')['props']['objectives'])->whereNotNull('kpi_indicator'));
        $this->assertSame(['on_target' => 1, 'total' => 1], collect($this->actingAs($user)->get('/app/objectives')->viewData('page')['props']['objectives'])->firstWhere('id', $first->id)['kpi_indicator']);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function at(string $date): void
    {
        $this->travelTo(CarbonImmutable::parse($date.' 10:00:00'));
    }

    private function schedule(Kpi $kpi, string $today): KpiSchedule
    {
        $kpi = $kpi->fresh();

        return app(KpiMeasurementSchedule::class)->for($kpi, $kpi->measurements()->get(), CarbonImmutable::parse($today));
    }

    /** @param list<KpiPeriod> $periods */
    private function keys(array $periods): array
    {
        return array_map(fn ($period): string => $period->key, $periods);
    }

    private function record(Kpi $kpi, User $user, string $period, string $value, ?string $comment = null): void
    {
        $this->actingAs($user)
            ->post($this->measureUrl($kpi), ['period' => $period, 'value' => $value, 'comment' => $comment])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('error');
    }

    /** @return array<string, mixed> */
    private function kpiProps(Kpi $kpi, User $user): array
    {
        return $this->actingAs($user)->get($this->kpiUrl($kpi))->assertOk()->viewData('page')['props'];
    }

    /** @return array<string, mixed> */
    private function objectiveProps(Objective $objective, User $user): array
    {
        return $this->actingAs($user)->get("/app/objectives/{$objective->id}")->assertOk()->viewData('page')['props'];
    }

    private function kpiUrl(Kpi $kpi): string
    {
        return "/app/objectives/{$kpi->objective_id}/kpis/{$kpi->id}";
    }

    private function measureUrl(Kpi $kpi): string
    {
        return $this->kpiUrl($kpi).'/measurements';
    }

    private function withdrawUrl(KpiMeasurement $measurement): string
    {
        $kpi = $measurement->kpi;

        return $this->kpiUrl($kpi)."/measurements/{$measurement->id}/withdraw";
    }

    /**
     * A customer with one area, an active objective with one monthly percent KPI (≥ 98 %), a user
     * who measures there, and one who edits there.
     *
     * @param  array<string, mixed>  $kpi
     * @return array{customer: Customer, owner: User, area: BusinessArea, objective: Objective, kpi: Kpi, measurer: User, editor: User}
     */
    private function scenario(array $kpi = []): array
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $area = $this->area($customer, 'Drift');
        $measurer = $this->member($customer);
        $this->grant($customer, $measurer, [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_MEASURE], [$area]);
        $editor = $this->member($customer);
        $this->grant($customer, $editor, [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_EDIT], [$area]);
        $objective = $this->objective($customer, $area, 'Stabil drift', $editor);

        return [
            'customer' => $customer,
            'owner' => $owner,
            'area' => $area,
            'objective' => $objective,
            'kpi' => $this->kpi($objective, $kpi),
            'measurer' => $measurer,
            'editor' => $editor,
        ];
    }

    /** @param array<string, mixed> $override */
    private function kpi(Objective $objective, array $override = []): Kpi
    {
        return Kpi::query()->create($override + [
            'customer_id' => $objective->customer_id,
            'objective_id' => $objective->id,
            'title' => 'Oppetid',
            'unit' => Kpi::UNIT_PERCENT,
            'target_min' => '98',
            'frequency' => Kpi::FREQUENCY_MONTHLY,
            'reporting_grace_days' => 7,
        ]);
    }

    /**
     * The KPI form, as the edit page sends it for the scenario's KPI.
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function payload(array $override = []): array
    {
        return $override + [
            'title' => 'Oppetid',
            'description' => '',
            'owner_user_id' => '',
            'unit' => 'percent',
            'unit_label' => '',
            'currency_code' => '',
            'target_min' => '98',
            'target_max' => '',
            'tolerance' => '',
            'frequency' => 'monthly',
            'reporting_grace_days' => 7,
        ];
    }

    /** A row for writing straight to the table, past the model. */
    private function rawRow(int $customerId, int $kpiId): array
    {
        return [
            'customer_id' => $customerId,
            'kpi_id' => $kpiId,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'value' => '99',
            'target_min' => '98',
            'target_max' => null,
            'tolerance' => null,
            'recorded_at' => now(),
        ];
    }

    private function objective(Customer $customer, BusinessArea $area, string $title, ?User $owner = null): Objective
    {
        return Objective::query()->create([
            'customer_id' => $customer->id,
            'business_area_id' => $area->id,
            'title' => $title,
            'owner_user_id' => $owner?->id,
        ]);
    }

    /**
     * @param  list<string>  $permissionKeys
     * @param  list<BusinessArea>  $areas
     */
    private function grant(Customer $customer, User $user, array $permissionKeys, array $areas): CustomerRole
    {
        $role = $this->role($customer, $permissionKeys);
        $role->syncBusinessAreas(false, array_map(fn (BusinessArea $area): int => (int) $area->id, $areas));
        $user->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        return $role;
    }

    /** @param  list<string>  $permissionKeys */
    private function grantAll(Customer $customer, User $user, array $permissionKeys): CustomerRole
    {
        $role = $this->role($customer, $permissionKeys);
        $role->syncBusinessAreas(true, []);
        $user->customerRoles()->attach($role->id, ['customer_id' => $customer->id]);

        return $role;
    }

    /** @param  list<string>  $permissionKeys */
    private function role(Customer $customer, array $permissionKeys): CustomerRole
    {
        $role = CustomerRole::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Rolle '.Str::upper(Str::random(8)),
            'is_active' => true,
        ]);

        $role->syncPermissions($permissionKeys);

        return $role;
    }

    private function area(Customer $customer, string $name): BusinessArea
    {
        return BusinessArea::query()->create(['customer_id' => $customer->id, 'name' => $name]);
    }

    private function member(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'Medarbeider '.Str::upper(Str::random(6)),
            'email' => 'maling-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    /** @return array{customer: Customer, owner: User} */
    private function context(): array
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        $customer = Customer::query()->create([
            'name' => 'Måling AS',
            'slug' => 'maling-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        CustomerPackageEntitlement::query()->updateOrCreate(
            ['customer_id' => $customer->id, 'package_key' => 'governance'],
            ['status' => CustomerPackageEntitlement::STATUS_ACTIVE, 'activated_at' => now()],
        );

        $owner = User::query()->create([
            'name' => 'System Owner',
            'email' => 'maling-eier-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        return ['customer' => $customer, 'owner' => $owner];
    }
}
