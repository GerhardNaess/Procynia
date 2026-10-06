<?php

namespace Tests\Feature\App;

use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\CustomerPackageEntitlement;
use App\Models\CustomerRole;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\Objective;
use App\Models\ObjectiveStatusChange;
use App\Models\User;
use App\Support\CustomerPermissionCatalog;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use LogicException;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Mål, from database to page, gated twice: by the customer's own roles (what) and by fagområder
 * (where), exactly as Risiko.
 *
 * What these tests defend:
 *
 *  - An objective outside the user's areas is undiscoverable — not listed, not searched, not
 *    counted, and a 404 by URL for read and for every write.
 *  - Permission and area pair per role; «Alle» is a wildcard; inactive roles and System Owner's
 *    administrator grant reach no objective.
 *  - objective.edit creates and changes, in the area it acts on — both areas when moving.
 *    objective.delete is its own permission.
 *  - The owner is an active person of the same customer who can read objectives in the area.
 *  - Status is never a form field: an objective is created active, and leaves it only through
 *    Lukk mål / Gjenåpne. The objective's closed_* columns are the current state; every closing and
 *    reopening is also an immutable ObjectiveStatusChange, so a reopening loses nothing.
 */
class ObjectiveTest extends TestCase
{
    use UsesProjectPostgresConnection;

    private const ALL = [
        CustomerPermissionCatalog::OBJECTIVE_VIEW,
        CustomerPermissionCatalog::OBJECTIVE_EDIT,
        CustomerPermissionCatalog::OBJECTIVE_DELETE,
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
    // Model and lifecycle
    // ---------------------------------------------------------------------

    public function test_a_new_objective_is_active_whatever_the_form_says_and_may_run_without_a_target_date(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_EDIT], [$hr]);

        $response = $this->actingAs($user)->post('/app/objectives', $this->payload($hr, $user) + [
            'status' => Objective::STATUS_ACHIEVED,
            'closed_at' => now()->toIso8601String(),
        ]);

        $objective = Objective::query()->where('customer_id', $customer->id)->sole();
        $response->assertRedirect("/app/objectives/{$objective->id}");

        $this->assertSame(Objective::STATUS_ACTIVE, $objective->status);
        $this->assertNull($objective->target_date);
        $this->assertNull($objective->closed_at);
        $this->assertSame((int) $user->id, (int) $objective->owner_user_id);
        $this->assertSame((int) $user->id, (int) $objective->created_by);

        $this->actingAs($user)->post('/app/objectives', ['target_date' => '2027-06-30'] + $this->payload($hr, $user, 'Med måldato'))
            ->assertRedirect();
        $this->assertSame('2027-06-30', Objective::query()->where('title', 'Med måldato')->sole()->target_date->format('Y-m-d'));
    }

    public function test_an_unknown_status_is_refused_by_the_model_and_the_database(): void
    {
        ['customer' => $customer] = $this->context();
        $objective = $this->objective($customer, $this->area($customer, 'HR'), 'Mål');

        $objective->status = 'done';

        try {
            $objective->save();
            $this->fail('An unknown status must not be saved.');
        } catch (DomainException) {
        }

        $this->expectException(QueryException::class);
        DB::table('objectives')->where('id', $objective->id)->update(['status' => 'done']);
    }

    public function test_the_database_keeps_closure_fields_in_step_with_status(): void
    {
        ['customer' => $customer] = $this->context();
        $objective = $this->objective($customer, $this->area($customer, 'HR'), 'Mål');

        // Closed without a closure time.
        try {
            DB::transaction(fn () => DB::table('objectives')->where('id', $objective->id)->update(['status' => Objective::STATUS_ACHIEVED]));
            $this->fail('A closed objective needs closed_at.');
        } catch (QueryException) {
        }

        // Active with a closure time.
        $this->expectException(QueryException::class);
        DB::table('objectives')->where('id', $objective->id)->update(['closed_at' => now()]);
    }

    public function test_status_is_not_mass_assignable(): void
    {
        $this->assertNotContains('status', (new Objective)->getFillable());
        $this->assertNotContains('closed_at', (new Objective)->getFillable());
        $this->assertNotContains('closed_by_user_id', (new Objective)->getFillable());
    }

    public function test_an_owner_who_is_deleted_leaves_the_objective_without_one(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $owner = $this->member($customer);
        $reader = $this->member($customer);
        $this->grant($customer, $owner, [CustomerPermissionCatalog::OBJECTIVE_VIEW], [$hr]);
        $this->grant($customer, $reader, [CustomerPermissionCatalog::OBJECTIVE_VIEW], [$hr]);
        $objective = $this->objective($customer, $hr, 'Mål', $owner);

        $owner->delete();

        $this->assertNull($objective->fresh()->owner_user_id);
        $props = $this->actingAs($reader)->get("/app/objectives/{$objective->id}")->assertOk()->viewData('page')['props'];
        $this->assertNull($props['objective']['owner_name']);
    }

    // ---------------------------------------------------------------------
    // Tenant boundary
    // ---------------------------------------------------------------------

    public function test_another_customers_objective_can_never_be_read_or_changed(): void
    {
        ['customer' => $customer] = $this->context();
        ['customer' => $other] = $this->context();
        $mine = $this->area($customer, 'HR');
        $theirs = $this->objective($other, $this->area($other, 'HR'), 'Andres mål');

        $user = $this->member($customer);
        $this->grantAll($customer, $user, self::ALL);

        $props = $this->actingAs($user)->get('/app/objectives?search=andres')->assertOk()->viewData('page')['props'];
        $this->assertSame([], $props['objectives']);
        $this->assertSame(0, $props['visible_count']);

        $this->actingAs($user)->get("/app/objectives/{$theirs->id}")->assertNotFound();
        $this->actingAs($user)->patch("/app/objectives/{$theirs->id}", $this->payload($mine, $user))->assertNotFound();
        $this->actingAs($user)->delete("/app/objectives/{$theirs->id}")->assertNotFound();

        $this->assertSame('Andres mål', $theirs->fresh()->title);
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

        $this->actingAs($user)->post('/app/objectives', $this->payload($hr, $foreigner))
            ->assertSessionHasErrors(['owner_user_id' => 'Ansvarlig må være en aktiv bruker som kan se mål i valgt fagområde.']);

        $this->actingAs($user)->post('/app/objectives', $this->payload($foreignArea, $user))
            ->assertSessionHasErrors(['business_area_id' => 'Du kan ikke legge mål i dette fagområdet.']);

        $this->assertSame(0, Objective::query()->whereIn('customer_id', [$customer->id, $other->id])->count());
    }

    // ---------------------------------------------------------------------
    // Fagområde and roles
    // ---------------------------------------------------------------------

    public function test_view_in_the_right_area_reads_and_the_wrong_area_is_absent(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $finance = $this->area($customer, 'Økonomi');
        $visible = $this->objective($customer, $hr, 'Lavere sykefravær');
        $hidden = $this->objective($customer, $finance, 'Lavere fakturafeil');

        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::OBJECTIVE_VIEW], [$hr]);

        $props = $this->actingAs($user)->get('/app/objectives')->assertOk()->viewData('page')['props'];
        $this->assertSame([$visible->id], array_column($props['objectives'], 'id'));

        $props = $this->actingAs($user)->get("/app/objectives/{$visible->id}")->assertOk()->viewData('page')['props'];
        $this->assertFalse($props['permissions']['can_edit']);
        $this->assertFalse($props['permissions']['can_delete']);
        $this->assertSame([], $props['area_options']);
        $this->assertSame([], $props['owner_options']);

        $this->actingAs($user)->get("/app/objectives/{$hidden->id}")->assertNotFound();
        $this->actingAs($user)->get('/app/objectives/999999999')->assertNotFound();
    }

    public function test_permission_and_area_from_different_roles_are_not_combined(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $finance = $this->area($customer, 'Økonomi');
        $hrObjective = $this->objective($customer, $hr, 'HR-mål');
        $financeObjective = $this->objective($customer, $finance, 'Økonomimål');

        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::OBJECTIVE_VIEW], [$hr]);
        $this->grant($customer, $user, [CustomerPermissionCatalog::OBJECTIVE_EDIT, CustomerPermissionCatalog::OBJECTIVE_DELETE], [$finance]);

        // Edit and delete in Økonomi do not reach HR, where the user only reads.
        $this->actingAs($user)->patch("/app/objectives/{$hrObjective->id}", $this->payload($hr, $user, 'Endret'))->assertForbidden();
        $this->actingAs($user)->delete("/app/objectives/{$hrObjective->id}")->assertForbidden();

        // View in HR does not reach Økonomi, where the user only edits.
        $this->actingAs($user)->get("/app/objectives/{$financeObjective->id}")->assertNotFound();
        $this->actingAs($user)->patch("/app/objectives/{$financeObjective->id}", $this->payload($finance, $user))->assertNotFound();

        $this->assertSame('HR-mål', $hrObjective->fresh()->title);
        $this->assertNotNull($financeObjective->fresh());
    }

    public function test_alle_reaches_every_area_including_one_created_later(): void
    {
        ['customer' => $customer] = $this->context();
        ['customer' => $other] = $this->context();
        $hr = $this->area($customer, 'HR');
        $this->objective($customer, $hr, 'Første mål');
        $this->objective($other, $this->area($other, 'HR'), 'Fremmed mål');

        $user = $this->member($customer);
        $this->grantAll($customer, $user, [CustomerPermissionCatalog::OBJECTIVE_VIEW]);

        $later = $this->area($customer, 'Beredskap');
        $laterObjective = $this->objective($customer, $later, 'Senere mål');

        $props = $this->actingAs($user)->get('/app/objectives')->assertOk()->viewData('page')['props'];
        $this->assertSame(['Første mål', 'Senere mål'], array_column($props['objectives'], 'title'));
        $this->assertSame(2, $props['visible_count']);
        $this->actingAs($user)->get("/app/objectives/{$laterObjective->id}")->assertOk();
    }

    public function test_an_inactive_role_reaches_nothing(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $objective = $this->objective($customer, $hr, 'Mål');
        $user = $this->member($customer);
        $role = $this->grant($customer, $user, self::ALL, [$hr]);

        $role->forceFill(['is_active' => false])->save();

        $this->actingAs($user)->get('/app/objectives')->assertForbidden();
        $this->actingAs($user)->get("/app/objectives/{$objective->id}")->assertForbidden();
        $this->actingAs($user)->post('/app/objectives', $this->payload($hr, $user))->assertForbidden();
    }

    public function test_without_objective_view_everything_is_forbidden_before_any_id_is_looked_at(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $objective = $this->objective($customer, $hr, 'Mål');
        $user = $this->member($customer);
        // Risk rights in the same area are no objective rights.
        $this->grant($customer, $user, [CustomerPermissionCatalog::RISK_VIEW, CustomerPermissionCatalog::RISK_EDIT], [$hr]);

        $this->actingAs($user)->get('/app/objectives')->assertForbidden();
        $this->actingAs($user)->get("/app/objectives/{$objective->id}")->assertForbidden();
        $this->actingAs($user)->get('/app/objectives/999999999')->assertForbidden();
        $this->actingAs($user)->delete("/app/objectives/{$objective->id}")->assertForbidden();
    }

    public function test_the_module_needs_the_objectives_entitlement(): void
    {
        ['customer' => $customer] = $this->context(entitled: false);
        $hr = $this->area($customer, 'HR');
        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::OBJECTIVE_VIEW], [$hr]);

        $this->actingAs($user)->get('/app/objectives')->assertRedirect();
    }

    public function test_system_owner_reaches_the_module_but_reads_no_objective_without_a_role(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $hr = $this->area($customer, 'HR');
        $objective = $this->objective($customer, $hr, 'Hemmelig mål');

        $props = $this->actingAs($owner)->get('/app/objectives?search=hemmelig')->assertOk()->viewData('page')['props'];
        $this->assertSame([], $props['objectives']);
        $this->assertSame(0, $props['visible_count']);
        $this->assertFalse($props['has_areas']);
        $this->assertFalse($props['permissions']['can_create']);
        $this->assertTrue($props['access_setup']['customer_has_areas']);
        $this->assertStringEndsWith('?tab=permissions#business-areas', $props['access_setup']['manage_url']);

        $this->actingAs($owner)->get("/app/objectives/{$objective->id}")->assertNotFound();
        $this->actingAs($owner)->delete("/app/objectives/{$objective->id}")->assertNotFound();
        $this->actingAs($owner)->post('/app/objectives', $this->payload($hr, $owner))->assertSessionHasErrors('business_area_id');

        // Through an explicit role, like anyone else.
        $this->grant($customer, $owner, [CustomerPermissionCatalog::OBJECTIVE_VIEW], [$hr]);
        $this->actingAs($owner)->get("/app/objectives/{$objective->id}")->assertOk();
    }

    // ---------------------------------------------------------------------
    // Edit and move
    // ---------------------------------------------------------------------

    public function test_edit_in_the_right_area_changes_the_objective_but_never_its_status(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->member($customer);
        $colleague = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_EDIT], [$hr]);
        $this->grant($customer, $colleague, [CustomerPermissionCatalog::OBJECTIVE_VIEW], [$hr]);
        $objective = $this->objective($customer, $hr, 'Mål', $user);

        $props = $this->actingAs($user)->get("/app/objectives/{$objective->id}")->assertOk()->viewData('page')['props'];
        $this->assertTrue($props['permissions']['can_edit']);
        $this->assertFalse($props['permissions']['can_delete']);
        $this->assertEqualsCanonicalizing([(int) $user->id, (int) $colleague->id], array_column($props['owner_options'], 'id'));

        $this->actingAs($user)->patch("/app/objectives/{$objective->id}", [
            'title' => '  Nytt navn  ',
            'description' => '',
            'business_area_id' => $hr->id,
            'owner_user_id' => $colleague->id,
            'target_date' => '2027-12-31',
            'status' => Objective::STATUS_CANCELLED,
        ])->assertRedirect()->assertSessionHas('success', 'Målet er oppdatert.');

        $objective->refresh();
        $this->assertSame('Nytt navn', $objective->title);
        $this->assertNull($objective->description);
        $this->assertSame((int) $colleague->id, (int) $objective->owner_user_id);
        $this->assertSame('2027-12-31', $objective->target_date->format('Y-m-d'));
        $this->assertSame(Objective::STATUS_ACTIVE, $objective->status);
        $this->assertSame((int) $user->id, (int) $objective->updated_by);
    }

    public function test_view_without_edit_cannot_create_or_change(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::OBJECTIVE_VIEW], [$hr]);
        $objective = $this->objective($customer, $hr, 'Mål', $user);

        $props = $this->actingAs($user)->get('/app/objectives')->assertOk()->viewData('page')['props'];
        $this->assertFalse($props['permissions']['can_create']);
        $this->assertSame([], $props['area_options']);

        $this->actingAs($user)->post('/app/objectives', $this->payload($hr, $user))->assertSessionHasErrors('business_area_id');
        $this->actingAs($user)->patch("/app/objectives/{$objective->id}", $this->payload($hr, $user, 'Endret'))->assertForbidden();
        $this->assertSame('Mål', $objective->fresh()->title);
    }

    public function test_moving_takes_edit_in_both_the_old_and_the_new_area(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $finance = $this->area($customer, 'Økonomi');
        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_EDIT], [$hr]);
        $this->grant($customer, $user, [CustomerPermissionCatalog::OBJECTIVE_VIEW], [$finance]);
        $fromHr = $this->objective($customer, $hr, 'Fra HR', $user);
        $fromFinance = $this->objective($customer, $finance, 'Fra Økonomi', $user);

        // Into an area the user only reads: refused, the objective stays where it was.
        $this->actingAs($user)->patch("/app/objectives/{$fromHr->id}", $this->payload($finance, $user, 'Fra HR'))
            ->assertSessionHasErrors(['business_area_id' => 'Du kan ikke legge mål i dette fagområdet.']);
        $this->assertSame((int) $hr->id, (int) $fromHr->fresh()->business_area_id);

        // Out of an area the user only reads, into one they edit: refused as well.
        $this->actingAs($user)->patch("/app/objectives/{$fromFinance->id}", $this->payload($hr, $user, 'Fra Økonomi'))->assertForbidden();
        $this->assertSame((int) $finance->id, (int) $fromFinance->fresh()->business_area_id);

        // With edit on both sides it moves.
        $this->grant($customer, $user, [CustomerPermissionCatalog::OBJECTIVE_EDIT], [$finance]);
        $this->actingAs($user)->patch("/app/objectives/{$fromHr->id}", $this->payload($finance, $user, 'Fra HR'))->assertSessionHasNoErrors();
        $this->assertSame((int) $finance->id, (int) $fromHr->fresh()->business_area_id);
    }

    public function test_a_move_is_refused_when_the_owner_cannot_see_the_new_area(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $finance = $this->area($customer, 'Økonomi');
        $user = $this->member($customer);
        $hrOnly = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_EDIT], [$hr, $finance]);
        $this->grant($customer, $hrOnly, [CustomerPermissionCatalog::OBJECTIVE_VIEW], [$hr]);
        $objective = $this->objective($customer, $hr, 'Mål', $hrOnly);

        $this->actingAs($user)->patch("/app/objectives/{$objective->id}", $this->payload($finance, $hrOnly, 'Mål'))
            ->assertSessionHasErrors(['owner_user_id' => 'Ansvarlig må være en aktiv bruker som kan se mål i valgt fagområde.']);

        $this->assertSame((int) $hr->id, (int) $objective->fresh()->business_area_id);
    }

    public function test_the_owner_must_be_active_and_able_to_read_the_area(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_EDIT], [$hr]);

        $inactive = $this->member($customer);
        $this->grant($customer, $inactive, [CustomerPermissionCatalog::OBJECTIVE_VIEW], [$hr]);
        $inactive->forceFill(['is_active' => false])->save();

        $riskReader = $this->member($customer);
        $this->grant($customer, $riskReader, [CustomerPermissionCatalog::RISK_VIEW], [$hr]);

        foreach ([$inactive, $riskReader] as $candidate) {
            $this->actingAs($user)->post('/app/objectives', $this->payload($hr, $candidate))->assertSessionHasErrors('owner_user_id');
        }

        $props = $this->actingAs($user)->get('/app/objectives')->assertOk()->viewData('page')['props'];
        $this->assertSame([(int) $user->id], array_column($props['owner_options'], 'id'));
    }

    public function test_required_fields_are_named_in_norwegian(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_EDIT], [$hr]);

        $this->actingAs($user)->post('/app/objectives', ['title' => '', 'business_area_id' => $hr->id, 'owner_user_id' => '', 'target_date' => '30.06.2027'])
            ->assertSessionHasErrors([
                'title' => 'Tittel må fylles ut.',
                'owner_user_id' => 'Ansvarlig må fylles ut.',
                'target_date' => 'Måldato må være en gyldig dato.',
            ]);
    }

    // ---------------------------------------------------------------------
    // Delete
    // ---------------------------------------------------------------------

    public function test_delete_is_its_own_permission(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $editor = $this->member($customer);
        $deleter = $this->member($customer);
        $this->grant($customer, $editor, [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_EDIT], [$hr]);
        $this->grant($customer, $deleter, [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_DELETE], [$hr]);
        $objective = $this->objective($customer, $hr, 'Feilregistrert');

        $this->actingAs($editor)->delete("/app/objectives/{$objective->id}")->assertForbidden();
        $this->assertNotNull($objective->fresh());

        $props = $this->actingAs($deleter)->get("/app/objectives/{$objective->id}")->assertOk()->viewData('page')['props'];
        $this->assertTrue($props['permissions']['can_delete']);
        $this->assertFalse($props['permissions']['can_edit']);

        $this->actingAs($deleter)->delete("/app/objectives/{$objective->id}")
            ->assertRedirect('/app/objectives')
            ->assertSessionHas('success', 'Målet er slettet.');
        $this->assertNull($objective->fresh());
    }

    public function test_an_area_holding_objectives_cannot_be_deleted(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $used = $this->area($customer, 'HR');
        $empty = $this->area($customer, 'Tomt');
        $this->objective($customer, $used, 'Mål');

        $this->assertTrue($used->isInUse());
        $this->assertFalse($empty->isInUse());

        $this->actingAs($owner)->delete("/app/customer-environment/business-areas/{$used->id}")
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertNotNull($used->fresh());

        $this->actingAs($owner)->delete("/app/customer-environment/business-areas/{$empty->id}")->assertRedirect();
        $this->assertNull($empty->fresh());
    }

    // ---------------------------------------------------------------------
    // Listing
    // ---------------------------------------------------------------------

    public function test_search_filters_and_counts_stay_inside_the_authorised_set(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $health = $this->area($customer, 'HMS');
        $finance = $this->area($customer, 'Økonomi');
        $closed = $this->objective($customer, $hr, 'Bedre onboarding');
        $closed->forceFill(['status' => Objective::STATUS_ACHIEVED, 'closed_at' => now()])->save();
        $active = $this->objective($customer, $hr, 'Bedre arbeidsmiljø');
        $other = $this->objective($customer, $health, 'Færre skader');
        $this->objective($customer, $finance, 'Bedre likviditet');
        $this->objective($customer, $finance, 'Bedre fakturering');

        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::OBJECTIVE_VIEW], [$hr, $health]);

        // Active first, then by title; the hidden area contributes nothing.
        $props = $this->actingAs($user)->get('/app/objectives')->assertOk()->viewData('page')['props'];
        $this->assertSame(['Bedre arbeidsmiljø', 'Færre skader', 'Bedre onboarding'], array_column($props['objectives'], 'title'));
        $this->assertSame(3, $props['visible_count']);
        $this->assertSame(['HMS', 'HR'], array_column($props['filter_area_options'], 'name'));

        // The search term matches four titles; only the visible ones come back, and the total
        // stays the size of the visible set.
        $props = $this->actingAs($user)->get('/app/objectives?search=bedre')->assertOk()->viewData('page')['props'];
        $this->assertSame([$active->id, $closed->id], array_column($props['objectives'], 'id'));
        $this->assertSame(3, $props['visible_count']);

        $props = $this->actingAs($user)->get('/app/objectives?status='.Objective::STATUS_ACHIEVED)->assertOk()->viewData('page')['props'];
        $this->assertSame([$closed->id], array_column($props['objectives'], 'id'));

        $props = $this->actingAs($user)->get("/app/objectives?area={$health->id}")->assertOk()->viewData('page')['props'];
        $this->assertSame([$other->id], array_column($props['objectives'], 'id'));

        // A hidden area as filter is ignored, not answered.
        $props = $this->actingAs($user)->get("/app/objectives?area={$finance->id}")->assertOk()->viewData('page')['props'];
        $this->assertSame(3, count($props['objectives']));
        $this->assertNull($props['filters']['area']);

        $this->assertStringNotContainsString(
            'Økonomi',
            json_encode(collect($props)->except('translations')->all(), JSON_UNESCAPED_UNICODE),
        );
        $this->assertStringNotContainsString(
            'likviditet',
            json_encode(collect($props)->except('translations')->all(), JSON_UNESCAPED_UNICODE),
        );
    }

    // ---------------------------------------------------------------------
    // Lukk mål, Gjenåpne and Historikk
    // ---------------------------------------------------------------------

    public function test_the_first_closing_sets_the_current_state_and_writes_one_history_row(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $objective = $this->objective($customer, $hr, 'Mål', $user);

        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->actingAs($user)->post("/app/objectives/{$objective->id}/close", [
            'status' => Objective::STATUS_ACHIEVED,
            'note' => '  Målet er nådd.  ',
        ])->assertRedirect()->assertSessionHas('success', 'Målet er lukket.');

        $objective->refresh();
        $this->assertSame(Objective::STATUS_ACHIEVED, $objective->status);
        $this->assertSame('2026-10-05 10:00:00', $objective->closed_at->format('Y-m-d H:i:s'));
        $this->assertSame((int) $user->id, (int) $objective->closed_by_user_id);
        $this->assertSame('Målet er nådd.', $objective->closing_note);

        $change = ObjectiveStatusChange::query()->where('objective_id', $objective->id)->sole();
        $this->assertSame((int) $customer->id, (int) $change->customer_id);
        $this->assertSame(Objective::STATUS_ACTIVE, $change->from_status);
        $this->assertSame(Objective::STATUS_ACHIEVED, $change->to_status);
        $this->assertSame('Målet er nådd.', $change->note);
        $this->assertSame((int) $user->id, (int) $change->changed_by_user_id);
        $this->assertSame('2026-10-05 10:00:00', $change->changed_at->format('Y-m-d H:i:s'));

        // The page now offers Gjenåpne and nothing that changes fields.
        $props = $this->actingAs($user)->get("/app/objectives/{$objective->id}")->assertOk()->viewData('page')['props'];
        $this->assertFalse($props['permissions']['can_edit']);
        $this->assertFalse($props['permissions']['can_close']);
        $this->assertTrue($props['permissions']['can_reopen']);
        $this->assertSame($user->name, $props['objective']['closed_by_name']);
        $this->assertCount(1, $props['status_history']);
        $this->assertSame($user->name, $props['status_history'][0]['changed_by_name']);
    }

    public function test_a_closing_note_is_optional_but_the_outcome_must_be_a_closing_one(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $objective = $this->objective($customer, $hr, 'Mål', $user);

        foreach (['', Objective::STATUS_ACTIVE, 'done'] as $outcome) {
            $this->actingAs($user)->post("/app/objectives/{$objective->id}/close", ['status' => $outcome])->assertSessionHasErrors('status');
        }
        $this->assertSame(0, ObjectiveStatusChange::query()->where('objective_id', $objective->id)->count());

        $this->actingAs($user)->post("/app/objectives/{$objective->id}/close", ['status' => Objective::STATUS_CANCELLED])->assertSessionHasNoErrors();
        $this->assertNull($objective->fresh()->closing_note);
        $this->assertNull(ObjectiveStatusChange::query()->where('objective_id', $objective->id)->sole()->note);

        // Closing again is refused, and writes nothing.
        $this->actingAs($user)->post("/app/objectives/{$objective->id}/close", ['status' => Objective::STATUS_ACHIEVED])
            ->assertSessionHasErrors(['status' => 'Målet er allerede lukket. Last siden på nytt.']);
        $this->assertSame(Objective::STATUS_CANCELLED, $objective->fresh()->status);
        $this->assertSame(1, ObjectiveStatusChange::query()->where('objective_id', $objective->id)->count());
    }

    public function test_reopening_requires_a_reason_clears_the_closing_and_keeps_it_in_the_history(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $closer = $this->editor($customer, $hr);
        $reopener = $this->editor($customer, $hr);
        $objective = $this->objective($customer, $hr, 'Mål', $closer);

        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->actingAs($closer)->post("/app/objectives/{$objective->id}/close", ['status' => Objective::STATUS_ACHIEVED, 'note' => 'Målet er nådd.']);

        foreach (['', '   '] as $reason) {
            $this->actingAs($reopener)->post("/app/objectives/{$objective->id}/reopen", ['reason' => $reason])
                ->assertSessionHasErrors(['reason' => 'Begrunnelse må fylles ut.']);
        }
        $this->assertSame(Objective::STATUS_ACHIEVED, $objective->fresh()->status);

        Carbon::setTestNow('2026-10-12 09:30:00');
        $this->actingAs($reopener)->post("/app/objectives/{$objective->id}/reopen", ['reason' => 'Nye krav gjør at målet må videreføres.'])
            ->assertRedirect()->assertSessionHas('success', 'Målet er gjenåpnet.');

        $objective->refresh();
        $this->assertSame(Objective::STATUS_ACTIVE, $objective->status);
        $this->assertNull($objective->closed_at);
        $this->assertNull($objective->closed_by_user_id);
        $this->assertNull($objective->closing_note);
        $this->assertSame((int) $reopener->id, (int) $objective->updated_by);

        $history = ObjectiveStatusChange::query()->where('objective_id', $objective->id)->orderBy('id')->get();
        $this->assertCount(2, $history);
        $this->assertSame([Objective::STATUS_ACTIVE, Objective::STATUS_ACHIEVED, 'Målet er nådd.', (int) $closer->id, '2026-10-05 10:00:00'], [
            $history[0]->from_status, $history[0]->to_status, $history[0]->note, (int) $history[0]->changed_by_user_id, $history[0]->changed_at->format('Y-m-d H:i:s'),
        ]);
        $this->assertSame([Objective::STATUS_ACHIEVED, Objective::STATUS_ACTIVE, 'Nye krav gjør at målet må videreføres.', (int) $reopener->id, '2026-10-12 09:30:00'], [
            $history[1]->from_status, $history[1]->to_status, $history[1]->note, (int) $history[1]->changed_by_user_id, $history[1]->changed_at->format('Y-m-d H:i:s'),
        ]);

        // Reopening an active objective is refused, and writes nothing.
        $this->actingAs($reopener)->post("/app/objectives/{$objective->id}/reopen", ['reason' => 'Igjen'])
            ->assertSessionHasErrors(['reason' => 'Målet er allerede aktivt. Last siden på nytt.']);
        $this->assertSame(2, ObjectiveStatusChange::query()->where('objective_id', $objective->id)->count());

        // Newest first on the page.
        $props = $this->actingAs($reopener)->get("/app/objectives/{$objective->id}")->assertOk()->viewData('page')['props'];
        $this->assertSame([Objective::STATUS_ACTIVE, Objective::STATUS_ACHIEVED], array_column($props['status_history'], 'to_status'));
        $this->assertTrue($props['permissions']['can_edit']);
        $this->assertTrue($props['permissions']['can_close']);
        $this->assertFalse($props['permissions']['can_reopen']);
    }

    public function test_a_second_closing_describes_itself_and_overwrites_nothing_in_the_history(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $first = $this->editor($customer, $hr);
        $second = $this->editor($customer, $hr);
        $objective = $this->objective($customer, $hr, 'Mål', $first);

        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->actingAs($first)->post("/app/objectives/{$objective->id}/close", ['status' => Objective::STATUS_ACHIEVED, 'note' => 'Første lukking.']);
        Carbon::setTestNow('2026-10-12 09:00:00');
        $this->actingAs($second)->post("/app/objectives/{$objective->id}/reopen", ['reason' => 'Tallene var feil.']);
        Carbon::setTestNow('2026-11-01 14:00:00');
        $this->actingAs($second)->post("/app/objectives/{$objective->id}/close", ['status' => Objective::STATUS_NOT_ACHIEVED, 'note' => 'Andre lukking.']);

        $objective->refresh();
        $this->assertSame(Objective::STATUS_NOT_ACHIEVED, $objective->status);
        $this->assertSame('2026-11-01 14:00:00', $objective->closed_at->format('Y-m-d H:i:s'));
        $this->assertSame((int) $second->id, (int) $objective->closed_by_user_id);
        $this->assertSame('Andre lukking.', $objective->closing_note);

        $rows = ObjectiveStatusChange::query()->where('objective_id', $objective->id)->orderBy('id')->get()
            ->map(fn (ObjectiveStatusChange $change): array => [
                $change->from_status, $change->to_status, $change->note, (int) $change->changed_by_user_id, $change->changed_at->format('Y-m-d H:i:s'),
            ])
            ->all();

        $this->assertSame([
            [Objective::STATUS_ACTIVE, Objective::STATUS_ACHIEVED, 'Første lukking.', (int) $first->id, '2026-10-05 10:00:00'],
            [Objective::STATUS_ACHIEVED, Objective::STATUS_ACTIVE, 'Tallene var feil.', (int) $second->id, '2026-10-12 09:00:00'],
            [Objective::STATUS_ACTIVE, Objective::STATUS_NOT_ACHIEVED, 'Andre lukking.', (int) $second->id, '2026-11-01 14:00:00'],
        ], $rows);
    }

    public function test_a_closed_objective_is_reopened_before_it_is_edited(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $objective = $this->objective($customer, $hr, 'Mål', $user);
        $this->actingAs($user)->post("/app/objectives/{$objective->id}/close", ['status' => Objective::STATUS_ACHIEVED]);

        $this->actingAs($user)->patch("/app/objectives/{$objective->id}", $this->payload($hr, $user, 'Endret'))
            ->assertRedirect()
            ->assertSessionHas('error', 'Gjenåpne målet før du endrer det.');

        $this->assertSame('Mål', $objective->fresh()->title);
        $this->assertSame(Objective::STATUS_ACHIEVED, $objective->fresh()->status);
    }

    public function test_closing_and_reopening_take_edit_in_the_objectives_own_area(): void
    {
        ['customer' => $customer, 'owner' => $systemOwner] = $this->context();
        $hr = $this->area($customer, 'HR');
        $finance = $this->area($customer, 'Økonomi');
        $editor = $this->editor($customer, $hr);
        $open = $this->objective($customer, $hr, 'Åpent', $editor);
        $closed = $this->objective($customer, $hr, 'Lukket', $editor);
        $this->actingAs($editor)->post("/app/objectives/{$closed->id}/close", ['status' => Objective::STATUS_ACHIEVED]);

        // Reads HR, edits Økonomi: no closing or reopening in HR.
        $wrongArea = $this->member($customer);
        $this->grant($customer, $wrongArea, [CustomerPermissionCatalog::OBJECTIVE_VIEW], [$hr]);
        $this->grant($customer, $wrongArea, [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_EDIT], [$finance]);

        // Reads HR only.
        $reader = $this->member($customer);
        $this->grant($customer, $reader, [CustomerPermissionCatalog::OBJECTIVE_VIEW], [$hr]);

        foreach ([$wrongArea, $reader] as $user) {
            $this->actingAs($user)->post("/app/objectives/{$open->id}/close", ['status' => Objective::STATUS_ACHIEVED])->assertForbidden();
            $this->actingAs($user)->post("/app/objectives/{$closed->id}/reopen", ['reason' => 'Fordi'])->assertForbidden();

            $props = $this->actingAs($user)->get("/app/objectives/{$closed->id}")->assertOk()->viewData('page')['props'];
            $this->assertFalse($props['permissions']['can_reopen']);
            // Whoever may read the objective reads its history.
            $this->assertCount(1, $props['status_history']);
        }

        // System Owner's administrator grant reaches neither the objective nor its history.
        $this->actingAs($systemOwner)->get("/app/objectives/{$closed->id}")->assertNotFound();
        $this->actingAs($systemOwner)->post("/app/objectives/{$open->id}/close", ['status' => Objective::STATUS_ACHIEVED])->assertNotFound();
        $this->actingAs($systemOwner)->post("/app/objectives/{$closed->id}/reopen", ['reason' => 'Fordi'])->assertNotFound();

        $this->assertSame(Objective::STATUS_ACTIVE, $open->fresh()->status);
        $this->assertSame(Objective::STATUS_ACHIEVED, $closed->fresh()->status);
        $this->assertSame(1, ObjectiveStatusChange::query()->whereIn('objective_id', [$open->id, $closed->id])->count());
    }

    public function test_another_customer_can_neither_read_nor_touch_the_history(): void
    {
        ['customer' => $customer] = $this->context();
        ['customer' => $other] = $this->context();
        $hr = $this->area($customer, 'HR');
        $editor = $this->editor($customer, $hr);
        $objective = $this->objective($customer, $hr, 'Mål', $editor);
        $this->actingAs($editor)->post("/app/objectives/{$objective->id}/close", ['status' => Objective::STATUS_ACHIEVED, 'note' => 'Intern merknad']);

        $foreigner = $this->member($other);
        $this->grantAll($other, $foreigner, self::ALL);

        $this->actingAs($foreigner)->get("/app/objectives/{$objective->id}")->assertNotFound();
        $this->actingAs($foreigner)->post("/app/objectives/{$objective->id}/reopen", ['reason' => 'Kapret'])->assertNotFound();
        $this->actingAs($foreigner)->post("/app/objectives/{$objective->id}/close", ['status' => Objective::STATUS_CANCELLED])->assertNotFound();

        $this->assertSame(Objective::STATUS_ACHIEVED, $objective->fresh()->status);
        $this->assertSame(1, ObjectiveStatusChange::query()->where('objective_id', $objective->id)->count());
    }

    public function test_history_cannot_be_changed_or_deleted_by_the_application(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $objective = $this->objective($customer, $hr, 'Mål', $user);
        $this->actingAs($user)->post("/app/objectives/{$objective->id}/close", ['status' => Objective::STATUS_ACHIEVED, 'note' => 'Original']);
        $change = ObjectiveStatusChange::query()->where('objective_id', $objective->id)->sole();

        try {
            $change->update(['note' => 'Omskrevet']);
            $this->fail('A history row must not be updated.');
        } catch (LogicException) {
        }

        try {
            $change->delete();
            $this->fail('A history row must not be deleted.');
        } catch (LogicException) {
        }

        $this->assertSame('Original', $change->fresh()->note);

        // No route edits or removes history; only closing and reopening write it.
        $historyRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'app.objectives.'))
            // KPI routes have their own guard in KpiTest.
            ->reject(fn ($route): bool => str_starts_with((string) $route->getName(), 'app.objectives.kpis.'))
            ->map(fn ($route): string => $route->getName())
            ->sort()
            ->values()
            ->all();
        $this->assertSame([
            'app.objectives.close', 'app.objectives.destroy', 'app.objectives.index', 'app.objectives.reopen',
            'app.objectives.show', 'app.objectives.store', 'app.objectives.update',
        ], $historyRoutes);
    }

    public function test_the_database_refuses_a_reopening_without_a_reason_and_impossible_transitions(): void
    {
        ['customer' => $customer] = $this->context();
        $objective = $this->objective($customer, $this->area($customer, 'HR'), 'Mål');
        $row = fn (string $from, string $to, ?string $note): array => [
            'customer_id' => $customer->id, 'objective_id' => $objective->id, 'from_status' => $from,
            'to_status' => $to, 'note' => $note, 'changed_at' => now(),
        ];

        foreach ([
            $row(Objective::STATUS_ACHIEVED, Objective::STATUS_ACTIVE, null),
            $row(Objective::STATUS_ACHIEVED, Objective::STATUS_ACTIVE, '  '),
            $row(Objective::STATUS_ACTIVE, Objective::STATUS_ACTIVE, 'Fordi'),
            $row(Objective::STATUS_ACHIEVED, Objective::STATUS_CANCELLED, null),
            $row(Objective::STATUS_ACTIVE, 'done', null),
        ] as $attributes) {
            try {
                DB::transaction(fn () => DB::table('objective_status_changes')->insert($attributes));
                $this->fail('Refused: '.json_encode($attributes));
            } catch (QueryException) {
            }
        }

        $this->assertSame(0, ObjectiveStatusChange::query()->where('objective_id', $objective->id)->count());
    }

    public function test_deleting_an_objective_takes_its_history_along(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr, [CustomerPermissionCatalog::OBJECTIVE_DELETE]);
        $objective = $this->objective($customer, $hr, 'Feilregistrert', $user);
        $this->actingAs($user)->post("/app/objectives/{$objective->id}/close", ['status' => Objective::STATUS_CANCELLED]);

        $props = $this->actingAs($user)->get("/app/objectives/{$objective->id}")->assertOk()->viewData('page')['props'];
        $this->assertTrue($props['permissions']['can_delete']);

        $this->actingAs($user)->delete("/app/objectives/{$objective->id}")->assertRedirect('/app/objectives');

        $this->assertNull($objective->fresh());
        $this->assertSame(0, ObjectiveStatusChange::query()->where('objective_id', $objective->id)->count());
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function payload(BusinessArea $area, User $owner, string $title = 'Nytt mål'): array
    {
        return [
            'title' => $title,
            'description' => 'Kort beskrivelse',
            'business_area_id' => $area->id,
            'owner_user_id' => $owner->id,
            'target_date' => null,
        ];
    }

    /**
     * A member who reads and edits objectives in the area, plus any extra keys there.
     *
     * @param  list<string>  $extra
     */
    private function editor(Customer $customer, BusinessArea $area, array $extra = []): User
    {
        $user = $this->member($customer);
        $this->grant($customer, $user, [CustomerPermissionCatalog::OBJECTIVE_VIEW, CustomerPermissionCatalog::OBJECTIVE_EDIT, ...$extra], [$area]);

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
            'email' => 'maal-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_CONTRIBUTOR,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    /** @return array{customer: Customer, owner: User} */
    private function context(bool $entitled = true): array
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
            'name' => 'Mål AS',
            'slug' => 'maal-'.Str::lower(Str::random(10)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);

        if ($entitled) {
            // Styring is the lowest step of the ladder that carries Mål og KPI.
            CustomerPackageEntitlement::query()->updateOrCreate(
                ['customer_id' => $customer->id, 'package_key' => 'governance'],
                ['status' => CustomerPackageEntitlement::STATUS_ACTIVE, 'activated_at' => now()],
            );
        }

        $owner = User::query()->create([
            'name' => 'System Owner',
            'email' => 'maal-eier-'.Str::lower(Str::random(10)).'@procynia.local',
            'password' => bcrypt('secret-password'),
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        return ['customer' => $customer, 'owner' => $owner];
    }
}
