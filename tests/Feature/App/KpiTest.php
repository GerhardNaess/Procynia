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
use App\Models\User;
use App\Services\EnterpriseWiki\Knowledge\Sources\ObjectiveKnowledgeSource;
use App\Services\Modules\ModuleEntitlementService;
use App\Support\CustomerPermissionCatalog;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * KPIs, from database to page. A KPI belongs to exactly one objective and has no fagområde of its
 * own, so every access answer is the objective's:
 *
 *  - read with objective.view in the objective's area; hide the objective and its KPIs are a 404.
 *  - create, change, retire and reopen with objective.edit there, while the objective is active.
 *  - delete with objective.delete there.
 *
 * The rules a KPI keeps (target bounds, tolerance, unit/currency/label, grace days, same customer)
 * are checked by the form, the model and the database. The owner is optional; without one the
 * objective's owner answers for the KPI — on the page, never copied into the row. Retiring and
 * reopening write an immutable history, like the objective's.
 */
class KpiTest extends TestCase
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
    // Model and database rules
    // ---------------------------------------------------------------------

    public function test_a_new_kpi_is_active_with_seven_grace_days_whatever_the_form_says(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $objective = $this->objective($customer, $hr, 'Mål', $user);

        $response = $this->actingAs($user)->post("/app/objectives/{$objective->id}/kpis", $this->payload() + [
            'status' => Kpi::STATUS_RETIRED,
        ]);

        $kpi = Kpi::query()->where('objective_id', $objective->id)->sole();
        $response->assertRedirect("/app/objectives/{$objective->id}/kpis/{$kpi->id}");

        $this->assertSame(Kpi::STATUS_ACTIVE, $kpi->status);
        $this->assertSame(7, $kpi->reporting_grace_days);
        $this->assertSame((int) $customer->id, (int) $kpi->customer_id);
        $this->assertSame((int) $user->id, (int) $kpi->created_by);
        $this->assertSame('99.5000', $kpi->target_min);
        $this->assertNull($kpi->target_max);
        $this->assertSame('1.0000', $kpi->tolerance);
        $this->assertNotContains('status', $kpi->getFillable());
        $this->assertSame(0, KpiStatusChange::query()->where('kpi_id', $kpi->id)->count());
    }

    public function test_a_kpi_needs_an_objective(): void
    {
        ['customer' => $customer] = $this->context();

        try {
            Kpi::query()->create($this->attributes($customer, null));
            $this->fail('A KPI without an objective must not be saved.');
        } catch (DomainException) {
        }

        $this->expectException(QueryException::class);
        DB::table('kpis')->insert($this->rawRow($customer->id, null));
    }

    public function test_an_objective_of_another_customer_is_refused_by_the_model_and_the_database(): void
    {
        ['customer' => $customer] = $this->context();
        ['customer' => $other] = $this->context();
        $theirs = $this->objective($other, $this->area($other, 'HR'), 'Andres mål');

        try {
            Kpi::query()->create($this->attributes($customer, $theirs));
            $this->fail('A KPI under another customer\'s objective must not be saved.');
        } catch (DomainException) {
        }

        $this->expectException(QueryException::class);
        DB::table('kpis')->insert($this->rawRow($customer->id, $theirs->id));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function brokenKpis(): array
    {
        return [
            'no target bound' => [['target_min' => null, 'target_max' => null]],
            'min above max' => [['target_min' => '6', 'target_max' => '5']],
            'negative tolerance' => [['tolerance' => '-0.5']],
            'unknown unit' => [['unit' => 'liters']],
            'unknown frequency' => [['frequency' => 'daily']],
            'negative grace days' => [['reporting_grace_days' => -1]],
            'currency without code' => [['unit' => 'currency', 'currency_code' => null]],
            'code without currency' => [['unit' => 'percent', 'currency_code' => 'NOK']],
            'lowercase code' => [['unit' => 'currency', 'currency_code' => 'nok']],
            'label on percent' => [['unit' => 'percent', 'unit_label' => 'saker']],
        ];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('brokenKpis')]
    public function test_the_model_and_the_database_both_refuse_a_broken_kpi(array $override): void
    {
        ['customer' => $customer] = $this->context();
        $objective = $this->objective($customer, $this->area($customer, 'HR'), 'Mål');

        try {
            Kpi::query()->create($override + $this->attributes($customer, $objective));
            $this->fail('The model must refuse it.');
        } catch (DomainException) {
        }

        $this->expectException(QueryException::class);
        DB::table('kpis')->insert($override + $this->rawRow($customer->id, $objective->id));
    }

    public function test_an_unknown_status_is_refused_by_the_model_and_the_database(): void
    {
        ['customer' => $customer] = $this->context();
        $kpi = $this->kpi($this->objective($customer, $this->area($customer, 'HR'), 'Mål'));

        $kpi->status = 'paused';

        try {
            $kpi->save();
            $this->fail('An unknown status must not be saved.');
        } catch (DomainException) {
        }

        $this->expectException(QueryException::class);
        DB::table('kpis')->where('id', $kpi->id)->update(['status' => 'paused']);
    }

    public function test_the_form_reports_each_broken_rule_on_its_field_in_norwegian(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $objective = $this->objective($customer, $hr, 'Mål', $user);
        $url = "/app/objectives/{$objective->id}/kpis";

        $this->actingAs($user)->post($url, ['target_min' => '', 'target_max' => ''] + $this->payload())
            ->assertSessionHasErrors(['target_min' => 'Fyll inn minst én grense: minst, høyst eller begge.']);
        $this->actingAs($user)->post($url, ['target_min' => '6', 'target_max' => '5'] + $this->payload())
            ->assertSessionHasErrors(['target_max' => '«Høyst» kan ikke være mindre enn «Minst».']);
        $this->actingAs($user)->post($url, ['tolerance' => '-1'] + $this->payload())
            ->assertSessionHasErrors(['tolerance' => 'Toleransen kan ikke være negativ.']);
        $this->actingAs($user)->post($url, ['target_min' => 'mye'] + $this->payload())
            ->assertSessionHasErrors(['target_min' => 'Minst må være et tall med høyst fire desimaler.']);
        $this->actingAs($user)->post($url, ['target_min' => '1.23456'] + $this->payload())
            ->assertSessionHasErrors('target_min');
        $this->actingAs($user)->post($url, ['reporting_grace_days' => -1] + $this->payload())
            ->assertSessionHasErrors(['reporting_grace_days' => 'Innrapporteringsfrist kan ikke være mindre enn 0.']);
        $this->actingAs($user)->post($url, ['unit' => 'currency', 'currency_code' => ''] + $this->payload())
            ->assertSessionHasErrors(['currency_code' => 'Velg valuta for en KPI som måles i valuta.']);
        $this->actingAs($user)->post($url, ['unit' => 'percent', 'currency_code' => 'NOK'] + $this->payload())
            ->assertSessionHasErrors(['currency_code' => 'Valuta brukes bare når enheten er valuta.']);
        $this->actingAs($user)->post($url, ['unit' => 'currency', 'currency_code' => 'NO'] + $this->payload())
            ->assertSessionHasErrors(['currency_code' => 'Valuta må være tre bokstaver, for eksempel NOK.']);
        $this->actingAs($user)->post($url, ['unit' => 'hours', 'unit_label' => 'saker'] + $this->payload())
            ->assertSessionHasErrors(['unit_label' => 'Benevning brukes bare for antall og tall.']);
        $this->actingAs($user)->post($url, ['unit' => 'liters'] + $this->payload())->assertSessionHasErrors('unit');
        $this->actingAs($user)->post($url, ['frequency' => 'daily'] + $this->payload())->assertSessionHasErrors('frequency');
        $this->actingAs($user)->post($url, ['title' => ''] + $this->payload())
            ->assertSessionHasErrors(['title' => 'Tittel må fylles ut.']);

        $this->assertSame(0, Kpi::query()->where('objective_id', $objective->id)->count());
    }

    public function test_the_form_takes_norwegian_decimals_and_fits_unit_details_to_the_unit(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $objective = $this->objective($customer, $hr, 'Mål', $user);
        $url = "/app/objectives/{$objective->id}/kpis";

        $this->actingAs($user)->post($url, [
            'title' => 'Omsetning', 'unit' => 'currency', 'currency_code' => 'nok',
            'target_min' => '1 000 000,50', 'target_max' => '', 'tolerance' => '0,5', 'frequency' => 'quarterly', 'reporting_grace_days' => 0,
        ] + $this->payload())->assertRedirect();

        $kpi = Kpi::query()->where('title', 'Omsetning')->sole();
        $this->assertSame('NOK', $kpi->currency_code);
        $this->assertSame('1000000.5000', $kpi->target_min);
        $this->assertSame('0.5000', $kpi->tolerance);
        $this->assertSame(0, $kpi->reporting_grace_days);
        $this->assertSame(Kpi::FREQUENCY_QUARTERLY, $kpi->frequency);

        $this->actingAs($user)->post($url, ['title' => 'Saker', 'unit' => 'count', 'unit_label' => ' saker ', 'frequency' => '', 'target_min' => '3', 'tolerance' => '1'] + $this->payload())
            ->assertRedirect();
        $count = Kpi::query()->where('title', 'Saker')->sole();
        $this->assertSame('saker', $count->unit_label);
        $this->assertNull($count->frequency);
    }

    // ---------------------------------------------------------------------
    // Access: always the objective's
    // ---------------------------------------------------------------------

    public function test_view_in_the_objectives_area_reads_the_kpi_and_the_wrong_area_does_not(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $it = $this->area($customer, 'IT');
        $kpi = $this->kpi($this->objective($customer, $hr, 'HR-mål'));
        $hidden = $this->kpi($this->objective($customer, $it, 'IT-mål'));

        $reader = $this->member($customer);
        $this->grant($customer, $reader, [CustomerPermissionCatalog::OBJECTIVE_VIEW], [$hr]);

        $this->actingAs($reader)->get($this->kpiUrl($kpi))->assertOk();
        $this->actingAs($reader)->get($this->kpiUrl($hidden))->assertNotFound();
        $this->actingAs($reader)->patch($this->kpiUrl($hidden), $this->payload())->assertNotFound();
        $this->actingAs($reader)->post($this->kpiUrl($hidden).'/retire')->assertNotFound();
        $this->actingAs($reader)->delete($this->kpiUrl($hidden))->assertNotFound();
        $this->actingAs($reader)->post("/app/objectives/{$hidden->objective_id}/kpis", $this->payload())->assertNotFound();
    }

    public function test_a_hidden_objective_hides_its_kpis_and_a_kpi_is_only_found_under_its_own_objective(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $it = $this->area($customer, 'IT');
        $visible = $this->objective($customer, $hr, 'Synlig mål');
        $secret = $this->objective($customer, $it, 'Skjult mål');
        $secretKpi = $this->kpi($secret);

        $reader = $this->member($customer);
        $this->grantAll($customer, $reader, [CustomerPermissionCatalog::OBJECTIVE_VIEW]);
        $this->actingAs($reader)->get($this->kpiUrl($secretKpi))->assertOk();

        // Under another objective's URL the KPI does not exist, even for someone who may read both.
        $this->actingAs($reader)->get("/app/objectives/{$visible->id}/kpis/{$secretKpi->id}")->assertNotFound();

        // Narrow the reader to HR: the KPI goes with its objective.
        $narrow = $this->member($customer);
        $this->grant($customer, $narrow, [CustomerPermissionCatalog::OBJECTIVE_VIEW], [$hr]);
        $this->actingAs($narrow)->get($this->kpiUrl($secretKpi))->assertNotFound();
        $this->actingAs($narrow)->get("/app/objectives/{$visible->id}/kpis/{$secretKpi->id}")->assertNotFound();
    }

    public function test_alle_reaches_kpis_in_every_area(): void
    {
        ['customer' => $customer] = $this->context();
        $kpi = $this->kpi($this->objective($customer, $this->area($customer, 'Økonomi'), 'Mål'));
        $user = $this->member($customer);
        $this->grantAll($customer, $user, [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_EDIT]);

        $this->actingAs($user)->get($this->kpiUrl($kpi))->assertOk();
        $this->actingAs($user)->patch($this->kpiUrl($kpi), ['title' => 'Endret med Alle'] + $this->payload())->assertRedirect();
        $this->assertSame('Endret med Alle', $kpi->fresh()->title);
    }

    public function test_view_without_edit_cannot_create_change_retire_or_reopen(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $objective = $this->objective($customer, $hr, 'Mål');
        $kpi = $this->kpi($objective);
        $reader = $this->member($customer);
        $this->grant($customer, $reader, [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_DELETE], [$hr]);

        $this->actingAs($reader)->post("/app/objectives/{$objective->id}/kpis", $this->payload())->assertForbidden();
        $this->actingAs($reader)->patch($this->kpiUrl($kpi), ['title' => 'Nei'] + $this->payload())->assertForbidden();
        $this->actingAs($reader)->post($this->kpiUrl($kpi).'/retire')->assertForbidden();
        $this->actingAs($reader)->post($this->kpiUrl($kpi).'/reopen', ['reason' => 'Nei'])->assertForbidden();

        $this->assertSame(Kpi::STATUS_ACTIVE, $kpi->fresh()->status);
        $this->assertSame(1, Kpi::query()->where('objective_id', $objective->id)->count());
    }

    public function test_edit_changes_the_kpi_but_never_its_status(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $kpi = $this->kpi($this->objective($customer, $hr, 'Mål', $user));

        $this->actingAs($user)->patch($this->kpiUrl($kpi), [
            'title' => 'Svartid',
            'unit' => 'hours',
            'target_min' => '2',
            'target_max' => '5',
            'tolerance' => '',
            'frequency' => 'weekly',
            'reporting_grace_days' => 3,
            'status' => Kpi::STATUS_RETIRED,
        ] + $this->payload())->assertRedirect();

        $kpi->refresh();
        $this->assertSame('Svartid', $kpi->title);
        $this->assertSame(Kpi::UNIT_HOURS, $kpi->unit);
        $this->assertSame('2.0000', $kpi->target_min);
        $this->assertSame('5.0000', $kpi->target_max);
        $this->assertNull($kpi->tolerance);
        $this->assertSame(3, $kpi->reporting_grace_days);
        $this->assertSame(Kpi::STATUS_ACTIVE, $kpi->status);
        $this->assertSame((int) $user->id, (int) $kpi->updated_by);
    }

    public function test_delete_takes_objective_delete_and_takes_the_history_along(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $objective = $this->objective($customer, $hr, 'Mål');
        $kpi = $this->kpi($objective);
        $editor = $this->editor($customer, $hr);
        $deleter = $this->editor($customer, $hr, [CustomerPermissionCatalog::OBJECTIVE_DELETE]);

        $this->actingAs($editor)->post($this->kpiUrl($kpi).'/retire', ['note' => 'Feilregistrert'])->assertRedirect();
        $this->assertSame(1, KpiStatusChange::query()->where('kpi_id', $kpi->id)->count());

        $props = $this->actingAs($editor)->get($this->kpiUrl($kpi))->viewData('page')['props'];
        $this->assertFalse($props['permissions']['can_delete']);
        $this->actingAs($editor)->delete($this->kpiUrl($kpi))->assertForbidden();

        $props = $this->actingAs($deleter)->get($this->kpiUrl($kpi))->viewData('page')['props'];
        $this->assertTrue($props['permissions']['can_delete']);
        $this->actingAs($deleter)->delete($this->kpiUrl($kpi))->assertRedirect("/app/objectives/{$objective->id}");

        $this->assertNull(Kpi::query()->find($kpi->id));
        $this->assertSame(0, KpiStatusChange::query()->where('kpi_id', $kpi->id)->count());
    }

    public function test_deleting_the_objective_takes_its_kpis_along(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $objective = $this->objective($customer, $hr, 'Mål');
        $kpi = $this->kpi($objective);
        $deleter = $this->editor($customer, $hr, [CustomerPermissionCatalog::OBJECTIVE_DELETE]);

        $this->actingAs($deleter)->delete("/app/objectives/{$objective->id}")->assertRedirect('/app/objectives');

        $this->assertNull(Kpi::query()->find($kpi->id));
    }

    public function test_system_owner_reaches_no_kpi_without_a_role(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $hr = $this->area($customer, 'HR');
        $objective = $this->objective($customer, $hr, 'Mål');
        $kpi = $this->kpi($objective);

        $this->actingAs($owner)->get($this->kpiUrl($kpi))->assertNotFound();
        $this->actingAs($owner)->post("/app/objectives/{$objective->id}/kpis", $this->payload())->assertNotFound();
        $this->actingAs($owner)->delete($this->kpiUrl($kpi))->assertNotFound();

        $this->grant($customer, $owner, [CustomerPermissionCatalog::OBJECTIVE_VIEW], [$hr]);
        $props = $this->actingAs($owner)->get($this->kpiUrl($kpi))->assertOk()->viewData('page')['props'];
        $this->assertSame(['can_edit' => false, 'can_retire' => false, 'can_reopen' => false, 'can_delete' => false, 'can_measure' => false, 'can_link_context' => false], $props['permissions']);
    }

    public function test_another_customers_kpi_can_never_be_read_or_changed(): void
    {
        ['customer' => $customer] = $this->context();
        ['customer' => $other] = $this->context();
        $theirs = $this->kpi($this->objective($other, $this->area($other, 'HR'), 'Andres mål'));
        $user = $this->member($customer);
        $this->grantAll($customer, $user, [
            CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_EDIT, CustomerPermissionCatalog::OBJECTIVE_DELETE,
        ]);

        $this->actingAs($user)->get($this->kpiUrl($theirs))->assertNotFound();
        $this->actingAs($user)->patch($this->kpiUrl($theirs), $this->payload())->assertNotFound();
        $this->actingAs($user)->post($this->kpiUrl($theirs).'/retire')->assertNotFound();
        $this->actingAs($user)->delete($this->kpiUrl($theirs))->assertNotFound();
        $this->actingAs($user)->post("/app/objectives/{$theirs->objective_id}/kpis", $this->payload())->assertNotFound();

        $this->assertSame(Kpi::STATUS_ACTIVE, $theirs->fresh()->status);
    }

    public function test_without_objective_view_everything_is_forbidden(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $kpi = $this->kpi($this->objective($customer, $hr, 'Mål'));
        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_EDIT], [$hr]);

        $this->actingAs($user)->get($this->kpiUrl($kpi))->assertForbidden();
        $this->actingAs($user)->delete($this->kpiUrl($kpi))->assertForbidden();
    }

    public function test_a_closed_objectives_kpis_are_read_but_not_changed_until_it_is_reopened(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $objective = $this->objective($customer, $hr, 'Mål', $user);
        $kpi = $this->kpi($objective);

        $this->actingAs($user)->post("/app/objectives/{$objective->id}/close", ['status' => Objective::STATUS_ACHIEVED])->assertRedirect();

        $this->actingAs($user)->post("/app/objectives/{$objective->id}/kpis", $this->payload())
            ->assertSessionHas('error', 'Målet er lukket. Gjenåpne målet før du endrer KPI-ene.');
        $this->actingAs($user)->patch($this->kpiUrl($kpi), ['title' => 'Nei'] + $this->payload())->assertSessionHas('error');
        $this->actingAs($user)->post($this->kpiUrl($kpi).'/retire')->assertSessionHas('error');

        $props = $this->actingAs($user)->get($this->kpiUrl($kpi))->assertOk()->viewData('page')['props'];
        $this->assertFalse($props['permissions']['can_edit']);
        $this->assertFalse($props['permissions']['can_retire']);
        $this->assertNull($props['form_options']);

        $objectiveProps = $this->actingAs($user)->get("/app/objectives/{$objective->id}")->viewData('page')['props'];
        $this->assertFalse($objectiveProps['permissions']['can_create_kpi']);
        $this->assertNull($objectiveProps['kpi_form_options']);

        $this->assertSame(1, Kpi::query()->where('objective_id', $objective->id)->count());
        $this->assertSame(Kpi::STATUS_ACTIVE, $kpi->fresh()->status);
        $this->assertNotSame('Nei', $kpi->fresh()->title);
    }

    // ---------------------------------------------------------------------
    // Owner and fallback
    // ---------------------------------------------------------------------

    public function test_an_explicit_owner_answers_for_the_kpi(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $kpiOwner = $this->reader($customer, $hr, 'Kari KPI');
        $objective = $this->objective($customer, $hr, 'Mål', $user);

        $this->actingAs($user)->post("/app/objectives/{$objective->id}/kpis", ['owner_user_id' => $kpiOwner->id] + $this->payload())
            ->assertRedirect();
        $kpi = Kpi::query()->where('objective_id', $objective->id)->sole();

        $this->assertSame((int) $kpiOwner->id, (int) $kpi->owner_user_id);
        $props = $this->actingAs($user)->get($this->kpiUrl($kpi))->viewData('page')['props'];
        $this->assertSame('Kari KPI', $props['kpi']['responsible_name']);
        $this->assertFalse($props['kpi']['responsible_is_fallback']);
    }

    public function test_without_an_owner_the_objectives_owner_answers_but_is_never_copied(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $objectiveOwner = $this->reader($customer, $hr, 'Ola Mål');
        $user = $this->editor($customer, $hr);
        $objective = $this->objective($customer, $hr, 'Mål', $objectiveOwner);

        $this->actingAs($user)->post("/app/objectives/{$objective->id}/kpis", ['owner_user_id' => ''] + $this->payload())->assertRedirect();
        $kpi = Kpi::query()->where('objective_id', $objective->id)->sole();

        $this->assertNull($kpi->owner_user_id);
        $props = $this->actingAs($user)->get($this->kpiUrl($kpi))->viewData('page')['props'];
        $this->assertSame('Ola Mål', $props['kpi']['responsible_name']);
        $this->assertTrue($props['kpi']['responsible_is_fallback']);

        // A new objective owner is the KPI's new fallback at once — nothing was copied.
        $newOwner = $this->reader($customer, $hr, 'Nina Ny');
        $objective->forceFill(['owner_user_id' => $newOwner->id])->save();
        $props = $this->actingAs($user)->get($this->kpiUrl($kpi))->viewData('page')['props'];
        $this->assertSame('Nina Ny', $props['kpi']['responsible_name']);
        $this->assertNull($kpi->fresh()->owner_user_id);
    }

    public function test_an_owner_must_be_an_active_person_of_the_customer_who_can_see_the_objective(): void
    {
        ['customer' => $customer] = $this->context();
        ['customer' => $other] = $this->context();
        $hr = $this->area($customer, 'HR');
        $it = $this->area($customer, 'IT');
        $user = $this->editor($customer, $hr);
        $objective = $this->objective($customer, $hr, 'Mål', $user);
        $url = "/app/objectives/{$objective->id}/kpis";

        $foreigner = $this->member($other);
        $this->grantAll($other, $foreigner, [CustomerPermissionCatalog::OBJECTIVE_VIEW]);
        $blind = $this->reader($customer, $it, 'Ser bare IT');
        $inactive = $this->reader($customer, $hr, 'Sluttet');
        $inactive->forceFill(['is_active' => false])->save();
        $noRole = $this->member($customer);

        foreach ([$foreigner, $blind, $inactive, $noRole] as $candidate) {
            $this->actingAs($user)->post($url, ['owner_user_id' => $candidate->id] + $this->payload())
                ->assertSessionHasErrors(['owner_user_id' => 'Ansvarlig må være en aktiv bruker som kan se målet.']);
        }

        $this->assertSame(0, Kpi::query()->where('objective_id', $objective->id)->count());

        // The form offers only those who could be chosen.
        $options = $this->actingAs($user)->get("/app/objectives/{$objective->id}")->viewData('page')['props']['kpi_form_options']['owner_options'];
        $ids = array_column($options, 'id');
        $this->assertContains((int) $user->id, $ids);
        foreach ([$foreigner, $blind, $inactive, $noRole] as $candidate) {
            $this->assertNotContains((int) $candidate->id, $ids);
        }
    }

    public function test_a_deleted_kpi_owner_falls_back_to_the_objectives_owner(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $objectiveOwner = $this->reader($customer, $hr, 'Ola Mål');
        $kpiOwner = $this->reader($customer, $hr, 'Kari KPI');
        $kpi = $this->kpi($this->objective($customer, $hr, 'Mål', $objectiveOwner), ['owner_user_id' => $kpiOwner->id]);

        $kpiOwner->delete();

        $this->assertNull($kpi->fresh()->owner_user_id);
        $props = $this->actingAs($objectiveOwner)->get($this->kpiUrl($kpi))->viewData('page')['props'];
        $this->assertSame('Ola Mål', $props['kpi']['responsible_name']);
        $this->assertTrue($props['kpi']['responsible_is_fallback']);
    }

    public function test_an_objective_cannot_move_where_a_kpi_owner_would_lose_sight_of_it(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $it = $this->area($customer, 'IT');
        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_EDIT], [$hr, $it]);
        $hrOnly = $this->reader($customer, $hr, 'Bare HR');
        $objective = $this->objective($customer, $hr, 'Mål', $user);
        $this->kpi($objective, ['owner_user_id' => $hrOnly->id]);

        $move = ['title' => 'Mål', 'description' => null, 'business_area_id' => $it->id, 'owner_user_id' => $user->id, 'target_date' => null];

        $this->actingAs($user)->patch("/app/objectives/{$objective->id}", $move)
            ->assertSessionHasErrors(['business_area_id' => 'Ansvarlig for en eller flere KPI-er kan ikke se mål i det nye fagområdet. Bytt ansvarlig på KPI-ene først.']);
        $this->assertSame((int) $hr->id, (int) $objective->fresh()->business_area_id);

        // Once the KPI owner can see IT too, the move goes through and the KPI moves with it.
        $this->grant($customer, $hrOnly, [CustomerPermissionCatalog::OBJECTIVE_VIEW], [$it]);
        $this->actingAs($user)->patch("/app/objectives/{$objective->id}", $move)->assertSessionHasNoErrors();
        $this->assertSame((int) $it->id, (int) $objective->fresh()->business_area_id);
    }

    // ---------------------------------------------------------------------
    // Lifecycle: Avslutt / Gjenåpne with history
    // ---------------------------------------------------------------------

    public function test_retire_and_reopen_write_history_and_reopening_needs_a_reason(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $kpi = $this->kpi($this->objective($customer, $hr, 'Mål', $user));

        $this->actingAs($user)->post($this->kpiUrl($kpi).'/retire', ['note' => 'Måles ikke lenger'])->assertRedirect();
        $this->assertSame(Kpi::STATUS_RETIRED, $kpi->fresh()->status);

        // A retired KPI is reopened before it is edited, and cannot be retired twice.
        $this->actingAs($user)->patch($this->kpiUrl($kpi), ['title' => 'Nei'] + $this->payload())
            ->assertSessionHas('error', 'Gjenåpne KPI-en før du endrer den.');
        $this->actingAs($user)->post($this->kpiUrl($kpi).'/retire')->assertSessionHasErrors('note');

        $this->actingAs($user)->post($this->kpiUrl($kpi).'/reopen', ['reason' => '  '])->assertSessionHasErrors('reason');
        $this->assertSame(Kpi::STATUS_RETIRED, $kpi->fresh()->status);

        $this->actingAs($user)->post($this->kpiUrl($kpi).'/reopen', ['reason' => 'Trengs i ny strategi'])->assertRedirect();
        $this->assertSame(Kpi::STATUS_ACTIVE, $kpi->fresh()->status);

        $props = $this->actingAs($user)->get($this->kpiUrl($kpi))->viewData('page')['props'];
        $history = $props['status_history'];
        $this->assertCount(2, $history);
        $this->assertSame(['retired', 'active', 'Trengs i ny strategi'], [$history[0]['from_status'], $history[0]['to_status'], $history[0]['note']]);
        $this->assertSame(['active', 'retired', 'Måles ikke lenger'], [$history[1]['from_status'], $history[1]['to_status'], $history[1]['note']]);
        $this->assertSame($user->name, $history[1]['changed_by_name']);
    }

    public function test_history_is_immutable_and_the_database_refuses_impossible_changes(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $kpi = $this->kpi($this->objective($customer, $hr, 'Mål', $user));
        $this->actingAs($user)->post($this->kpiUrl($kpi).'/retire')->assertRedirect();
        $change = KpiStatusChange::query()->where('kpi_id', $kpi->id)->sole();

        try {
            $change->update(['note' => 'Omskrevet']);
            $this->fail('History must not be changed.');
        } catch (LogicException) {
        }

        try {
            $change->delete();
            $this->fail('History must not be deleted on its own.');
        } catch (LogicException) {
        }

        $row = ['customer_id' => $customer->id, 'kpi_id' => $kpi->id, 'changed_by_user_id' => $user->id, 'changed_at' => now()];

        try {
            DB::transaction(fn () => DB::table('kpi_status_changes')->insert($row + ['from_status' => 'retired', 'to_status' => 'active', 'note' => null]));
            $this->fail('A reopening without a reason must be refused.');
        } catch (QueryException) {
        }

        // No route edits or removes KPI history; only retiring and reopening write it.
        $kpiRoutes = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route): string => (string) $route->getName())
            ->filter(fn (string $name): bool => str_starts_with($name, 'app.objectives.kpis.'))
            ->sort()
            ->values()
            ->all();
        $this->assertSame([
            'app.objectives.kpis.context.update', 'app.objectives.kpis.destroy', 'app.objectives.kpis.measurements.store', 'app.objectives.kpis.measurements.withdraw',
            'app.objectives.kpis.reopen', 'app.objectives.kpis.retire',
            'app.objectives.kpis.show', 'app.objectives.kpis.store', 'app.objectives.kpis.update',
        ], $kpiRoutes);

        $this->expectException(QueryException::class);
        DB::table('kpi_status_changes')->insert($row + ['from_status' => 'active', 'to_status' => 'active', 'note' => 'x']);
    }

    // ---------------------------------------------------------------------
    // Pages
    // ---------------------------------------------------------------------

    public function test_the_objective_page_lists_its_kpis_with_formatted_targets_and_not_measured(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $objective = $this->objective($customer, $hr, 'Mål', $user);
        $this->kpi($objective, ['title' => 'Oppetid', 'unit' => 'percent', 'target_min' => '99.5', 'frequency' => 'monthly']);
        $this->kpi($objective, ['title' => 'Alvorlige avvik', 'unit' => 'count', 'unit_label' => 'hendelser', 'target_min' => null, 'target_max' => '3']);
        $this->kpi($objective, ['title' => 'Svartid', 'unit' => 'hours', 'target_min' => '2', 'target_max' => '5']);
        $this->kpi($objective, ['title' => 'Omsetning', 'unit' => 'currency', 'currency_code' => 'NOK', 'target_min' => '1000000']);
        $retired = $this->kpi($objective, ['title' => 'Aaa gammel']);
        $retired->forceFill(['status' => Kpi::STATUS_RETIRED])->save();
        $this->kpi($this->objective($customer, $hr, 'Annet mål'), ['title' => 'Ikke her']);

        $props = $this->actingAs($user)->get("/app/objectives/{$objective->id}")->assertOk()->viewData('page')['props'];
        $rows = collect($props['kpis'])->keyBy('title');

        // Active first, by title; the retired one last.
        $this->assertSame(['Alvorlige avvik', 'Omsetning', 'Oppetid', 'Svartid', 'Aaa gammel'], array_column($props['kpis'], 'title'));
        $nbsp = fn (string $value): string => str_replace("\u{00A0}", ' ', $value);
        $this->assertSame('≥ 99,5 %', $nbsp($rows['Oppetid']['target_display']));
        $this->assertSame('≤ 3 hendelser', $nbsp($rows['Alvorlige avvik']['target_display']));
        $this->assertSame('2–5 timer', $nbsp($rows['Svartid']['target_display']));
        $this->assertSame('≥ NOK 1 000 000', $nbsp($rows['Omsetning']['target_display']));
        $this->assertSame('Antall (hendelser)', $rows['Alvorlige avvik']['unit_display']);
        $this->assertSame('monthly', $rows['Oppetid']['frequency']);
        $this->assertSame(Kpi::STATUS_RETIRED, $rows['Aaa gammel']['status']);
        $this->assertSame($user->name, $rows['Oppetid']['responsible_name']);
        $this->assertTrue($rows['Oppetid']['responsible_is_fallback']);

        // Nothing measured yet, and nothing pretended.
        $this->assertNull($rows['Oppetid']['latest_value_display']);
        $this->assertSame('not_measured', $rows['Oppetid']['result']);
        $this->assertSame(['on_target' => 0, 'total' => 4], $props['kpi_indicator']);

        $this->assertTrue($props['permissions']['can_create_kpi']);
        $this->assertSame(Kpi::UNITS, $props['kpi_form_options']['units']);
    }

    public function test_the_kpi_page_and_its_buttons_follow_the_users_rights(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $editor = $this->editor($customer, $hr);
        $reader = $this->reader($customer, $hr, 'Leser');
        $objective = $this->objective($customer, $hr, 'Mål', $editor);
        $kpi = $this->kpi($objective, ['tolerance' => '1', 'frequency' => 'monthly', 'reporting_grace_days' => 7]);

        $props = $this->actingAs($editor)->get($this->kpiUrl($kpi))->assertOk()->viewData('page')['props'];
        $this->assertSame('App/Objectives/KpiShow', $this->actingAs($editor)->get($this->kpiUrl($kpi))->viewData('page')['component']);
        $this->assertSame(['can_edit' => true, 'can_retire' => true, 'can_reopen' => false, 'can_delete' => false, 'can_measure' => false, 'can_link_context' => false], $props['permissions']);
        $this->assertSame('Mål', $props['objective']['title']);
        // A percent tolerance is in percentage points, never «1 %» of the målverdi.
        $this->assertSame('1 prosentpoeng', str_replace("\u{00A0}", ' ', $props['kpi']['tolerance_display']));
        $this->assertSame('7 dager etter at perioden er slutt', $props['kpi']['deadline_display']);
        $this->assertSame('99.5', $props['kpi']['target_min']);
        $this->assertNotNull($props['form_options']);

        $props = $this->actingAs($reader)->get($this->kpiUrl($kpi))->assertOk()->viewData('page')['props'];
        $this->assertSame(['can_edit' => false, 'can_retire' => false, 'can_reopen' => false, 'can_delete' => false, 'can_measure' => false, 'can_link_context' => false], $props['permissions']);
        $this->assertNull($props['form_options']);

        $objectiveProps = $this->actingAs($reader)->get("/app/objectives/{$objective->id}")->viewData('page')['props'];
        $this->assertFalse($objectiveProps['permissions']['can_create_kpi']);
        $this->assertNull($objectiveProps['kpi_form_options']);
        $this->assertCount(1, $objectiveProps['kpis']);

        $kpi->forceFill(['frequency' => null, 'tolerance' => null])->save();
        $props = $this->actingAs($editor)->get($this->kpiUrl($kpi))->viewData('page')['props'];
        $this->assertSame('Ingen fast frist, KPI-en har ingen fast målefrekvens', $props['kpi']['deadline_display']);
        $this->assertNull($props['kpi']['tolerance_display']);

        $kpi->forceFill(['status' => Kpi::STATUS_RETIRED])->save();
        $props = $this->actingAs($editor)->get($this->kpiUrl($kpi))->viewData('page')['props'];
        $this->assertSame(['can_edit' => false, 'can_retire' => false, 'can_reopen' => true, 'can_delete' => false, 'can_measure' => false, 'can_link_context' => false], $props['permissions']);
    }

    // ---------------------------------------------------------------------
    // Måltype, startverdi and the unit's rules
    // ---------------------------------------------------------------------

    /** @return array<string, array{array<string, string>, string|null, string|null, string}> */
    public static function targetTypes(): array
    {
        return [
            // Fields that do not belong to the type are dropped, whatever the form still held.
            'minst' => [['target_type' => 'at_least', 'target_min' => '100', 'target_max' => '200'], '100.0000', null, 'at_least'],
            'høyst' => [['target_type' => 'at_most', 'target_min' => '1', 'target_max' => '5'], null, '5.0000', 'at_most'],
            'intervall' => [['target_type' => 'range', 'target_min' => '18', 'target_max' => '22'], '18.0000', '22.0000', 'range'],
            'nøyaktig' => [['target_type' => 'exact', 'target_min' => '100', 'target_max' => '7'], '100.0000', '100.0000', 'exact'],
        ];
    }

    /** @param array<string, string> $fields */
    #[DataProvider('targetTypes')]
    public function test_each_target_type_stores_only_its_own_bounds(array $fields, ?string $min, ?string $max, string $type): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $objective = $this->objective($customer, $hr, 'Mål', $user);

        $this->actingAs($user)->post("/app/objectives/{$objective->id}/kpis", $fields + ['unit' => 'number', 'tolerance' => ''] + $this->payload())
            ->assertSessionHasNoErrors();

        $kpi = Kpi::query()->where('objective_id', $objective->id)->sole();
        $this->assertSame($min, $kpi->target_min);
        $this->assertSame($max, $kpi->target_max);
        $this->assertNull($kpi->baseline_value);

        $props = $this->actingAs($user)->get($this->kpiUrl($kpi))->viewData('page')['props']['kpi'];
        $this->assertSame($type, $props['target_type']);
        $this->assertSame($type, $props['form_target_type']);
    }

    public function test_a_target_type_needs_its_fields_and_a_range_needs_from_below_to(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $objective = $this->objective($customer, $hr, 'Mål', $user);
        $url = "/app/objectives/{$objective->id}/kpis";
        $post = fn (array $fields) => $this->actingAs($user)->post($url, $fields + ['unit' => 'number', 'tolerance' => ''] + $this->payload());

        $post(['target_type' => 'at_least', 'target_min' => ''])->assertSessionHasErrors(['target_min' => 'Fyll inn målverdien.']);
        $post(['target_type' => 'at_most', 'target_max' => ''])->assertSessionHasErrors(['target_max' => 'Fyll inn målverdien.']);
        $post(['target_type' => 'range', 'target_min' => '18', 'target_max' => ''])->assertSessionHasErrors(['target_max' => 'Fyll inn målverdien.']);
        $post(['target_type' => 'range', 'target_min' => '22', 'target_max' => '18'])->assertSessionHasErrors(['target_max' => '«Høyst» kan ikke være mindre enn «Minst».']);
        $post(['target_type' => 'range', 'target_min' => '18', 'target_max' => '18'])->assertSessionHasErrors(['target_max' => '«Fra» og «Til» er like. Velg «Nøyaktig verdi» for ett bestemt tall.']);
        $post(['target_type' => 'something'])->assertSessionHasErrors('target_type');

        $this->assertSame(0, Kpi::query()->where('objective_id', $objective->id)->count());
    }

    public function test_a_growth_target_is_computed_from_the_startverdi_and_the_change_is_never_stored(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $objective = $this->objective($customer, $hr, 'Vekst', $user);
        $objective->forceFill(['target_date' => '2027-10-30'])->save();

        $this->actingAs($user)->post("/app/objectives/{$objective->id}/kpis", [
            'title' => 'FCV', 'unit' => 'currency', 'currency_code' => 'NOK', 'target_type' => 'growth',
            'baseline_value' => '1 000 000', 'baseline_date' => '2026-10-01', 'change_percent' => '30',
            'target_min' => '5', 'target_max' => '9', 'tolerance' => '',
        ] + $this->payload())->assertSessionHasNoErrors();

        $kpi = Kpi::query()->where('title', 'FCV')->sole();
        $this->assertSame('1300000.0000', $kpi->target_min);
        $this->assertNull($kpi->target_max);
        $this->assertSame('1000000.0000', $kpi->baseline_value);
        $this->assertSame('2026-10-01', $kpi->baseline_date->format('Y-m-d'));
        $this->assertArrayNotHasKey('change_percent', $kpi->getAttributes());

        $props = $this->actingAs($user)->get($this->kpiUrl($kpi))->viewData('page')['props']['kpi'];
        $this->assertSame('growth', $props['form_target_type']);
        $this->assertSame('at_least', $props['target_type']);
        $this->assertSame('30', $props['change_percent']);
        $this->assertSame('Økning på 30 %', $this->spaces($props['change_display']));
        $this->assertSame(
            'Målet er nådd når verdien er minst NOK 1 300 000. Det er en økning på 30 % fra startverdien NOK 1 000 000 (1. oktober 2026).',
            $this->spaces($props['target_rule']),
        );
        $this->assertSame('Ingen gjeldende måling ennå. KPI-en får status når den første målingen er registrert.', $props['status_explanation']);
        $this->assertSame('not_measured', $props['result']);
        // The KPI follows the objective's måldato; there is no deadline of its own.
        $this->assertSame('30. oktober 2027', $props['deadline']['date_display']);
        $this->assertFalse($props['deadline']['passed']);

        // A decrease is «Høyst».
        $this->actingAs($user)->patch($this->kpiUrl($kpi), [
            'title' => 'FCV', 'unit' => 'currency', 'currency_code' => 'NOK', 'target_type' => 'growth',
            'baseline_value' => '1000000', 'baseline_date' => '2026-10-01', 'change_percent' => '-20,5', 'tolerance' => '',
        ] + $this->payload())->assertSessionHasNoErrors();
        $kpi->refresh();
        $this->assertNull($kpi->target_min);
        $this->assertSame('795000.0000', $kpi->target_max);

        // Changed to a plain «Minst», the startverdi goes.
        $this->actingAs($user)->patch($this->kpiUrl($kpi), [
            'title' => 'FCV', 'unit' => 'currency', 'currency_code' => 'NOK', 'target_type' => 'at_least',
            'target_min' => '1200000', 'baseline_value' => '1000000', 'change_percent' => '30', 'tolerance' => '',
        ] + $this->payload())->assertSessionHasNoErrors();
        $kpi->refresh();
        $this->assertNull($kpi->baseline_value);
        $this->assertNull($kpi->baseline_date);
        $this->assertSame('1200000.0000', $kpi->target_min);
    }

    public function test_saving_a_growth_kpi_again_never_moves_its_target(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $objective = $this->objective($customer, $hr, 'Mål', $user);
        $kpi = $this->kpi($objective, [
            'unit' => 'number', 'target_min' => '3.9999', 'tolerance' => null, 'baseline_value' => '3', 'baseline_date' => '2026-01-01',
        ]);

        $props = $this->actingAs($user)->get($this->kpiUrl($kpi))->viewData('page')['props']['kpi'];
        $this->assertSame('33.33', $props['change_percent']);

        // The form sends back exactly what the page gave it.
        $this->actingAs($user)->patch($this->kpiUrl($kpi), [
            'title' => 'KPI', 'unit' => 'number', 'target_type' => $props['form_target_type'],
            'baseline_value' => $props['baseline_value'], 'baseline_date' => $props['baseline_date'], 'change_percent' => $props['change_percent'], 'tolerance' => '',
        ] + $this->payload())->assertSessionHasNoErrors();

        $this->assertSame('3.9999', $kpi->fresh()->target_min);
    }

    public function test_growth_is_refused_for_percent_units_and_without_a_positive_startverdi(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $objective = $this->objective($customer, $hr, 'Mål', $user);
        $url = "/app/objectives/{$objective->id}/kpis";
        $growth = ['target_type' => 'growth', 'baseline_value' => '40', 'baseline_date' => '2026-01-01', 'change_percent' => '10', 'tolerance' => ''];

        $this->actingAs($user)->post($url, ['unit' => 'percent'] + $growth + $this->payload())
            ->assertSessionHasErrors(['target_type' => 'Endring fra startverdi kan ikke brukes med prosent som enhet. Velg «Minst» eller «Høyst».']);
        $this->actingAs($user)->post($url, ['unit' => 'number', 'baseline_value' => '0'] + $growth + $this->payload())
            ->assertSessionHasErrors(['baseline_value' => 'Startverdien må være større enn 0.']);
        $this->actingAs($user)->post($url, ['unit' => 'number', 'change_percent' => '0'] + $growth + $this->payload())
            ->assertSessionHasErrors(['change_percent' => 'Endringen kan ikke være 0 %.']);
        $this->actingAs($user)->post($url, ['unit' => 'number', 'baseline_date' => ''] + $growth + $this->payload())
            ->assertSessionHasErrors(['baseline_date' => 'Velg dato for startverdien.']);
        $this->actingAs($user)->post($url, ['unit' => 'count', 'baseline_value' => '7.5'] + $growth + $this->payload())
            ->assertSessionHasErrors(['baseline_value' => 'Skriv et helt tall.']);

        $this->assertSame(0, Kpi::query()->where('objective_id', $objective->id)->count());
    }

    public function test_the_model_and_the_database_keep_the_startverdi_rules(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $objective = $this->objective($customer, $hr, 'Mål');

        foreach ([
            ['unit' => 'number', 'baseline_value' => '5', 'baseline_date' => null],
            ['unit' => 'number', 'baseline_value' => '-5', 'baseline_date' => '2026-01-01'],
            ['unit' => 'number', 'baseline_value' => '5', 'baseline_date' => '2026-01-01', 'target_min' => '1', 'target_max' => '9'],
            ['unit' => 'percent', 'baseline_value' => '5', 'baseline_date' => '2026-01-01'],
        ] as $override) {
            try {
                $this->kpi($objective, $override);
                $this->fail('A broken startverdi must not be saved: '.json_encode($override));
            } catch (DomainException) {
            }
        }

        $this->expectException(QueryException::class);
        DB::table('kpis')->insert(['baseline_value' => '5', 'baseline_date' => '2026-01-01', 'target_max' => '9'] + $this->rawRow($customer->id, $objective->id));
    }

    public function test_numbers_must_fit_the_unit(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $objective = $this->objective($customer, $hr, 'Mål', $user);
        $url = "/app/objectives/{$objective->id}/kpis";
        $post = fn (array $fields) => $this->actingAs($user)->post($url, $fields + ['tolerance' => ''] + $this->payload());

        $post(['unit' => 'percent', 'target_type' => 'at_least', 'target_min' => '130'])
            ->assertSessionHasErrors(['target_min' => 'En prosentandel er mellom 0 og 100. Måler KPI-en endring, velg enheten «Prosentvis endring».']);
        $post(['unit' => 'count', 'target_type' => 'at_most', 'target_max' => '2.5'])->assertSessionHasErrors(['target_max' => 'Skriv et helt tall.']);
        $post(['unit' => 'count', 'target_type' => 'at_least', 'target_min' => '3', 'tolerance' => '0.5'])->assertSessionHasErrors(['tolerance' => 'Skriv et helt tall.']);
        $post(['unit' => 'currency', 'currency_code' => 'NOK', 'target_type' => 'at_least', 'target_min' => '10.125'])
            ->assertSessionHasErrors(['target_min' => 'Skriv høyst 2 desimaler for denne enheten.']);
        $post(['unit' => 'hours', 'target_type' => 'at_most', 'target_max' => '-1'])->assertSessionHasErrors(['target_max' => 'Verdien kan ikke være negativ for denne enheten.']);
        $this->assertSame(0, Kpi::query()->where('objective_id', $objective->id)->count());

        // What the unit means is allowed: change above 100 %, negative amounts and numbers, a share of 99,995 %.
        $post(['title' => 'Endring', 'unit' => 'percent_change', 'target_type' => 'range', 'target_min' => '-20', 'target_max' => '150'])->assertSessionHasNoErrors();
        $post(['title' => 'Resultat', 'unit' => 'currency', 'currency_code' => 'NOK', 'target_type' => 'at_least', 'target_min' => '-250000.50'])->assertSessionHasNoErrors();
        $post(['title' => 'Temperatur', 'unit' => 'number', 'target_type' => 'range', 'target_min' => '-5.5', 'target_max' => '4'])->assertSessionHasNoErrors();
        $post(['title' => 'Oppetid', 'unit' => 'percent', 'target_type' => 'at_least', 'target_min' => '99,995', 'tolerance' => '150'])->assertSessionHasNoErrors();
        $this->assertSame(4, Kpi::query()->where('objective_id', $objective->id)->count());
    }

    /**
     * The real case from before måltyper: «30 % økning av FCV» saved as a share with both bounds.
     * It stays an interval, its numbers are not touched, and the page points out what no longer
     * fits the unit instead of guessing what was meant.
     */
    public function test_an_existing_kpi_with_both_bounds_stays_an_interval_and_is_pointed_out_not_changed(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $objective = $this->objective($customer, $hr, 'Mål', $user);
        DB::table('kpis')->insert([
            'title' => '30% økning av FCV innen ett år fra dagens nivå.', 'target_min' => '1000000', 'target_max' => '1300000', 'tolerance' => '0',
        ] + $this->rawRow($customer->id, $objective->id));
        $kpi = Kpi::query()->where('objective_id', $objective->id)->sole();

        $props = $this->actingAs($user)->get($this->kpiUrl($kpi))->assertOk()->viewData('page')['props']['kpi'];
        $this->assertSame('range', $props['target_type']);
        $this->assertSame('range', $props['form_target_type']);
        $this->assertSame('Målet er nådd når verdien er mellom 1 000 000 % og 1 300 000 %.', $this->spaces($props['target_rule']));
        $this->assertSame(['Minst', 'Høyst'], $props['definition_problems']);

        $fresh = $kpi->fresh();
        $this->assertSame('1000000.0000', $fresh->target_min);
        $this->assertSame('1300000.0000', $fresh->target_max);
        $this->assertSame('percent', $fresh->unit);

        // Corrected by hand: the right unit, as a growth target.
        $this->actingAs($user)->patch($this->kpiUrl($kpi), [
            'title' => 'FCV', 'unit' => 'currency', 'currency_code' => 'NOK', 'target_type' => 'growth',
            'baseline_value' => '1000000', 'baseline_date' => '2026-10-01', 'change_percent' => '30', 'tolerance' => '',
        ] + $this->payload())->assertSessionHasNoErrors();
        $props = $this->actingAs($user)->get($this->kpiUrl($kpi))->viewData('page')['props']['kpi'];
        $this->assertSame([], $props['definition_problems']);
        $this->assertSame('1300000.0000', $kpi->fresh()->target_min);
    }

    public function test_a_form_without_a_target_type_is_read_by_its_bounds_as_before(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $objective = $this->objective($customer, $hr, 'Mål', $user);

        $this->actingAs($user)->post("/app/objectives/{$objective->id}/kpis", ['unit' => 'number', 'target_min' => '2', 'target_max' => '5', 'tolerance' => ''] + $this->payload())
            ->assertSessionHasNoErrors();

        $kpi = Kpi::query()->where('objective_id', $objective->id)->sole();
        $this->assertSame(['2.0000', '5.0000'], [$kpi->target_min, $kpi->target_max]);
    }

    public function test_a_kpi_with_measurements_may_swap_share_and_change_but_no_other_unit(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $objective = $this->objective($customer, $hr, 'Mål', $user);
        $kpi = $this->kpi($objective, ['tolerance' => null]);
        KpiMeasurement::query()->create([
            'customer_id' => $customer->id, 'kpi_id' => $kpi->id, 'period_start' => '2026-01-01', 'period_end' => '2026-01-31',
            'value' => '99.7', 'target_min' => '99.5', 'recorded_at' => now(),
        ]);
        $form = ['target_type' => 'at_least', 'target_min' => '20', 'tolerance' => ''] + $this->payload();

        $this->actingAs($user)->patch($this->kpiUrl($kpi), ['unit' => 'percent_change'] + $form)->assertSessionHasNoErrors();
        $this->assertSame('percent_change', $kpi->fresh()->unit);

        $this->actingAs($user)->patch($this->kpiUrl($kpi), ['unit' => 'percent'] + $form)->assertSessionHasNoErrors();
        $this->assertSame('percent', $kpi->fresh()->unit);

        $this->actingAs($user)->patch($this->kpiUrl($kpi), ['unit' => 'number'] + $form)->assertSessionHasErrors('unit');
        $this->assertSame('percent', $kpi->fresh()->unit);
    }

    public function test_the_wiki_draft_reads_the_kpi_like_its_page(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $objective = $this->objective($customer, $hr, 'Vekst', $user);
        $interval = $this->kpi($objective, ['title' => 'Omsetning', 'unit' => 'currency', 'currency_code' => 'NOK', 'target_min' => '1000000', 'target_max' => '1300000', 'tolerance' => null]);
        $this->kpi($objective, ['title' => 'Ikke målt', 'unit' => 'days', 'target_min' => null, 'target_max' => '5', 'tolerance' => null]);
        KpiMeasurement::query()->create([
            'customer_id' => $customer->id, 'kpi_id' => $interval->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
            'value' => '1400000', 'target_min' => '1000000', 'target_max' => '1300000', 'recorded_at' => now(),
        ]);

        app()->setLocale('no');
        $lines = collect(app(ObjectiveKnowledgeSource::class)->draft($user, $objective)->sections)->firstWhere('key', 'kpis')->lines;

        $this->assertSame([
            'Omsetning — Målet er nådd når verdien er mellom NOK 1 000 000 og NOK 1 300 000. — Siste måling: NOK 1 400 000 (September 2026) — Status: Utenfor mål',
            'Ikke målt — Målet er nådd når verdien er høyst 5 dager. — Status: Ikke målt',
        ], array_map(fn (string $line): string => $this->spaces($line), $lines));
        $this->assertStringNotContainsString('.0000', implode(' ', $lines));
    }

    private function spaces(string $value): string
    {
        return str_replace("\u{00A0}", ' ', $value);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'title' => 'Oppetid',
            'description' => 'Tilgjengelighet for kundeportalen',
            'owner_user_id' => '',
            'unit' => 'percent',
            'unit_label' => '',
            'currency_code' => '',
            'target_min' => '99.5',
            'target_max' => '',
            'tolerance' => '1.0',
            'frequency' => 'monthly',
            'reporting_grace_days' => 7,
        ];
    }

    /** @return array<string, mixed> */
    private function attributes(Customer $customer, ?Objective $objective): array
    {
        return [
            'customer_id' => $customer->id,
            'objective_id' => $objective?->id,
            'title' => 'KPI',
            'unit' => Kpi::UNIT_PERCENT,
            'target_min' => '99.5',
            'tolerance' => '1',
            'frequency' => Kpi::FREQUENCY_MONTHLY,
            'reporting_grace_days' => 7,
        ];
    }

    /** A row for writing straight to the table, past the model. */
    private function rawRow(int $customerId, ?int $objectiveId): array
    {
        return [
            'customer_id' => $customerId,
            'objective_id' => $objectiveId,
            'title' => 'Rå KPI',
            'unit' => 'percent',
            'unit_label' => null,
            'currency_code' => null,
            'target_min' => '99.5',
            'target_max' => null,
            'tolerance' => '1',
            'frequency' => 'monthly',
            'reporting_grace_days' => 7,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** @param array<string, mixed> $override */
    private function kpi(Objective $objective, array $override = []): Kpi
    {
        return Kpi::query()->create($override + $this->attributes($objective->customer, $objective));
    }

    private function kpiUrl(Kpi $kpi): string
    {
        return "/app/objectives/{$kpi->objective_id}/kpis/{$kpi->id}";
    }

    /** @param list<string> $extra */
    private function editor(Customer $customer, BusinessArea $area, array $extra = []): User
    {
        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_EDIT, ...$extra], [$area]);

        return $user;
    }

    private function reader(Customer $customer, BusinessArea $area, string $name): User
    {
        $user = $this->member($customer);
        $user->forceFill(['name' => $name])->save();
        $this->grant($customer, $user, [CustomerPermissionCatalog::OBJECTIVE_VIEW], [$area]);

        return $user;
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
            'email' => 'kpi-'.Str::lower(Str::random(10)).'@procynia.local',
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
            'name' => 'KPI AS',
            'slug' => 'kpi-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        app(ModuleEntitlementService::class)->activatePackage($customer, 'governance');

        $owner = User::query()->create([
            'name' => 'System Owner',
            'email' => 'kpi-eier-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        return ['customer' => $customer, 'owner' => $owner];
    }
}
