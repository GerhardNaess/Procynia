<?php

namespace Tests\Feature\App;

use App\Models\ImprovementCase;
use App\Models\ImprovementCaseStatusChange;
use App\Support\CustomerPermissionCatalog;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use LogicException;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Avvik og forbedringer, from database to page, gated twice: by the customer's own roles (what) and
 * by fagområder (where), exactly as Risiko and Mål og KPI.
 *
 * What these tests defend:
 *
 *  - One case type for avvik and forbedring; only those two types and four statuses exist.
 *  - A case outside the user's areas is undiscoverable — not listed, searched or counted, and a 404
 *    by URL for read and for every write. Permission and area pair per role; «Alle» is a wildcard;
 *    inactive roles and System Owner's administrator grant reach no case.
 *  - improvement.edit registers, changes and starts handling; improvement.close closes, cancels and
 *    reopens; improvement.delete deletes only a case nobody has started on.
 *  - The owner is an active person of the same customer who can read cases in the area.
 *  - Status is never a form field. Every change goes through the lifecycle, writes one immutable
 *    history row, and is refused when the case is no longer where the change starts from.
 */
class ImprovementCaseTest extends TestCase
{
    use CreatesImprovementCaseScenarios;
    use UsesProjectPostgresConnection;

    private const ALL = [
        CustomerPermissionCatalog::IMPROVEMENT_VIEW,
        CustomerPermissionCatalog::IMPROVEMENT_EDIT,
        CustomerPermissionCatalog::IMPROVEMENT_CLOSE,
        CustomerPermissionCatalog::IMPROVEMENT_DELETE,
    ];

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
        Carbon::setTestNow();

        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Model and database
    // ---------------------------------------------------------------------

    public function test_a_new_case_is_open_whatever_the_form_says_and_records_who_reported_it(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $reporter = $this->editor($customer, $hr);
        $owner = $this->editor($customer, $hr);

        Carbon::setTestNow('2026-10-05 10:00:00');
        $response = $this->actingAs($reporter)->post('/app/improvements', [
            'occurred_at' => '2026-10-04',
            'due_date' => '2026-10-20',
            'status' => ImprovementCase::STATUS_CLOSED,
            'closed_at' => now()->toIso8601String(),
            'closing_note' => 'Smuglet inn',
            'reported_by_user_id' => $owner->id,
        ] + $this->payload($hr, $owner, 'Feil i lønnskjøring'));

        $case = ImprovementCase::query()->where('customer_id', $customer->id)->sole();
        $response->assertRedirect("/app/improvements/{$case->id}")->assertSessionHas('success', 'Saken er registrert.');

        $this->assertSame(ImprovementCase::STATUS_OPEN, $case->status);
        $this->assertNull($case->closed_at);
        $this->assertNull($case->closing_note);
        $this->assertSame(ImprovementCase::TYPE_DEVIATION, $case->type);
        $this->assertSame('2026-10-04', $case->occurred_at->format('Y-m-d'));
        $this->assertSame('2026-10-20', $case->due_date->format('Y-m-d'));
        $this->assertSame((int) $owner->id, (int) $case->owner_user_id);
        $this->assertSame((int) $reporter->id, (int) $case->reported_by_user_id);
        $this->assertSame((int) $reporter->id, (int) $case->created_by);
        $this->assertSame(0, ImprovementCaseStatusChange::query()->where('improvement_case_id', $case->id)->count());

        $props = $this->actingAs($reporter)->get("/app/improvements/{$case->id}")->assertOk()->viewData('page')['props'];
        $this->assertSame($reporter->name, $props['case']['reported_by_name']);
        $this->assertSame('2026-10-04', $props['case']['occurred_at']);
        $this->assertSame([], $props['status_history']);
    }

    public function test_only_two_types_and_four_statuses_exist_in_the_model_and_the_database(): void
    {
        ['customer' => $customer] = $this->context();
        $case = $this->improvementCase($customer, $this->area($customer, 'HR'), 'Sak');

        $this->assertSame(['deviation', 'improvement'], ImprovementCase::TYPES);
        $this->assertSame(['open', 'in_progress', 'closed', 'cancelled'], ImprovementCase::STATUSES);

        foreach ([['type', 'observation'], ['status', 'done']] as [$column, $value]) {
            $fresh = $case->fresh();
            $fresh->{$column} = $value;

            try {
                $fresh->save();
                $this->fail("An unknown {$column} must not be saved.");
            } catch (DomainException) {
            }

            try {
                DB::transaction(fn () => DB::table('improvement_cases')->where('id', $case->id)->update([$column => $value]));
                $this->fail("The database must refuse an unknown {$column}.");
            } catch (QueryException) {
            }
        }

        $this->assertSame(ImprovementCase::STATUS_OPEN, $case->fresh()->status);
    }

    public function test_the_database_keeps_the_ending_in_step_with_status(): void
    {
        ['customer' => $customer] = $this->context();
        $case = $this->improvementCase($customer, $this->area($customer, 'HR'), 'Sak');

        foreach ([
            // Ended without an ending.
            ['status' => ImprovementCase::STATUS_CLOSED],
            // Ended without saying why.
            ['status' => ImprovementCase::STATUS_CANCELLED, 'closed_at' => now(), 'closing_note' => '   '],
            // Open with an ending.
            ['closed_at' => now()],
            ['closing_note' => 'Ferdig'],
        ] as $attributes) {
            try {
                DB::transaction(fn () => DB::table('improvement_cases')->where('id', $case->id)->update($attributes));
                $this->fail('Refused: '.json_encode($attributes));
            } catch (QueryException) {
            }
        }

        $this->assertSame(ImprovementCase::STATUS_OPEN, $case->fresh()->status);
    }

    public function test_status_and_the_ending_are_not_mass_assignable(): void
    {
        foreach (['status', 'closed_at', 'closed_by_user_id', 'closing_note'] as $attribute) {
            $this->assertNotContains($attribute, (new ImprovementCase)->getFillable());
        }
    }

    public function test_a_forbedring_has_no_hendelsesdato(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);

        $this->actingAs($user)->post('/app/improvements', ['occurred_at' => '2026-01-01'] + $this->payload($hr, $user, 'Bedre onboarding', ImprovementCase::TYPE_IMPROVEMENT))
            ->assertSessionHasNoErrors();

        $case = ImprovementCase::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame(ImprovementCase::TYPE_IMPROVEMENT, $case->type);
        $this->assertNull($case->occurred_at);

        $this->expectException(QueryException::class);
        DB::table('improvement_cases')->where('id', $case->id)->update(['occurred_at' => '2026-01-01']);
    }

    public function test_an_owner_who_is_deleted_leaves_the_case_without_one(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $owner = $this->member($customer);
        $reader = $this->member($customer);
        $this->grant($customer, $owner, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$hr]);
        $this->grant($customer, $reader, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$hr]);
        $case = $this->improvementCase($customer, $hr, 'Sak', $owner);

        $owner->delete();

        $this->assertNull($case->fresh()->owner_user_id);
        $props = $this->actingAs($reader)->get("/app/improvements/{$case->id}")->assertOk()->viewData('page')['props'];
        $this->assertNull($props['case']['owner_name']);
    }

    // ---------------------------------------------------------------------
    // Tenant boundary
    // ---------------------------------------------------------------------

    public function test_another_customers_case_can_never_be_read_or_changed(): void
    {
        ['customer' => $customer] = $this->context();
        ['customer' => $other] = $this->context();
        $mine = $this->area($customer, 'HR');
        $theirs = $this->improvementCase($other, $this->area($other, 'HR'), 'Andres avvik');

        $user = $this->member($customer);
        $this->grantAll($customer, $user, self::ALL);

        $props = $this->actingAs($user)->get('/app/improvements?search=andres')->assertOk()->viewData('page')['props'];
        $this->assertSame([], $props['cases']);
        $this->assertSame(0, $props['visible_count']);

        $this->actingAs($user)->get("/app/improvements/{$theirs->id}")->assertNotFound();
        $this->actingAs($user)->patch("/app/improvements/{$theirs->id}", $this->payload($mine, $user))->assertNotFound();
        $this->actingAs($user)->post("/app/improvements/{$theirs->id}/start")->assertNotFound();
        $this->actingAs($user)->post("/app/improvements/{$theirs->id}/close", ['closing_note' => 'Kapret'])->assertNotFound();
        $this->actingAs($user)->post("/app/improvements/{$theirs->id}/cancel", ['reason' => 'Kapret'])->assertNotFound();
        $this->actingAs($user)->delete("/app/improvements/{$theirs->id}")->assertNotFound();

        $fresh = $theirs->fresh();
        $this->assertSame('Andres avvik', $fresh->title);
        $this->assertSame(ImprovementCase::STATUS_OPEN, $fresh->status);
        $this->assertSame(0, ImprovementCaseStatusChange::query()->where('improvement_case_id', $theirs->id)->count());
    }

    public function test_an_owner_or_area_from_another_customer_cannot_be_used(): void
    {
        ['customer' => $customer] = $this->context();
        ['customer' => $other] = $this->context();
        $hr = $this->area($customer, 'HR');
        $foreignArea = $this->area($other, 'HR');
        $user = $this->member($customer);
        $foreigner = $this->member($other);
        $this->grantAll($customer, $user, self::ALL);
        $this->grantAll($other, $foreigner, self::ALL);

        $this->actingAs($user)->post('/app/improvements', $this->payload($hr, $foreigner))
            ->assertSessionHasErrors(['owner_user_id' => 'Ansvarlig må være en aktiv bruker som kan se saker i valgt fagområde.']);

        $this->actingAs($user)->post('/app/improvements', $this->payload($foreignArea, $user))
            ->assertSessionHasErrors(['business_area_id' => 'Du kan ikke legge saker i dette fagområdet.']);

        $this->assertSame(0, ImprovementCase::query()->whereIn('customer_id', [$customer->id, $other->id])->count());
    }

    // ---------------------------------------------------------------------
    // Fagområde and roles
    // ---------------------------------------------------------------------

    public function test_view_in_the_right_area_reads_and_the_wrong_area_is_absent(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $finance = $this->area($customer, 'Økonomi');
        $visible = $this->improvementCase($customer, $hr, 'Feil i timeregistrering');
        $hidden = $this->improvementCase($customer, $finance, 'Feil i fakturering');

        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$hr]);

        $props = $this->actingAs($user)->get('/app/improvements')->assertOk()->viewData('page')['props'];
        $this->assertSame([$visible->id], array_column($props['cases'], 'id'));
        $this->assertSame(1, $props['visible_count']);
        $this->assertFalse($props['permissions']['can_create']);
        // A single readable area: no area filter to offer.
        $this->assertCount(1, $props['filter_area_options']);

        $props = $this->actingAs($user)->get("/app/improvements/{$visible->id}")->assertOk()->viewData('page')['props'];
        foreach (['can_edit', 'can_start', 'can_close', 'can_cancel', 'can_reopen', 'can_delete'] as $permission) {
            $this->assertFalse($props['permissions'][$permission], $permission);
        }
        $this->assertSame([], $props['area_options']);
        $this->assertSame([], $props['owner_options']);

        $this->actingAs($user)->get("/app/improvements/{$hidden->id}")->assertNotFound();
        $this->actingAs($user)->get('/app/improvements/999999999')->assertNotFound();
    }

    public function test_permission_and_area_from_different_roles_are_not_combined(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $finance = $this->area($customer, 'Økonomi');
        $hrCase = $this->improvementCase($customer, $hr, 'HR-sak');
        $financeCase = $this->improvementCase($customer, $finance, 'Økonomisak');

        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$hr]);
        $this->grant($customer, $user, [CustomerPermissionCatalog::IMPROVEMENT_EDIT, CustomerPermissionCatalog::IMPROVEMENT_CLOSE, CustomerPermissionCatalog::IMPROVEMENT_DELETE], [$finance]);

        // Edit, close and delete in Økonomi do not reach HR, where the user only reads.
        $this->actingAs($user)->patch("/app/improvements/{$hrCase->id}", $this->payload($hr, $user, 'Endret'))->assertForbidden();
        $this->actingAs($user)->post("/app/improvements/{$hrCase->id}/start")->assertForbidden();
        $this->actingAs($user)->post("/app/improvements/{$hrCase->id}/close", ['closing_note' => 'Ferdig'])->assertForbidden();
        $this->actingAs($user)->delete("/app/improvements/{$hrCase->id}")->assertForbidden();

        // View in HR does not reach Økonomi, where the user only edits.
        $this->actingAs($user)->get("/app/improvements/{$financeCase->id}")->assertNotFound();
        $this->actingAs($user)->post("/app/improvements/{$financeCase->id}/start")->assertNotFound();

        $this->assertSame('HR-sak', $hrCase->fresh()->title);
        $this->assertSame(ImprovementCase::STATUS_OPEN, $hrCase->fresh()->status);
        $this->assertSame(ImprovementCase::STATUS_OPEN, $financeCase->fresh()->status);
    }

    public function test_alle_reaches_every_area_including_one_created_later(): void
    {
        ['customer' => $customer] = $this->context();
        ['customer' => $other] = $this->context();
        $hr = $this->area($customer, 'HR');
        $this->improvementCase($customer, $hr, 'Første sak');
        $this->improvementCase($other, $this->area($other, 'HR'), 'Fremmed sak');

        $user = $this->member($customer);
        $this->grantAll($customer, $user, [CustomerPermissionCatalog::IMPROVEMENT_VIEW]);

        Carbon::setTestNow(now()->addMinute());
        $later = $this->area($customer, 'Beredskap');
        $laterCase = $this->improvementCase($customer, $later, 'Senere sak');

        $props = $this->actingAs($user)->get('/app/improvements')->assertOk()->viewData('page')['props'];
        // Both open, no frist: the newest first.
        $this->assertSame(['Senere sak', 'Første sak'], array_column($props['cases'], 'title'));
        $this->assertSame(2, $props['visible_count']);
        $this->assertCount(2, $props['filter_area_options']);
        $this->actingAs($user)->get("/app/improvements/{$laterCase->id}")->assertOk();
    }

    public function test_an_inactive_role_reaches_nothing(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $case = $this->improvementCase($customer, $hr, 'Sak');
        $user = $this->member($customer);
        $role = $this->grant($customer, $user, self::ALL, [$hr]);

        $role->forceFill(['is_active' => false])->save();

        $this->actingAs($user)->get('/app/improvements')->assertForbidden();
        $this->actingAs($user)->get("/app/improvements/{$case->id}")->assertForbidden();
        $this->actingAs($user)->post('/app/improvements', $this->payload($hr, $user))->assertForbidden();
    }

    public function test_without_improvement_view_everything_is_forbidden_before_any_id_is_looked_at(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $case = $this->improvementCase($customer, $hr, 'Sak');
        $user = $this->member($customer);
        // Rights in other area-scoped modules, in the same area, are no improvement rights.
        $this->grant($customer, $user, [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_EDIT, CustomerPermissionCatalog::RISK_VIEW], [$hr]);

        $this->actingAs($user)->get('/app/improvements')->assertForbidden();
        $this->actingAs($user)->get("/app/improvements/{$case->id}")->assertForbidden();
        $this->actingAs($user)->get('/app/improvements/999999999')->assertForbidden();
        $this->actingAs($user)->post("/app/improvements/{$case->id}/start")->assertForbidden();
        $this->actingAs($user)->delete("/app/improvements/{$case->id}")->assertForbidden();
    }

    public function test_the_module_comes_with_every_step_of_the_ladder_and_not_without_one(): void
    {
        foreach (['basis' => true, 'governance' => true, 'iso' => true, 'grc' => true, 'tender' => false, null => false] as $package => $reachable) {
            ['customer' => $customer] = $this->context($package === '' ? null : $package);
            $hr = $this->area($customer, 'HR');
            $user = $this->member($customer);
            $this->grant($customer, $user, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$hr]);

            $response = $this->actingAs($user)->get('/app/improvements');
            $reachable ? $response->assertOk() : $response->assertRedirect();
        }
    }

    public function test_system_owner_reaches_the_module_but_reads_no_case_without_a_role(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $hr = $this->area($customer, 'HR');
        $case = $this->improvementCase($customer, $hr, 'Hemmelig avvik');

        $props = $this->actingAs($owner)->get('/app/improvements?search=hemmelig')->assertOk()->viewData('page')['props'];
        $this->assertSame([], $props['cases']);
        $this->assertSame(0, $props['visible_count']);
        $this->assertFalse($props['has_areas']);
        $this->assertFalse($props['permissions']['can_create']);
        $this->assertTrue($props['access_setup']['customer_has_areas']);
        $this->assertStringEndsWith('?tab=permissions#business-areas', $props['access_setup']['manage_url']);

        $this->actingAs($owner)->get("/app/improvements/{$case->id}")->assertNotFound();
        $this->actingAs($owner)->post("/app/improvements/{$case->id}/close", ['closing_note' => 'Ferdig'])->assertNotFound();
        $this->actingAs($owner)->delete("/app/improvements/{$case->id}")->assertNotFound();
        $this->actingAs($owner)->post('/app/improvements', $this->payload($hr, $owner))->assertSessionHasErrors('business_area_id');

        // Through an explicit role, like anyone else.
        $this->grant($customer, $owner, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$hr]);
        $this->actingAs($owner)->get("/app/improvements/{$case->id}")->assertOk();
    }

    // ---------------------------------------------------------------------
    // Register
    // ---------------------------------------------------------------------

    public function test_search_filters_and_counts_stay_inside_the_authorised_set(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $it = $this->area($customer, 'IT');
        $finance = $this->area($customer, 'Økonomi');
        $handler = $this->handler($customer, $hr);
        $this->grant($customer, $handler, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$it]);

        $hrDeviation = $this->improvementCase($customer, $hr, 'Feil i lønn');
        $hrImprovement = $this->improvementCase($customer, $hr, 'Bedre lønnsrutine', null, ImprovementCase::TYPE_IMPROVEMENT);
        $itDeviation = $this->improvementCase($customer, $it, 'Feil i backup');
        $this->improvementCase($customer, $finance, 'Feil i lønnsbudsjett');
        $this->actingAs($handler)->post("/app/improvements/{$hrDeviation->id}/start");

        $titles = fn (string $query): array => array_column(
            $this->actingAs($handler)->get('/app/improvements'.$query)->assertOk()->viewData('page')['props']['cases'],
            'title',
        );

        // «lønn» also matches the hidden Økonomi case, which must not appear.
        $this->assertEqualsCanonicalizing(['Feil i lønn', 'Bedre lønnsrutine'], $titles('?search=L%C3%98NN'));
        $this->assertEqualsCanonicalizing(['Bedre lønnsrutine'], $titles('?type=improvement'));
        $this->assertEqualsCanonicalizing(['Feil i lønn'], $titles('?status=in_progress'));
        $this->assertEqualsCanonicalizing(['Feil i backup'], $titles("?area={$it->id}"));
        // An area outside the user's reach is ignored, not answered.
        $this->assertEqualsCanonicalizing(['Feil i lønn', 'Bedre lønnsrutine', 'Feil i backup'], $titles("?area={$finance->id}"));
        $this->assertEqualsCanonicalizing(['Feil i lønn', 'Bedre lønnsrutine', 'Feil i backup'], $titles('?type=observation&status=done'));
        $this->assertSame([], $titles('?search=budsjett'));

        $props = $this->actingAs($handler)->get('/app/improvements?search=backup')->assertOk()->viewData('page')['props'];
        // The count is of the visible set, never of the whole customer.
        $this->assertSame(3, $props['visible_count']);
        $this->assertSame(['HR', 'IT'], array_column($props['filter_area_options'], 'name'));
        // Registering is offered only where the user may edit.
        $this->assertSame(['HR'], array_column($props['area_options'], 'name'));
        $this->assertNotNull($itDeviation->id);
        $this->assertNotNull($hrImprovement->id);
    }

    public function test_the_register_puts_open_first_then_in_progress_then_ended_with_the_nearest_frist_first(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $handler = $this->handler($customer, $hr);

        Carbon::setTestNow('2026-10-01 08:00:00');
        $make = function (string $title, ?string $due) use ($customer, $hr): ImprovementCase {
            Carbon::setTestNow(now()->addMinute());
            $case = $this->improvementCase($customer, $hr, $title);
            $case->forceFill(['due_date' => $due])->save();

            return $case;
        };

        $closed = $make('Lukket', '2026-10-02');
        $openLate = $make('Åpen sen frist', '2026-12-01');
        $openNone = $make('Åpen uten frist', null);
        $openSoon = $make('Åpen snart', '2026-10-03');
        $progress = $make('Under arbeid', '2026-10-02');
        $cancelled = $make('Avbrutt', null);

        $this->actingAs($handler)->post("/app/improvements/{$progress->id}/start");
        $this->actingAs($handler)->post("/app/improvements/{$closed->id}/close", ['closing_note' => 'Ferdig']);
        $this->actingAs($handler)->post("/app/improvements/{$cancelled->id}/cancel", ['reason' => 'Duplikat']);

        Carbon::setTestNow('2026-10-04 08:00:00');
        $cases = $this->actingAs($handler)->get('/app/improvements')->assertOk()->viewData('page')['props']['cases'];

        $this->assertSame(
            ['Åpen snart', 'Åpen sen frist', 'Åpen uten frist', 'Under arbeid', 'Avbrutt', 'Lukket'],
            array_column($cases, 'title'),
        );
        // Frist passert only while the case is still being worked.
        $this->assertSame(
            ['Åpen snart' => true, 'Åpen sen frist' => false, 'Åpen uten frist' => false, 'Under arbeid' => true, 'Avbrutt' => false, 'Lukket' => false],
            array_combine(array_column($cases, 'title'), array_column($cases, 'is_overdue')),
        );
        $this->assertNotNull($openLate->id + $openNone->id + $openSoon->id);
    }

    // ---------------------------------------------------------------------
    // Edit and move
    // ---------------------------------------------------------------------

    public function test_edit_changes_the_case_but_never_its_status(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $colleague = $this->member($customer);
        $this->grant($customer, $colleague, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$hr]);
        $case = $this->improvementCase($customer, $hr, 'Sak', $user);

        $props = $this->actingAs($user)->get("/app/improvements/{$case->id}")->assertOk()->viewData('page')['props'];
        $this->assertTrue($props['permissions']['can_edit']);
        $this->assertTrue($props['permissions']['can_start']);
        $this->assertFalse($props['permissions']['can_close']);
        $this->assertEqualsCanonicalizing([$user->name, $colleague->name], array_column($props['owner_options'], 'name'));

        $this->actingAs($user)->patch("/app/improvements/{$case->id}", [
            'type' => ImprovementCase::TYPE_IMPROVEMENT,
            'title' => '  Bedre rutine  ',
            'description' => 'Ny beskrivelse',
            'business_area_id' => $hr->id,
            'owner_user_id' => $colleague->id,
            'occurred_at' => '2026-01-01',
            'due_date' => '2026-11-30',
            'status' => ImprovementCase::STATUS_CLOSED,
        ])->assertRedirect()->assertSessionHas('success', 'Saken er oppdatert.');

        $case->refresh();
        $this->assertSame('Bedre rutine', $case->title);
        $this->assertSame(ImprovementCase::TYPE_IMPROVEMENT, $case->type);
        // Became a forbedring: no Hendelsesdato.
        $this->assertNull($case->occurred_at);
        $this->assertSame('2026-11-30', $case->due_date->format('Y-m-d'));
        $this->assertSame((int) $colleague->id, (int) $case->owner_user_id);
        $this->assertSame(ImprovementCase::STATUS_OPEN, $case->status);
        $this->assertSame((int) $user->id, (int) $case->updated_by);
    }

    public function test_view_without_edit_cannot_register_change_or_start(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $reader = $this->member($customer);
        $this->grant($customer, $reader, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$hr]);
        $case = $this->improvementCase($customer, $hr, 'Sak', $reader);

        $this->actingAs($reader)->post('/app/improvements', $this->payload($hr, $reader))
            ->assertSessionHasErrors(['business_area_id' => 'Du kan ikke legge saker i dette fagområdet.']);
        $this->actingAs($reader)->patch("/app/improvements/{$case->id}", $this->payload($hr, $reader, 'Endret'))->assertForbidden();
        $this->actingAs($reader)->post("/app/improvements/{$case->id}/start")->assertForbidden();
        $this->actingAs($reader)->post("/app/improvements/{$case->id}/close", ['closing_note' => 'Ferdig'])->assertForbidden();
        $this->actingAs($reader)->post("/app/improvements/{$case->id}/cancel", ['reason' => 'Nei'])->assertForbidden();
        $this->actingAs($reader)->delete("/app/improvements/{$case->id}")->assertForbidden();

        $this->assertSame(1, ImprovementCase::query()->where('customer_id', $customer->id)->count());
        $this->assertSame('Sak', $case->fresh()->title);
        $this->assertSame(ImprovementCase::STATUS_OPEN, $case->fresh()->status);
    }

    public function test_moving_takes_edit_in_both_the_old_and_the_new_area(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $it = $this->area($customer, 'IT');
        $finance = $this->area($customer, 'Økonomi');
        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::IMPROVEMENT_EDIT], [$hr, $it]);
        $this->grant($customer, $user, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$finance]);
        $case = $this->improvementCase($customer, $hr, 'Sak', $user);

        // Into an area where the user only reads: refused on the area field.
        $this->actingAs($user)->patch("/app/improvements/{$case->id}", $this->payload($finance, $user, 'Flyttet'))
            ->assertSessionHasErrors(['business_area_id' => 'Du kan ikke legge saker i dette fagområdet.']);
        $this->assertSame((int) $hr->id, (int) $case->fresh()->business_area_id);

        // Into another area the user edits in: allowed.
        $this->actingAs($user)->patch("/app/improvements/{$case->id}", $this->payload($it, $user, 'Flyttet'))->assertSessionHasNoErrors();
        $this->assertSame((int) $it->id, (int) $case->fresh()->business_area_id);

        // Out of an area where the user only reads: forbidden.
        $readOnly = $this->improvementCase($customer, $finance, 'Leses bare', $user);
        $this->actingAs($user)->patch("/app/improvements/{$readOnly->id}", $this->payload($hr, $user))->assertForbidden();
        $this->assertSame((int) $finance->id, (int) $readOnly->fresh()->business_area_id);
    }

    public function test_a_move_is_refused_when_the_owner_cannot_see_the_new_area(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $it = $this->area($customer, 'IT');
        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::IMPROVEMENT_EDIT], [$hr, $it]);
        $owner = $this->member($customer);
        $this->grant($customer, $owner, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$hr]);
        $case = $this->improvementCase($customer, $hr, 'Sak', $owner);

        $this->actingAs($user)->patch("/app/improvements/{$case->id}", $this->payload($it, $owner))
            ->assertSessionHasErrors(['owner_user_id' => 'Ansvarlig må være en aktiv bruker som kan se saker i valgt fagområde.']);
        $this->assertSame((int) $hr->id, (int) $case->fresh()->business_area_id);
    }

    public function test_the_owner_must_be_active_and_able_to_read_the_area(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $it = $this->area($customer, 'IT');
        $user = $this->editor($customer, $hr);
        $message = 'Ansvarlig må være en aktiv bruker som kan se saker i valgt fagområde.';

        $inactive = $this->member($customer);
        $this->grant($customer, $inactive, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$hr]);
        $inactive->forceFill(['is_active' => false])->save();

        $otherArea = $this->member($customer);
        $this->grant($customer, $otherArea, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$it]);

        // Edit in the area without view there is not enough either: the owner must read the case.
        $noView = $this->member($customer);
        $this->grant($customer, $noView, [CustomerPermissionCatalog::OBJECTIVE_VIEW], [$hr]);

        foreach ([$inactive, $otherArea, $noView] as $candidate) {
            $this->actingAs($user)->post('/app/improvements', $this->payload($hr, $candidate))->assertSessionHasErrors(['owner_user_id' => $message]);
        }

        $props = $this->actingAs($user)->get('/app/improvements')->assertOk()->viewData('page')['props'];
        $this->assertSame([$user->name], array_column($props['owner_options'], 'name'));
        $this->assertSame(0, ImprovementCase::query()->where('customer_id', $customer->id)->count());
    }

    public function test_validation_messages_are_norwegian_and_on_the_right_field(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);

        $this->actingAs($user)->post('/app/improvements', [])->assertSessionHasErrors([
            'type' => 'Type må fylles ut.',
            'title' => 'Tittel må fylles ut.',
            'description' => 'Beskrivelse må fylles ut.',
            'business_area_id' => 'Fagområde må fylles ut.',
            'owner_user_id' => 'Ansvarlig må fylles ut.',
        ]);

        $this->actingAs($user)->post('/app/improvements', ['type' => 'observation'] + $this->payload($hr, $user))
            ->assertSessionHasErrors(['type' => 'Velg en gyldig verdi for Type.']);

        Carbon::setTestNow('2026-10-05 12:00:00');
        $this->actingAs($user)->post('/app/improvements', ['occurred_at' => '2026-10-06'] + $this->payload($hr, $user))
            ->assertSessionHasErrors(['occurred_at' => 'Hendelsesdato kan ikke være frem i tid.']);
        $this->actingAs($user)->post('/app/improvements', ['due_date' => '05.10.2026'] + $this->payload($hr, $user))
            ->assertSessionHasErrors(['due_date' => 'Frist må være en gyldig dato.']);

        $this->assertSame(0, ImprovementCase::query()->where('customer_id', $customer->id)->count());

        // Today is not the future.
        $this->actingAs($user)->post('/app/improvements', ['occurred_at' => '2026-10-05'] + $this->payload($hr, $user))->assertSessionHasNoErrors();
    }

    // ---------------------------------------------------------------------
    // Lifecycle
    // ---------------------------------------------------------------------

    public function test_start_behandling_moves_an_open_case_into_progress_without_a_note(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $case = $this->improvementCase($customer, $hr, 'Sak', $user);

        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->actingAs($user)->post("/app/improvements/{$case->id}/start")
            ->assertRedirect()->assertSessionHas('success', 'Behandlingen er startet.');

        $case->refresh();
        $this->assertSame(ImprovementCase::STATUS_IN_PROGRESS, $case->status);
        $this->assertNull($case->closed_at);

        $change = ImprovementCaseStatusChange::query()->where('improvement_case_id', $case->id)->sole();
        $this->assertSame([(int) $customer->id, 'open', 'in_progress', null, (int) $user->id, '2026-10-05 10:00:00'], [
            (int) $change->customer_id, $change->from_status, $change->to_status, $change->note, (int) $change->changed_by_user_id, $change->changed_at->format('Y-m-d H:i:s'),
        ]);

        $props = $this->actingAs($user)->get("/app/improvements/{$case->id}")->assertOk()->viewData('page')['props'];
        $this->assertTrue($props['permissions']['can_edit']);
        $this->assertFalse($props['permissions']['can_start']);
        $this->assertCount(1, $props['status_history']);
    }

    public function test_closing_from_open_or_in_progress_requires_a_result(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->handler($customer, $hr);
        $fromOpen = $this->improvementCase($customer, $hr, 'Rett til lukking', $user);
        $fromProgress = $this->improvementCase($customer, $hr, 'Behandlet', $user);
        $this->actingAs($user)->post("/app/improvements/{$fromProgress->id}/start");

        foreach (['', '   '] as $note) {
            $this->actingAs($user)->post("/app/improvements/{$fromOpen->id}/close", ['closing_note' => $note])
                ->assertSessionHasErrors(['closing_note' => 'Resultat må fylles ut.']);
        }
        $this->assertSame(ImprovementCase::STATUS_OPEN, $fromOpen->fresh()->status);

        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->actingAs($user)->post("/app/improvements/{$fromOpen->id}/close", ['closing_note' => '  Rutinen er rettet.  '])
            ->assertRedirect()->assertSessionHas('success', 'Saken er lukket.');
        $this->actingAs($user)->post("/app/improvements/{$fromProgress->id}/close", ['closing_note' => 'Opplæring gjennomført.'])->assertSessionHasNoErrors();

        $fromOpen->refresh();
        $this->assertSame(ImprovementCase::STATUS_CLOSED, $fromOpen->status);
        $this->assertSame('Rutinen er rettet.', $fromOpen->closing_note);
        $this->assertSame('2026-10-05 10:00:00', $fromOpen->closed_at->format('Y-m-d H:i:s'));
        $this->assertSame((int) $user->id, (int) $fromOpen->closed_by_user_id);
        $this->assertSame(['open' => 'closed'], ImprovementCaseStatusChange::query()->where('improvement_case_id', $fromOpen->id)->pluck('to_status', 'from_status')->all());
        $this->assertSame(['in_progress', 'closed'], ImprovementCaseStatusChange::query()->where('improvement_case_id', $fromProgress->id)->orderBy('id')->pluck('to_status')->all());

        $props = $this->actingAs($user)->get("/app/improvements/{$fromOpen->id}")->assertOk()->viewData('page')['props'];
        $this->assertSame('Rutinen er rettet.', $props['case']['closing_note']);
        $this->assertSame($user->name, $props['case']['closed_by_name']);
        $this->assertSame(['can_edit' => false, 'can_start' => false, 'can_close' => false, 'can_cancel' => false, 'can_reopen' => true], array_intersect_key(
            $props['permissions'], array_flip(['can_edit', 'can_start', 'can_close', 'can_cancel', 'can_reopen']),
        ));
    }

    public function test_cancelling_requires_a_reason(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->handler($customer, $hr);
        $case = $this->improvementCase($customer, $hr, 'Duplikat', $user);
        $this->actingAs($user)->post("/app/improvements/{$case->id}/start");

        $this->actingAs($user)->post("/app/improvements/{$case->id}/cancel", ['reason' => ' '])
            ->assertSessionHasErrors(['reason' => 'Begrunnelse må fylles ut.']);
        $this->assertSame(ImprovementCase::STATUS_IN_PROGRESS, $case->fresh()->status);

        $this->actingAs($user)->post("/app/improvements/{$case->id}/cancel", ['reason' => 'Samme som sak 12.'])
            ->assertRedirect()->assertSessionHas('success', 'Saken er avbrutt.');

        $case->refresh();
        $this->assertSame(ImprovementCase::STATUS_CANCELLED, $case->status);
        $this->assertSame('Samme som sak 12.', $case->closing_note);
        $this->assertNotNull($case->closed_at);
        $this->assertSame(['in_progress', 'cancelled'], ImprovementCaseStatusChange::query()->where('improvement_case_id', $case->id)->orderBy('id')->pluck('to_status')->all());
    }

    public function test_reopening_requires_a_reason_clears_the_ending_and_the_history_survives_many_changes(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $closer = $this->handler($customer, $hr);
        $reopener = $this->handler($customer, $hr);
        $case = $this->improvementCase($customer, $hr, 'Sak', $closer);

        Carbon::setTestNow('2026-10-05 09:00:00');
        $this->actingAs($closer)->post("/app/improvements/{$case->id}/start");
        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->actingAs($closer)->post("/app/improvements/{$case->id}/close", ['closing_note' => 'Rettet.']);

        $this->actingAs($reopener)->post("/app/improvements/{$case->id}/reopen", ['reason' => ''])
            ->assertSessionHasErrors(['reason' => 'Begrunnelse må fylles ut.']);
        $this->assertSame(ImprovementCase::STATUS_CLOSED, $case->fresh()->status);

        Carbon::setTestNow('2026-10-12 09:30:00');
        $this->actingAs($reopener)->post("/app/improvements/{$case->id}/reopen", ['reason' => 'Feilen kom tilbake.'])
            ->assertRedirect()->assertSessionHas('success', 'Saken er gjenåpnet.');

        $case->refresh();
        $this->assertSame(ImprovementCase::STATUS_OPEN, $case->status);
        $this->assertNull($case->closed_at);
        $this->assertNull($case->closed_by_user_id);
        $this->assertNull($case->closing_note);
        $this->assertSame((int) $reopener->id, (int) $case->updated_by);

        // Avbryt, Gjenåpne again: every change stays, as it was made.
        Carbon::setTestNow('2026-10-13 09:00:00');
        $this->actingAs($reopener)->post("/app/improvements/{$case->id}/cancel", ['reason' => 'Flyttet til leverandør.']);
        Carbon::setTestNow('2026-10-14 09:00:00');
        $this->actingAs($closer)->post("/app/improvements/{$case->id}/reopen", ['reason' => 'Leverandøren svarte ikke.']);

        $history = ImprovementCaseStatusChange::query()->where('improvement_case_id', $case->id)->orderBy('id')->get();
        $this->assertSame([
            ['open', 'in_progress', null, (int) $closer->id, '2026-10-05 09:00:00'],
            ['in_progress', 'closed', 'Rettet.', (int) $closer->id, '2026-10-05 10:00:00'],
            ['closed', 'open', 'Feilen kom tilbake.', (int) $reopener->id, '2026-10-12 09:30:00'],
            ['open', 'cancelled', 'Flyttet til leverandør.', (int) $reopener->id, '2026-10-13 09:00:00'],
            ['cancelled', 'open', 'Leverandøren svarte ikke.', (int) $closer->id, '2026-10-14 09:00:00'],
        ], $history->map(fn (ImprovementCaseStatusChange $change): array => [
            $change->from_status, $change->to_status, $change->note, (int) $change->changed_by_user_id, $change->changed_at->format('Y-m-d H:i:s'),
        ])->all());

        // Newest first on the page; the case is open and can be started again.
        $props = $this->actingAs($reopener)->get("/app/improvements/{$case->id}")->assertOk()->viewData('page')['props'];
        $this->assertSame(['open', 'cancelled', 'open', 'closed', 'in_progress'], array_column($props['status_history'], 'to_status'));
        $this->assertTrue($props['permissions']['can_start']);
        $this->assertTrue($props['permissions']['can_edit']);
        $this->assertFalse($props['permissions']['can_reopen']);
    }

    public function test_a_change_the_case_is_not_in_a_state_for_is_refused_and_writes_nothing(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->handler($customer, $hr);
        $message = 'Saken har allerede fått en annen status. Last siden på nytt.';

        $open = $this->improvementCase($customer, $hr, 'Åpen', $user);
        $this->actingAs($user)->post("/app/improvements/{$open->id}/reopen", ['reason' => 'Igjen'])->assertSessionHasErrors(['reason' => $message]);

        $progress = $this->improvementCase($customer, $hr, 'Under arbeid', $user);
        $this->actingAs($user)->post("/app/improvements/{$progress->id}/start");
        // A double click on Start behandling.
        $this->actingAs($user)->post("/app/improvements/{$progress->id}/start")->assertSessionHasErrors(['status' => $message]);
        $this->actingAs($user)->post("/app/improvements/{$progress->id}/reopen", ['reason' => 'Igjen'])->assertSessionHasErrors(['reason' => $message]);

        $closed = $this->improvementCase($customer, $hr, 'Lukket', $user);
        $this->actingAs($user)->post("/app/improvements/{$closed->id}/close", ['closing_note' => 'Ferdig']);
        // Two people closing at once: the second is refused, the first closing stands.
        $this->actingAs($user)->post("/app/improvements/{$closed->id}/close", ['closing_note' => 'Også ferdig'])->assertSessionHasErrors(['closing_note' => $message]);
        $this->actingAs($user)->post("/app/improvements/{$closed->id}/cancel", ['reason' => 'Nei'])->assertSessionHasErrors(['reason' => $message]);
        $this->actingAs($user)->post("/app/improvements/{$closed->id}/start")->assertSessionHasErrors(['status' => $message]);

        $cancelled = $this->improvementCase($customer, $hr, 'Avbrutt', $user);
        $this->actingAs($user)->post("/app/improvements/{$cancelled->id}/cancel", ['reason' => 'Duplikat']);
        $this->actingAs($user)->post("/app/improvements/{$cancelled->id}/close", ['closing_note' => 'Ferdig'])->assertSessionHasErrors(['closing_note' => $message]);

        $this->assertSame(0, ImprovementCaseStatusChange::query()->where('improvement_case_id', $open->id)->count());
        $this->assertSame(1, ImprovementCaseStatusChange::query()->where('improvement_case_id', $progress->id)->count());
        $this->assertSame(1, ImprovementCaseStatusChange::query()->where('improvement_case_id', $closed->id)->count());
        $this->assertSame('Ferdig', $closed->fresh()->closing_note);
        $this->assertSame(1, ImprovementCaseStatusChange::query()->where('improvement_case_id', $cancelled->id)->count());
    }

    public function test_every_change_locks_the_case_row_before_it_reads_the_status(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->handler($customer, $hr);
        $case = $this->improvementCase($customer, $hr, 'Sak', $user);

        $locks = 0;
        DB::listen(function ($query) use (&$locks): void {
            if (str_contains($query->sql, 'from "improvement_cases"') && str_contains($query->sql, 'for update')) {
                $locks++;
            }
        });

        $this->actingAs($user)->post("/app/improvements/{$case->id}/start");
        $this->actingAs($user)->post("/app/improvements/{$case->id}/close", ['closing_note' => 'Ferdig']);
        $this->actingAs($user)->post("/app/improvements/{$case->id}/reopen", ['reason' => 'Igjen']);
        $this->actingAs($user)->post("/app/improvements/{$case->id}/cancel", ['reason' => 'Duplikat']);

        $this->assertSame(4, $locks);
        $this->assertSame(4, ImprovementCaseStatusChange::query()->where('improvement_case_id', $case->id)->count());
    }

    public function test_an_ended_case_is_reopened_before_it_is_edited(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->handler($customer, $hr);
        $case = $this->improvementCase($customer, $hr, 'Sak', $user);
        $this->actingAs($user)->post("/app/improvements/{$case->id}/close", ['closing_note' => 'Ferdig']);

        $this->actingAs($user)->patch("/app/improvements/{$case->id}", $this->payload($hr, $user, 'Endret etter lukking'))
            ->assertRedirect()->assertSessionHas('error', 'Gjenåpne saken før du endrer den.');
        $this->assertSame('Sak', $case->fresh()->title);

        $props = $this->actingAs($user)->get("/app/improvements/{$case->id}")->assertOk()->viewData('page')['props'];
        $this->assertFalse($props['permissions']['can_edit']);
        $this->assertSame([], $props['area_options']);
    }

    public function test_starting_takes_edit_and_ending_or_reopening_takes_close_in_the_cases_own_area(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $it = $this->area($customer, 'IT');
        $case = $this->improvementCase($customer, $hr, 'Sak');

        $editor = $this->editor($customer, $hr);
        $closer = $this->member($customer);
        $this->grant($customer, $closer, [CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::IMPROVEMENT_CLOSE], [$hr]);
        // Close in another area reaches nothing here.
        $this->grant($customer, $editor, [CustomerPermissionCatalog::IMPROVEMENT_CLOSE], [$it]);

        $props = $this->actingAs($closer)->get("/app/improvements/{$case->id}")->assertOk()->viewData('page')['props'];
        $this->assertSame(['can_edit' => false, 'can_start' => false, 'can_close' => true, 'can_cancel' => true], array_intersect_key(
            $props['permissions'], array_flip(['can_edit', 'can_start', 'can_close', 'can_cancel']),
        ));

        $this->actingAs($closer)->post("/app/improvements/{$case->id}/start")->assertForbidden();
        $this->actingAs($closer)->patch("/app/improvements/{$case->id}", $this->payload($hr, $closer))->assertForbidden();
        $this->actingAs($editor)->post("/app/improvements/{$case->id}/start")->assertSessionHasNoErrors();
        $this->actingAs($editor)->post("/app/improvements/{$case->id}/close", ['closing_note' => 'Ferdig'])->assertForbidden();
        $this->actingAs($editor)->post("/app/improvements/{$case->id}/cancel", ['reason' => 'Nei'])->assertForbidden();
        $this->actingAs($closer)->post("/app/improvements/{$case->id}/close", ['closing_note' => 'Ferdig'])->assertSessionHasNoErrors();
        $this->actingAs($editor)->post("/app/improvements/{$case->id}/reopen", ['reason' => 'Igjen'])->assertForbidden();
        $this->actingAs($closer)->post("/app/improvements/{$case->id}/reopen", ['reason' => 'Igjen'])->assertSessionHasNoErrors();

        $this->assertSame(['in_progress', 'closed', 'open'], ImprovementCaseStatusChange::query()->where('improvement_case_id', $case->id)->orderBy('id')->pluck('to_status')->all());
    }

    // ---------------------------------------------------------------------
    // History
    // ---------------------------------------------------------------------

    public function test_history_cannot_be_changed_or_deleted_by_the_application_or_the_database(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->handler($customer, $hr);
        $case = $this->improvementCase($customer, $hr, 'Sak', $user);
        $this->actingAs($user)->post("/app/improvements/{$case->id}/close", ['closing_note' => 'Original']);
        $change = ImprovementCaseStatusChange::query()->where('improvement_case_id', $case->id)->sole();

        foreach ([fn () => $change->update(['note' => 'Omskrevet']), fn () => $change->delete()] as $write) {
            try {
                $write();
                $this->fail('A history row must not be changed or deleted.');
            } catch (LogicException) {
            }
        }

        // Below the model too: the trigger refuses every column, including handing the row to
        // someone else.
        $someoneElse = $this->member($customer);
        foreach (['note' => 'Omskrevet', 'to_status' => 'cancelled', 'changed_at' => now()->subYear(), 'changed_by_user_id' => $someoneElse->id] as $column => $value) {
            try {
                DB::transaction(fn () => DB::table('improvement_case_status_changes')->where('id', $change->id)->update([$column => $value]));
                $this->fail("The database must refuse changing {$column}.");
            } catch (QueryException) {
            }
        }

        $this->assertSame('Original', $change->fresh()->note);

        // No route edits or removes history; only the lifecycle writes it.
        $routes = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route): string => (string) $route->getName())
            ->filter(fn (string $name): bool => str_starts_with($name, 'app.improvements.'))
            ->sort()
            ->values()
            ->all();
        $this->assertSame([
            'app.improvements.actions.cancel', 'app.improvements.actions.complete', 'app.improvements.actions.destroy',
            'app.improvements.actions.reopen', 'app.improvements.actions.start', 'app.improvements.actions.store',
            'app.improvements.actions.update', 'app.improvements.actions.verify',
            'app.improvements.cancel', 'app.improvements.cause.update', 'app.improvements.close', 'app.improvements.context.update',
            'app.improvements.destroy', 'app.improvements.index', 'app.improvements.reopen', 'app.improvements.show',
            'app.improvements.start', 'app.improvements.store', 'app.improvements.update',
        ], $routes);
    }

    public function test_a_deleted_author_leaves_the_history_row_in_place(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->handler($customer, $hr);
        $case = $this->improvementCase($customer, $hr, 'Sak');
        $this->actingAs($user)->post("/app/improvements/{$case->id}/close", ['closing_note' => 'Ferdig']);

        // The foreign key nulls the author: the one change the trigger lets through.
        $user->delete();

        $change = ImprovementCaseStatusChange::query()->where('improvement_case_id', $case->id)->sole();
        $this->assertNull($change->changed_by_user_id);
        $this->assertSame('Ferdig', $change->note);
        $this->assertNull($case->fresh()->closed_by_user_id);
    }

    public function test_the_database_refuses_impossible_history_rows(): void
    {
        ['customer' => $customer] = $this->context();
        $case = $this->improvementCase($customer, $this->area($customer, 'HR'), 'Sak');
        $row = fn (string $from, string $to, ?string $note): array => [
            'customer_id' => $customer->id, 'improvement_case_id' => $case->id, 'from_status' => $from,
            'to_status' => $to, 'note' => $note, 'changed_at' => now(),
        ];

        foreach ([
            $row('closed', 'open', null),
            $row('cancelled', 'open', '  '),
            $row('open', 'closed', null),
            $row('in_progress', 'cancelled', ''),
            $row('in_progress', 'open', 'Tilbake'),
            $row('closed', 'in_progress', 'Fordi'),
            $row('closed', 'cancelled', 'Fordi'),
            $row('open', 'open', 'Fordi'),
            $row('open', 'done', 'Fordi'),
        ] as $attributes) {
            try {
                DB::transaction(fn () => DB::table('improvement_case_status_changes')->insert($attributes));
                $this->fail('Refused: '.json_encode($attributes));
            } catch (QueryException) {
            }
        }

        $this->assertSame(0, ImprovementCaseStatusChange::query()->where('improvement_case_id', $case->id)->count());
    }

    // ---------------------------------------------------------------------
    // Delete
    // ---------------------------------------------------------------------

    public function test_a_case_nobody_has_started_on_can_be_deleted_with_its_own_permission(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $editor = $this->editor($customer, $hr);
        $deleter = $this->member($customer);
        $this->grant($customer, $deleter, [CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::IMPROVEMENT_DELETE], [$hr]);
        $case = $this->improvementCase($customer, $hr, 'Feilregistrert', $editor);

        $this->assertTrue($case->isDeletable());
        $this->assertFalse($this->actingAs($editor)->get("/app/improvements/{$case->id}")->viewData('page')['props']['permissions']['can_delete']);
        $this->actingAs($editor)->delete("/app/improvements/{$case->id}")->assertForbidden();

        $this->assertTrue($this->actingAs($deleter)->get("/app/improvements/{$case->id}")->viewData('page')['props']['permissions']['can_delete']);
        $this->actingAs($deleter)->delete("/app/improvements/{$case->id}")
            ->assertRedirect('/app/improvements')
            ->assertSessionHas('success', 'Saken er slettet.');
        $this->assertNull($case->fresh());
    }

    public function test_a_case_that_has_been_handled_cannot_be_deleted_even_after_it_is_reopened(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->handler($customer, $hr);

        $started = $this->improvementCase($customer, $hr, 'Startet', $user);
        $this->actingAs($user)->post("/app/improvements/{$started->id}/start");

        $closed = $this->improvementCase($customer, $hr, 'Lukket', $user);
        $this->actingAs($user)->post("/app/improvements/{$closed->id}/close", ['closing_note' => 'Ferdig']);

        $cancelled = $this->improvementCase($customer, $hr, 'Avbrutt', $user);
        $this->actingAs($user)->post("/app/improvements/{$cancelled->id}/cancel", ['reason' => 'Duplikat']);

        // Open again, but it carries history.
        $reopened = $this->improvementCase($customer, $hr, 'Gjenåpnet', $user);
        $this->actingAs($user)->post("/app/improvements/{$reopened->id}/close", ['closing_note' => 'Ferdig']);
        $this->actingAs($user)->post("/app/improvements/{$reopened->id}/reopen", ['reason' => 'Igjen']);
        $this->assertSame(ImprovementCase::STATUS_OPEN, $reopened->fresh()->status);

        foreach ([$started, $closed, $cancelled, $reopened] as $case) {
            $this->assertFalse($case->fresh()->isDeletable(), $case->title);
            $props = $this->actingAs($user)->get("/app/improvements/{$case->id}")->assertOk()->viewData('page')['props'];
            $this->assertFalse($props['permissions']['can_delete'], $case->title);

            $this->actingAs($user)->delete("/app/improvements/{$case->id}")
                ->assertRedirect()
                ->assertSessionHas('error', 'Saken kan ikke slettes fordi den har vært under behandling. Bruk «Avbryt sak» i stedet.');
            $this->assertNotNull($case->fresh(), $case->title);
        }
    }

    public function test_an_area_holding_cases_cannot_be_deleted(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $used = $this->area($customer, 'HR');
        $empty = $this->area($customer, 'Tomt');
        $this->improvementCase($customer, $used, 'Sak');

        $this->assertTrue($used->isInUse());
        $this->assertFalse($empty->isInUse());

        $this->actingAs($owner)->delete("/app/customer-environment/business-areas/{$used->id}")
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertNotNull($used->fresh());
    }

    // ---------------------------------------------------------------------
    // Catalogue
    // ---------------------------------------------------------------------

    public function test_the_improvement_keys_are_their_own_area_scoped_domain(): void
    {
        $this->assertSame(
            ['improvement.view', 'improvement.edit', 'improvement.close', 'improvement.delete'],
            CustomerPermissionCatalog::domains()[CustomerPermissionCatalog::DOMAIN_IMPROVEMENT],
        );
        $this->assertContains(CustomerPermissionCatalog::DOMAIN_IMPROVEMENT, CustomerPermissionCatalog::areaScopedDomains());

        foreach (CustomerPermissionCatalog::domains()[CustomerPermissionCatalog::DOMAIN_IMPROVEMENT] as $key) {
            $this->assertTrue(CustomerPermissionCatalog::isAreaScoped($key), $key);
            $this->assertNotSame('procynia.customer_env.roles.permissions.'.CustomerPermissionCatalog::translationKey($key), CustomerPermissionCatalog::label($key));
        }
    }
}
