<?php

namespace Tests\Feature\App;

use App\Models\ImprovementAction;
use App\Models\ImprovementActionStatusChange;
use App\Models\ImprovementCase;
use App\Models\ImprovementCaseStatusChange;
use App\Support\CustomerPermissionCatalog;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Årsak og bakgrunn and Tiltak on an avvik or a forbedring.
 *
 * What these tests defend:
 *
 *  - A tiltak belongs to one case of the same customer, has no fagområde of its own and is reached
 *    only through its case: a hidden case, another customer's case or a tiltak id that is not the
 *    case's is a 404.
 *  - improvement.edit in the case's area does everything with tiltak; improvement.close alone does
 *    nothing with them. Permission and area pair per role; System Owner reaches nothing by default.
 *  - The owner is an active person of the same customer who can read cases in the case's area.
 *  - Status is never a form field. Every change goes through the lifecycle, writes one immutable
 *    history row and is refused when the tiltak is no longer where the change starts from. A
 *    reopening clears the current completion; the earlier completion stays in the history.
 *  - A case with tiltak that are planned or under arbeid cannot be closed; a case with tiltak cannot
 *    be deleted; only an untouched planned tiltak can be deleted.
 *  - Frist passert is computed: planned or under arbeid with the frist before today.
 */
class ImprovementActionTest extends TestCase
{
    use CreatesImprovementCaseScenarios;
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
        Carbon::setTestNow();

        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    private function url(ImprovementCase $case, ?ImprovementAction $action = null, string $suffix = ''): string
    {
        return "/app/improvements/{$case->id}/actions".($action !== null ? "/{$action->id}" : '').$suffix;
    }

    /** @return array<string, mixed> */
    private function props(mixed $user, ImprovementCase $case): array
    {
        return $this->actingAs($user)->get("/app/improvements/{$case->id}")->assertOk()->viewData('page')['props'];
    }

    // ---------------------------------------------------------------------
    // Årsak og bakgrunn
    // ---------------------------------------------------------------------

    public function test_cause_and_background_is_optional_and_edited_while_the_case_is_active(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->handler($customer, $hr);

        // Not asked for at registration, and not taken from that form either.
        $this->actingAs($user)->post('/app/improvements', ['cause_analysis' => 'Smuglet inn'] + $this->payload($hr, $user))->assertSessionHasNoErrors();
        $case = ImprovementCase::query()->where('customer_id', $customer->id)->sole();
        $this->assertNull($case->cause_analysis);
        $this->assertNull($this->props($user, $case)['case']['cause_analysis']);
        $this->assertTrue($this->props($user, $case)['permissions']['can_edit_cause']);

        $this->actingAs($user)->put("/app/improvements/{$case->id}/cause", ['cause_analysis' => "  Rutinen var ikke oppdatert.\n"])
            ->assertRedirect()->assertSessionHas('success', 'Årsak og bakgrunn er lagret.');
        $this->assertSame('Rutinen var ikke oppdatert.', $case->fresh()->cause_analysis);

        // Under arbeid: still editable, and the rest of the case is left alone.
        $this->actingAs($user)->post("/app/improvements/{$case->id}/start");
        $this->actingAs($user)->put("/app/improvements/{$case->id}/cause", ['cause_analysis' => 'Ny versjon ble ikke distribuert.'])->assertSessionHasNoErrors();
        $fresh = $case->fresh();
        $this->assertSame('Ny versjon ble ikke distribuert.', $fresh->cause_analysis);
        $this->assertSame(ImprovementCase::STATUS_IN_PROGRESS, $fresh->status);
        $this->assertSame('Ny sak', $fresh->title);

        // Emptied again.
        $this->actingAs($user)->put("/app/improvements/{$case->id}/cause", ['cause_analysis' => '   '])->assertSessionHasNoErrors();
        $this->assertNull($case->fresh()->cause_analysis);

        // Ended: left as it stood.
        $this->actingAs($user)->put("/app/improvements/{$case->id}/cause", ['cause_analysis' => 'Endelig årsak'])->assertSessionHasNoErrors();
        $this->actingAs($user)->post("/app/improvements/{$case->id}/close", ['closing_note' => 'Ferdig']);
        $this->actingAs($user)->put("/app/improvements/{$case->id}/cause", ['cause_analysis' => 'Etter lukking'])
            ->assertRedirect()->assertSessionHas('error', 'Gjenåpne saken før du endrer den.');
        $this->assertSame('Endelig årsak', $case->fresh()->cause_analysis);
        $props = $this->props($user, $case);
        $this->assertSame('Endelig årsak', $props['case']['cause_analysis']);
        $this->assertFalse($props['permissions']['can_edit_cause']);
    }

    public function test_cause_and_background_takes_edit_in_the_cases_area(): void
    {
        ['customer' => $customer] = $this->context();
        ['customer' => $other] = $this->context();
        $hr = $this->area($customer, 'HR');
        $it = $this->area($customer, 'IT');
        $case = $this->improvementCase($customer, $hr, 'Sak');
        $theirs = $this->improvementCase($other, $this->area($other, 'HR'), 'Andres sak');

        $reader = $this->member($customer);
        $this->grant($customer, $reader, [CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::IMPROVEMENT_CLOSE], [$hr]);
        $elsewhere = $this->editor($customer, $it);
        $this->grant($customer, $elsewhere, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$hr]);

        $this->assertFalse($this->props($reader, $case)['permissions']['can_edit_cause']);
        $this->actingAs($reader)->put("/app/improvements/{$case->id}/cause", ['cause_analysis' => 'Nei'])->assertForbidden();
        // Edit in IT and view in HR from two roles: no edit in HR.
        $this->actingAs($elsewhere)->put("/app/improvements/{$case->id}/cause", ['cause_analysis' => 'Nei'])->assertForbidden();
        $this->actingAs($this->handler($customer, $hr))->put("/app/improvements/{$theirs->id}/cause", ['cause_analysis' => 'Nei'])->assertNotFound();

        $this->assertNull($case->fresh()->cause_analysis);
        $this->assertNull($theirs->fresh()->cause_analysis);
    }

    // ---------------------------------------------------------------------
    // Model and database
    // ---------------------------------------------------------------------

    public function test_a_new_tiltak_is_planned_whatever_the_form_says(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $owner = $this->editor($customer, $hr);
        $case = $this->improvementCase($customer, $hr, 'Sak', $user);

        $this->actingAs($user)->post($this->url($case), [
            'status' => ImprovementAction::STATUS_COMPLETED,
            'completion_note' => 'Smuglet inn',
            'completed_at' => now()->toIso8601String(),
            'customer_id' => 999999,
            'improvement_case_id' => 999999,
        ] + $this->actionPayload($owner, '  Oppdater rutinen  ', '2026-11-15'))
            ->assertRedirect()->assertSessionHas('success', 'Tiltaket er lagt inn.');

        $action = ImprovementAction::query()->where('improvement_case_id', $case->id)->sole();
        $this->assertSame(ImprovementAction::STATUS_PLANNED, $action->status);
        $this->assertSame('Oppdater rutinen', $action->title);
        $this->assertSame('Hva tiltaket innebærer.', $action->description);
        $this->assertSame((int) $owner->id, (int) $action->owner_user_id);
        $this->assertSame('2026-11-15', $action->due_date->format('Y-m-d'));
        $this->assertSame((int) $customer->id, (int) $action->customer_id);
        $this->assertSame((int) $user->id, (int) $action->created_by);
        $this->assertNull($action->completion_note);
        $this->assertNull($action->completed_at);
        $this->assertSame(0, ImprovementActionStatusChange::query()->where('improvement_action_id', $action->id)->count());

        $row = $this->props($user, $case)['actions'][0];
        $this->assertSame(['Oppdater rutinen', 'planned', $owner->name, '2026-11-15', []], [
            $row['title'], $row['status'], $row['owner_name'], $row['due_date'], $row['history'],
        ]);

        // A blank description is no description.
        $this->actingAs($user)->post($this->url($case), ['description' => '  '] + $this->actionPayload($owner, 'Uten beskrivelse'));
        $this->assertNull(ImprovementAction::query()->where('title', 'Uten beskrivelse')->sole()->description);
    }

    public function test_title_owner_and_frist_are_required_with_norwegian_messages(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $case = $this->improvementCase($customer, $hr, 'Sak', $user);

        $this->actingAs($user)->post($this->url($case), ['title' => '', 'description' => '', 'owner_user_id' => '', 'due_date' => ''])
            ->assertSessionHasErrors([
                'title' => 'Tittel må fylles ut.',
                'owner_user_id' => 'Ansvarlig må fylles ut.',
                'due_date' => 'Frist må fylles ut.',
            ])
            ->assertSessionDoesntHaveErrors(['description']);

        $this->actingAs($user)->post($this->url($case), ['due_date' => '15.11.2026'] + $this->actionPayload($user))
            ->assertSessionHasErrors(['due_date' => 'Frist må være en gyldig dato.']);

        $this->assertSame(0, ImprovementAction::query()->where('improvement_case_id', $case->id)->count());
    }

    public function test_only_four_statuses_exist_and_the_database_keeps_the_completion_in_step(): void
    {
        ['customer' => $customer] = $this->context();
        $case = $this->improvementCase($customer, $this->area($customer, 'HR'), 'Sak');
        $action = $this->improvementAction($case, 'Tiltak');

        $this->assertSame(['planned', 'in_progress', 'completed', 'cancelled'], ImprovementAction::STATUSES);

        $fresh = $action->fresh();
        $fresh->status = 'done';
        try {
            $fresh->save();
            $this->fail('An unknown status must not be saved.');
        } catch (DomainException) {
        }

        foreach ([
            ['status' => 'done'],
            // Completed without saying what was done.
            ['status' => ImprovementAction::STATUS_COMPLETED],
            ['status' => ImprovementAction::STATUS_COMPLETED, 'completed_at' => now(), 'completion_note' => '  '],
            // Not completed, but carrying a completion.
            ['completed_at' => now()],
            ['completion_note' => 'Ferdig'],
            ['status' => ImprovementAction::STATUS_CANCELLED, 'completed_at' => now(), 'completion_note' => 'Ferdig'],
            ['title' => '  '],
            ['due_date' => null],
        ] as $attributes) {
            try {
                DB::transaction(fn () => DB::table('improvement_actions')->where('id', $action->id)->update($attributes));
                $this->fail('Refused: '.json_encode($attributes));
            } catch (QueryException) {
            }
        }

        foreach (['status', 'completed_at', 'completed_by_user_id', 'completion_note'] as $attribute) {
            $this->assertNotContains($attribute, (new ImprovementAction)->getFillable());
        }

        $this->assertSame(ImprovementAction::STATUS_PLANNED, $action->fresh()->status);
    }

    public function test_a_tiltak_belongs_to_a_case_of_the_same_customer(): void
    {
        ['customer' => $customer] = $this->context();
        ['customer' => $other] = $this->context();
        $theirs = $this->improvementCase($other, $this->area($other, 'HR'), 'Andres sak');

        try {
            ImprovementAction::query()->create([
                'customer_id' => $customer->id, 'improvement_case_id' => $theirs->id, 'title' => 'Kapret', 'due_date' => '2030-01-01',
            ]);
            $this->fail('The model must refuse a tiltak under another customer\'s case.');
        } catch (DomainException) {
        }

        $this->expectException(QueryException::class);
        DB::table('improvement_actions')->insert([
            'customer_id' => $customer->id, 'improvement_case_id' => $theirs->id, 'title' => 'Kapret',
            'due_date' => '2030-01-01', 'status' => 'planned', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_frist_after_the_cases_frist_is_allowed_and_only_flagged(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $case = $this->improvementCase($customer, $hr, 'Sak', $user);
        $case->forceFill(['due_date' => '2026-11-01'])->save();

        $this->actingAs($user)->post($this->url($case), $this->actionPayload($user, 'Senere', '2026-12-01'))->assertSessionHasNoErrors();
        $this->actingAs($user)->post($this->url($case), $this->actionPayload($user, 'Samme dag', '2026-11-01'))->assertSessionHasNoErrors();

        $rows = collect($this->props($user, $case)['actions'])->keyBy('title');
        $this->assertTrue($rows['Senere']['is_after_case_due_date']);
        $this->assertFalse($rows['Samme dag']['is_after_case_due_date']);
    }

    // ---------------------------------------------------------------------
    // Lifecycle
    // ---------------------------------------------------------------------

    public function test_start_moves_a_planned_tiltak_into_progress_without_a_note_and_leaves_the_case_alone(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $case = $this->improvementCase($customer, $hr, 'Sak', $user);
        $action = $this->improvementAction($case, 'Tiltak', $user);

        Carbon::setTestNow('2026-10-05 09:00:00');
        $this->actingAs($user)->post($this->url($case, $action, '/start'))
            ->assertRedirect()->assertSessionHas('success', 'Tiltaket er startet.');

        $this->assertSame(ImprovementAction::STATUS_IN_PROGRESS, $action->fresh()->status);
        $change = ImprovementActionStatusChange::query()->where('improvement_action_id', $action->id)->sole();
        $this->assertSame(['planned', 'in_progress', null, (int) $user->id, '2026-10-05 09:00:00'], [
            $change->from_status, $change->to_status, $change->note, (int) $change->changed_by_user_id, $change->changed_at->format('Y-m-d H:i:s'),
        ]);

        // The case's lifecycle is its own: still open, with no history.
        $this->assertSame(ImprovementCase::STATUS_OPEN, $case->fresh()->status);
        $this->assertSame(0, ImprovementCaseStatusChange::query()->where('improvement_case_id', $case->id)->count());
    }

    public function test_completing_from_planned_or_in_progress_requires_what_was_done(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $case = $this->improvementCase($customer, $hr, 'Sak', $user);
        $planned = $this->improvementAction($case, 'Planlagt', $user);
        $started = $this->improvementAction($case, 'Startet', $user);
        $this->actingAs($user)->post($this->url($case, $started, '/start'));

        foreach ([$planned, $started] as $action) {
            $this->actingAs($user)->post($this->url($case, $action, '/complete'), ['completion_note' => ''])
                ->assertSessionHasErrors(['completion_note' => 'Skriv hva som ble gjort.']);
            $this->actingAs($user)->post($this->url($case, $action, '/complete'), ['completion_note' => '   '])
                ->assertSessionHasErrors(['completion_note' => 'Skriv hva som ble gjort.']);
            $this->assertTrue($action->fresh()->isActive(), $action->title);
        }

        Carbon::setTestNow('2026-10-05 14:00:00');
        foreach ([$planned, $started] as $action) {
            $this->actingAs($user)->post($this->url($case, $action, '/complete'), ['completion_note' => " Oppdaterte rutinen.\n"])
                ->assertRedirect()->assertSessionHas('success', 'Tiltaket er fullført.');

            $fresh = $action->fresh();
            $this->assertSame(ImprovementAction::STATUS_COMPLETED, $fresh->status);
            $this->assertSame('Oppdaterte rutinen.', $fresh->completion_note);
            $this->assertSame('2026-10-05 14:00:00', $fresh->completed_at->format('Y-m-d H:i:s'));
            $this->assertSame((int) $user->id, (int) $fresh->completed_by_user_id);

            $last = ImprovementActionStatusChange::query()->where('improvement_action_id', $action->id)->orderByDesc('id')->first();
            $this->assertSame(['completed', 'Oppdaterte rutinen.'], [$last->to_status, $last->note]);
        }

        $row = collect($this->props($user, $case)['actions'])->firstWhere('title', 'Startet');
        $this->assertSame(['Oppdaterte rutinen.', $user->name], [$row['completion_note'], $row['completed_by_name']]);
        $this->assertNotNull($row['completed_at']);
    }

    public function test_cancelling_requires_a_reason_and_leaves_no_completion(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $case = $this->improvementCase($customer, $hr, 'Sak', $user);
        $action = $this->improvementAction($case, 'Tiltak', $user);

        $this->actingAs($user)->post($this->url($case, $action, '/cancel'), ['reason' => ''])
            ->assertSessionHasErrors(['reason' => 'Begrunnelse må fylles ut.']);
        $this->assertSame(ImprovementAction::STATUS_PLANNED, $action->fresh()->status);

        $this->actingAs($user)->post($this->url($case, $action, '/cancel'), ['reason' => 'Dekkes av et annet tiltak.'])
            ->assertRedirect()->assertSessionHas('success', 'Tiltaket er avbrutt.');

        $fresh = $action->fresh();
        $this->assertSame(ImprovementAction::STATUS_CANCELLED, $fresh->status);
        $this->assertNull($fresh->completion_note);
        $this->assertNull($fresh->completed_at);
        $this->assertSame('Dekkes av et annet tiltak.', ImprovementActionStatusChange::query()->where('improvement_action_id', $action->id)->sole()->note);
    }

    public function test_reopening_requires_a_reason_clears_the_completion_and_the_first_completion_survives(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $kari = $this->editor($customer, $hr);
        $ola = $this->editor($customer, $hr);
        $case = $this->improvementCase($customer, $hr, 'Sak', $kari);
        $action = $this->improvementAction($case, 'Tiltak', $kari);

        Carbon::setTestNow('2026-10-04 09:00:00');
        $this->actingAs($ola)->post($this->url($case, $action, '/start'));
        Carbon::setTestNow('2026-10-05 09:00:00');
        $this->actingAs($kari)->post($this->url($case, $action, '/complete'), ['completion_note' => 'Oppdaterte rutinen og informerte teamet.']);

        $this->actingAs($ola)->post($this->url($case, $action, '/reopen'), ['reason' => ''])
            ->assertSessionHasErrors(['reason' => 'Begrunnelse må fylles ut.']);
        $this->assertSame(ImprovementAction::STATUS_COMPLETED, $action->fresh()->status);

        Carbon::setTestNow('2026-10-06 09:00:00');
        $this->actingAs($ola)->post($this->url($case, $action, '/reopen'), ['reason' => 'Teamet på natt ble ikke informert.'])
            ->assertRedirect()->assertSessionHas('success', 'Tiltaket er gjenåpnet.');

        $fresh = $action->fresh();
        $this->assertSame(ImprovementAction::STATUS_PLANNED, $fresh->status);
        $this->assertNull($fresh->completion_note);
        $this->assertNull($fresh->completed_at);
        $this->assertNull($fresh->completed_by_user_id);

        Carbon::setTestNow('2026-10-07 09:00:00');
        $this->actingAs($ola)->post($this->url($case, $action, '/complete'), ['completion_note' => 'Informerte nattskiftet.']);

        $this->assertSame([
            ['planned', 'in_progress', null, (int) $ola->id, '2026-10-04 09:00:00'],
            ['in_progress', 'completed', 'Oppdaterte rutinen og informerte teamet.', (int) $kari->id, '2026-10-05 09:00:00'],
            ['completed', 'planned', 'Teamet på natt ble ikke informert.', (int) $ola->id, '2026-10-06 09:00:00'],
            ['planned', 'completed', 'Informerte nattskiftet.', (int) $ola->id, '2026-10-07 09:00:00'],
        ], ImprovementActionStatusChange::query()->where('improvement_action_id', $action->id)->orderBy('id')->get()
            ->map(fn (ImprovementActionStatusChange $change): array => [
                $change->from_status, $change->to_status, $change->note, (int) $change->changed_by_user_id, $change->changed_at->format('Y-m-d H:i:s'),
            ])->all());

        // Newest first on the page; the current completion is the second one.
        $row = $this->props($kari, $case)['actions'][0];
        $this->assertSame(['completed', 'planned', 'completed', 'in_progress'], array_column($row['history'], 'to_status'));
        $this->assertSame([$kari->name, 'Oppdaterte rutinen og informerte teamet.'], [$row['history'][2]['changed_by_name'], $row['history'][2]['note']]);
        $this->assertSame(['Informerte nattskiftet.', $ola->name], [$row['completion_note'], $row['completed_by_name']]);
    }

    public function test_a_change_the_tiltak_is_not_in_a_state_for_is_refused_and_writes_nothing(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $case = $this->improvementCase($customer, $hr, 'Sak', $user);
        $message = 'Tiltaket har allerede fått en annen status. Last siden på nytt.';

        $planned = $this->improvementAction($case, 'Planlagt', $user);
        $this->actingAs($user)->post($this->url($case, $planned, '/reopen'), ['reason' => 'Igjen'])->assertSessionHasErrors(['reason' => $message]);

        $started = $this->improvementAction($case, 'Startet', $user);
        $this->actingAs($user)->post($this->url($case, $started, '/start'));
        // A double click on Start tiltak.
        $this->actingAs($user)->post($this->url($case, $started, '/start'))->assertSessionHasErrors(['action' => $message]);
        $this->actingAs($user)->post($this->url($case, $started, '/reopen'), ['reason' => 'Igjen'])->assertSessionHasErrors(['reason' => $message]);

        $completed = $this->improvementAction($case, 'Fullført', $user);
        $this->actingAs($user)->post($this->url($case, $completed, '/complete'), ['completion_note' => 'Gjort']);
        $this->actingAs($user)->post($this->url($case, $completed, '/complete'), ['completion_note' => 'Gjort igjen'])->assertSessionHasErrors(['completion_note' => $message]);
        $this->actingAs($user)->post($this->url($case, $completed, '/cancel'), ['reason' => 'Nei'])->assertSessionHasErrors(['reason' => $message]);
        $this->actingAs($user)->post($this->url($case, $completed, '/start'))->assertSessionHasErrors(['action' => $message]);

        $cancelled = $this->improvementAction($case, 'Avbrutt', $user);
        $this->actingAs($user)->post($this->url($case, $cancelled, '/cancel'), ['reason' => 'Unødvendig']);
        $this->actingAs($user)->post($this->url($case, $cancelled, '/complete'), ['completion_note' => 'Gjort'])->assertSessionHasErrors(['completion_note' => $message]);

        $count = fn (ImprovementAction $action): int => ImprovementActionStatusChange::query()->where('improvement_action_id', $action->id)->count();
        $this->assertSame([0, 1, 1, 1], [$count($planned), $count($started), $count($completed), $count($cancelled)]);
        $this->assertSame('Gjort', $completed->fresh()->completion_note);

        // The database names the transitions too.
        $row = fn (string $from, string $to, ?string $note): array => [
            'customer_id' => $customer->id, 'improvement_action_id' => $planned->id, 'from_status' => $from,
            'to_status' => $to, 'note' => $note, 'changed_at' => now(),
        ];
        foreach ([
            $row('planned', 'completed', null),
            $row('planned', 'cancelled', ' '),
            $row('completed', 'planned', ''),
            $row('in_progress', 'planned', 'Tilbake'),
            $row('completed', 'in_progress', 'Fordi'),
            $row('completed', 'cancelled', 'Fordi'),
            $row('planned', 'planned', 'Fordi'),
            $row('planned', 'done', 'Fordi'),
        ] as $attributes) {
            try {
                DB::transaction(fn () => DB::table('improvement_action_status_changes')->insert($attributes));
                $this->fail('Refused: '.json_encode($attributes));
            } catch (QueryException) {
            }
        }
        $this->assertSame(0, $count($planned));
    }

    public function test_every_change_locks_the_case_and_then_the_tiltak(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $case = $this->improvementCase($customer, $hr, 'Sak', $user);
        $action = $this->improvementAction($case, 'Tiltak', $user);

        $locks = [];
        DB::listen(function ($query) use (&$locks): void {
            if (str_contains($query->sql, 'for update')) {
                $locks[] = str_contains($query->sql, 'from "improvement_actions"') ? 'action' : (str_contains($query->sql, 'from "improvement_cases"') ? 'case' : 'other');
            }
        });

        $this->actingAs($user)->post($this->url($case, $action, '/start'));
        $this->actingAs($user)->post($this->url($case, $action, '/complete'), ['completion_note' => 'Gjort']);
        $this->actingAs($user)->post($this->url($case, $action, '/reopen'), ['reason' => 'Igjen']);
        $this->actingAs($user)->post($this->url($case, $action, '/cancel'), ['reason' => 'Unødvendig']);

        $this->assertSame(['case', 'action', 'case', 'action', 'case', 'action', 'case', 'action'], $locks);
    }

    public function test_a_tiltak_is_edited_while_it_is_to_be_done_and_reopened_before_it_is_edited_again(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $other = $this->editor($customer, $hr);
        $case = $this->improvementCase($customer, $hr, 'Sak', $user);
        $action = $this->improvementAction($case, 'Tiltak', $user);

        $this->actingAs($user)->patch($this->url($case, $action), ['status' => 'completed'] + $this->actionPayload($other, 'Endret', '2026-12-24'))
            ->assertRedirect()->assertSessionHas('success', 'Tiltaket er oppdatert.');
        $fresh = $action->fresh();
        $this->assertSame(['Endret', (int) $other->id, '2026-12-24', ImprovementAction::STATUS_PLANNED], [
            $fresh->title, (int) $fresh->owner_user_id, $fresh->due_date->format('Y-m-d'), $fresh->status,
        ]);

        $this->actingAs($user)->post($this->url($case, $action, '/start'));
        $this->actingAs($user)->patch($this->url($case, $action), $this->actionPayload($other, 'Under arbeid'))->assertSessionHasNoErrors();
        $this->assertSame('Under arbeid', $action->fresh()->title);

        $this->actingAs($user)->post($this->url($case, $action, '/complete'), ['completion_note' => 'Gjort']);
        $this->actingAs($user)->patch($this->url($case, $action), $this->actionPayload($other, 'Etter fullføring'))
            ->assertSessionHasErrors(['title' => 'Gjenåpne tiltaket før du endrer det.']);
        $this->assertSame('Under arbeid', $action->fresh()->title);

        $permissions = $this->props($user, $case)['actions'][0]['permissions'];
        $this->assertSame(
            ['can_edit' => false, 'can_start' => false, 'can_complete' => false, 'can_cancel' => false, 'can_reopen' => true, 'can_delete' => false],
            $permissions,
        );
    }

    public function test_tiltak_are_left_as_they_stood_while_the_case_is_ended(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->handler($customer, $hr);
        $case = $this->improvementCase($customer, $hr, 'Sak', $user);
        $done = $this->improvementAction($case, 'Ferdig', $user);
        $this->actingAs($user)->post($this->url($case, $done, '/complete'), ['completion_note' => 'Gjort']);
        $open = $this->improvementAction($case, 'Åpent', $user);

        // Avbryt sak is not held back by open tiltak; only Lukk is.
        $this->actingAs($user)->post("/app/improvements/{$case->id}/cancel", ['reason' => 'Duplikat'])->assertSessionHasNoErrors();
        $message = 'Saken er lukket eller avbrutt. Gjenåpne saken før du endrer tiltakene.';

        $this->actingAs($user)->post($this->url($case), $this->actionPayload($user))->assertSessionHasErrors(['title' => $message]);
        $this->actingAs($user)->patch($this->url($case, $open), $this->actionPayload($user, 'Endret'))->assertSessionHasErrors(['title' => $message]);
        $this->actingAs($user)->post($this->url($case, $open, '/start'))->assertSessionHasErrors(['action' => $message]);
        $this->actingAs($user)->post($this->url($case, $open, '/complete'), ['completion_note' => 'Gjort'])->assertSessionHasErrors(['completion_note' => $message]);
        $this->actingAs($user)->post($this->url($case, $done, '/reopen'), ['reason' => 'Igjen'])->assertSessionHasErrors(['reason' => $message]);
        $this->actingAs($user)->delete($this->url($case, $open))->assertSessionHas('error', $message);

        $this->assertSame(2, ImprovementAction::query()->where('improvement_case_id', $case->id)->count());
        $this->assertSame([ImprovementAction::STATUS_COMPLETED, ImprovementAction::STATUS_PLANNED], [$done->fresh()->status, $open->fresh()->status]);

        $props = $this->props($user, $case);
        $this->assertFalse($props['permissions']['can_manage_actions']);
        $this->assertSame([], $props['action_owner_options']);
        foreach ($props['actions'] as $row) {
            $this->assertSame([], array_filter($row['permissions']), $row['title']);
        }

        // Reopening the case lets them be worked again.
        $this->actingAs($user)->post("/app/improvements/{$case->id}/reopen", ['reason' => 'Ikke duplikat likevel']);
        $this->actingAs($user)->post($this->url($case, $open, '/start'))->assertSessionHasNoErrors();
        $this->assertSame(ImprovementAction::STATUS_IN_PROGRESS, $open->fresh()->status);
    }

    // ---------------------------------------------------------------------
    // History
    // ---------------------------------------------------------------------

    public function test_history_cannot_be_changed_or_deleted_and_a_deleted_author_leaves_it_in_place(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $author = $this->editor($customer, $hr);
        $case = $this->improvementCase($customer, $hr, 'Sak', $user);
        $action = $this->improvementAction($case, 'Tiltak', $user);
        $this->actingAs($author)->post($this->url($case, $action, '/complete'), ['completion_note' => 'Original']);
        $change = ImprovementActionStatusChange::query()->where('improvement_action_id', $action->id)->sole();

        foreach ([fn () => $change->update(['note' => 'Omskrevet']), fn () => $change->delete()] as $write) {
            try {
                $write();
                $this->fail('A history row must not be changed or deleted.');
            } catch (LogicException) {
            }
        }

        foreach (['note' => 'Omskrevet', 'to_status' => 'cancelled', 'changed_at' => now()->subYear(), 'changed_by_user_id' => $user->id] as $column => $value) {
            try {
                DB::transaction(fn () => DB::table('improvement_action_status_changes')->where('id', $change->id)->update([$column => $value]));
                $this->fail("The database must refuse changing {$column}.");
            } catch (QueryException) {
            }
        }

        // The foreign key nulls the author: the one change the trigger lets through.
        $author->delete();

        $fresh = $change->fresh();
        $this->assertSame('Original', $fresh->note);
        $this->assertNull($fresh->changed_by_user_id);
        $this->assertNull($action->fresh()->completed_by_user_id);
        $this->assertSame('Original', $action->fresh()->completion_note);
    }

    // ---------------------------------------------------------------------
    // Access
    // ---------------------------------------------------------------------

    public function test_view_without_edit_sees_tiltak_but_cannot_change_them(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $editor = $this->editor($customer, $hr);
        $case = $this->improvementCase($customer, $hr, 'Sak', $editor);
        $action = $this->improvementAction($case, 'Tiltak', $editor);

        $reader = $this->member($customer);
        $this->grant($customer, $reader, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$hr]);
        // improvement.close decides about the case, not its tiltak.
        $closer = $this->member($customer);
        $this->grant($customer, $closer, [CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::IMPROVEMENT_CLOSE, CustomerPermissionCatalog::IMPROVEMENT_DELETE], [$hr]);

        foreach ([$reader, $closer] as $user) {
            $props = $this->props($user, $case);
            $this->assertSame(['Tiltak'], array_column($props['actions'], 'title'));
            $this->assertFalse($props['permissions']['can_manage_actions']);
            $this->assertSame([], $props['action_owner_options']);
            $this->assertSame([], array_filter($props['actions'][0]['permissions']));

            $this->actingAs($user)->post($this->url($case), $this->actionPayload($editor))->assertForbidden();
            $this->actingAs($user)->patch($this->url($case, $action), $this->actionPayload($editor, 'Endret'))->assertForbidden();
            $this->actingAs($user)->post($this->url($case, $action, '/start'))->assertForbidden();
            $this->actingAs($user)->post($this->url($case, $action, '/complete'), ['completion_note' => 'Gjort'])->assertForbidden();
            $this->actingAs($user)->post($this->url($case, $action, '/cancel'), ['reason' => 'Nei'])->assertForbidden();
            $this->actingAs($user)->post($this->url($case, $action, '/reopen'), ['reason' => 'Nei'])->assertForbidden();
            $this->actingAs($user)->delete($this->url($case, $action))->assertForbidden();
        }

        $this->assertSame(['Tiltak', ImprovementAction::STATUS_PLANNED], [$action->fresh()->title, $action->fresh()->status]);
        $this->assertSame(1, ImprovementAction::query()->where('improvement_case_id', $case->id)->count());

        $editorProps = $this->props($editor, $case);
        $this->assertTrue($editorProps['permissions']['can_manage_actions']);
        $this->assertSame(
            ['can_edit' => true, 'can_start' => true, 'can_complete' => true, 'can_cancel' => true, 'can_reopen' => false, 'can_delete' => true],
            $editorProps['actions'][0]['permissions'],
        );
    }

    public function test_edit_in_the_wrong_area_or_from_another_role_reaches_no_tiltak(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $it = $this->area($customer, 'IT');
        $case = $this->improvementCase($customer, $hr, 'Sak');
        $action = $this->improvementAction($case, 'Tiltak');

        // Edit in IT only: the HR case is not even visible.
        $wrongArea = $this->editor($customer, $it);
        $this->actingAs($wrongArea)->get("/app/improvements/{$case->id}")->assertNotFound();
        $this->actingAs($wrongArea)->post($this->url($case, $action, '/start'))->assertNotFound();
        $this->actingAs($wrongArea)->post($this->url($case), $this->actionPayload($wrongArea))->assertNotFound();

        // View in HR from one role, edit in IT from another: they are not combined.
        $mixed = $this->editor($customer, $it);
        $this->grant($customer, $mixed, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$hr]);
        $this->actingAs($mixed)->post($this->url($case, $action, '/start'))->assertForbidden();
        $this->actingAs($mixed)->post($this->url($case), $this->actionPayload($mixed))->assertForbidden();

        // «Alle» reaches the case's area.
        $all = $this->member($customer);
        $this->grantAll($customer, $all, [CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::IMPROVEMENT_EDIT]);
        $this->actingAs($all)->post($this->url($case, $action, '/start'))->assertSessionHasNoErrors();

        $this->assertSame(ImprovementAction::STATUS_IN_PROGRESS, $action->fresh()->status);
        $this->assertSame(1, ImprovementAction::query()->where('improvement_case_id', $case->id)->count());
    }

    public function test_system_owner_without_a_role_reaches_no_tiltak(): void
    {
        ['customer' => $customer, 'owner' => $systemOwner] = $this->context();
        $hr = $this->area($customer, 'HR');
        $case = $this->improvementCase($customer, $hr, 'Sak');
        $action = $this->improvementAction($case, 'Tiltak');

        $this->actingAs($systemOwner)->get("/app/improvements/{$case->id}")->assertNotFound();
        $this->actingAs($systemOwner)->post($this->url($case), $this->actionPayload($systemOwner))->assertNotFound();
        $this->actingAs($systemOwner)->post($this->url($case, $action, '/start'))->assertNotFound();
        $this->actingAs($systemOwner)->delete($this->url($case, $action))->assertNotFound();

        $this->assertSame(ImprovementAction::STATUS_PLANNED, $action->fresh()->status);
    }

    public function test_a_tiltak_under_another_customers_or_another_case_is_not_found(): void
    {
        ['customer' => $customer] = $this->context();
        ['customer' => $other] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $mine = $this->improvementCase($customer, $hr, 'Min sak', $user);
        $sibling = $this->improvementCase($customer, $hr, 'Annen sak', $user);
        $siblingAction = $this->improvementAction($sibling, 'Søskentiltak', $user);
        $theirs = $this->improvementCase($other, $this->area($other, 'HR'), 'Andres sak');
        $theirAction = $this->improvementAction($theirs, 'Andres tiltak');

        // Their case and their tiltak.
        $this->actingAs($user)->post($this->url($theirs), $this->actionPayload($user))->assertNotFound();
        $this->actingAs($user)->post($this->url($theirs, $theirAction, '/start'))->assertNotFound();
        // Their tiltak id under my case: the same 404, nothing says it exists.
        foreach ([$theirAction, $siblingAction] as $action) {
            $this->actingAs($user)->post($this->url($mine, $action, '/start'))->assertNotFound();
            $this->actingAs($user)->post($this->url($mine, $action, '/complete'), ['completion_note' => 'Kapret'])->assertNotFound();
            $this->actingAs($user)->patch($this->url($mine, $action), $this->actionPayload($user, 'Kapret'))->assertNotFound();
            $this->actingAs($user)->delete($this->url($mine, $action))->assertNotFound();
        }

        $this->assertSame([ImprovementAction::STATUS_PLANNED, 'Andres tiltak'], [$theirAction->fresh()->status, $theirAction->fresh()->title]);
        $this->assertSame([ImprovementAction::STATUS_PLANNED, 'Søskentiltak'], [$siblingAction->fresh()->status, $siblingAction->fresh()->title]);
        $this->assertSame(0, ImprovementAction::query()->where('improvement_case_id', $theirs->id)->where('id', '!=', $theirAction->id)->count());
    }

    // ---------------------------------------------------------------------
    // Ansvarlig
    // ---------------------------------------------------------------------

    public function test_the_owner_must_be_an_active_person_of_the_customer_who_can_read_the_cases_area(): void
    {
        ['customer' => $customer] = $this->context();
        ['customer' => $other] = $this->context();
        $hr = $this->area($customer, 'HR');
        $it = $this->area($customer, 'IT');
        $user = $this->editor($customer, $hr);
        $case = $this->improvementCase($customer, $hr, 'Sak', $user);
        $message = 'Ansvarlig må være en aktiv bruker som kan se saker i sakens fagområde.';

        $foreigner = $this->member($other);
        $this->grantAll($other, $foreigner, [CustomerPermissionCatalog::IMPROVEMENT_VIEW]);
        $inactive = $this->member($customer);
        $this->grant($customer, $inactive, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$hr]);
        $inactive->forceFill(['is_active' => false])->save();
        $otherArea = $this->member($customer);
        $this->grant($customer, $otherArea, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$it]);
        $noRole = $this->member($customer);

        foreach ([$foreigner, $inactive, $otherArea, $noRole] as $owner) {
            $this->actingAs($user)->post($this->url($case), $this->actionPayload($owner))->assertSessionHasErrors(['owner_user_id' => $message]);
        }
        $this->assertSame(0, ImprovementAction::query()->where('improvement_case_id', $case->id)->count());

        $reader = $this->member($customer);
        $this->grant($customer, $reader, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$hr]);
        $this->actingAs($user)->post($this->url($case), $this->actionPayload($reader))->assertSessionHasNoErrors();
        $action = ImprovementAction::query()->where('improvement_case_id', $case->id)->sole();

        // Editing checks the owner again.
        $this->actingAs($user)->patch($this->url($case, $action), $this->actionPayload($otherArea))->assertSessionHasErrors(['owner_user_id' => $message]);
        $this->assertSame((int) $reader->id, (int) $action->fresh()->owner_user_id);

        // The candidates offered are exactly the people who can read the case's area.
        $offered = array_column($this->props($user, $case)['action_owner_options'], 'id');
        $this->assertContains((int) $reader->id, $offered);
        $this->assertContains((int) $user->id, $offered);
        foreach ([$foreigner, $inactive, $otherArea, $noRole] as $owner) {
            $this->assertNotContains((int) $owner->id, $offered);
        }

        // Being responsible gives no access: the owner without a role for the area cannot open the case.
        $this->improvementAction($case, 'Satt direkte', $noRole);
        $this->actingAs($noRole)->get("/app/improvements/{$case->id}")->assertForbidden();
        $this->actingAs($otherArea)->get("/app/improvements/{$case->id}")->assertNotFound();
    }

    public function test_a_deleted_owner_leaves_the_tiltak_without_one(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $owner = $this->editor($customer, $hr);
        $case = $this->improvementCase($customer, $hr, 'Sak', $user);
        $action = $this->improvementAction($case, 'Tiltak', $owner);

        $owner->delete();

        $this->assertNull($action->fresh()->owner_user_id);
        $row = $this->props($user, $case)['actions'][0];
        $this->assertSame(['Tiltak', null, null], [$row['title'], $row['owner_user_id'], $row['owner_name']]);
    }

    // ---------------------------------------------------------------------
    // Delete
    // ---------------------------------------------------------------------

    public function test_only_an_untouched_planned_tiltak_can_be_deleted(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $case = $this->improvementCase($customer, $hr, 'Sak', $user);

        $untouched = $this->improvementAction($case, 'Urørt', $user);
        $this->assertTrue($untouched->isDeletable());
        $this->actingAs($user)->delete($this->url($case, $untouched))
            ->assertRedirect()->assertSessionHas('success', 'Tiltaket er slettet.');
        $this->assertNull($untouched->fresh());

        $started = $this->improvementAction($case, 'Startet', $user);
        $this->actingAs($user)->post($this->url($case, $started, '/start'));
        $completed = $this->improvementAction($case, 'Fullført', $user);
        $this->actingAs($user)->post($this->url($case, $completed, '/complete'), ['completion_note' => 'Gjort']);
        $cancelled = $this->improvementAction($case, 'Avbrutt', $user);
        $this->actingAs($user)->post($this->url($case, $cancelled, '/cancel'), ['reason' => 'Unødvendig']);
        // Planned again, but it carries history.
        $reopened = $this->improvementAction($case, 'Gjenåpnet', $user);
        $this->actingAs($user)->post($this->url($case, $reopened, '/cancel'), ['reason' => 'Unødvendig']);
        $this->actingAs($user)->post($this->url($case, $reopened, '/reopen'), ['reason' => 'Nødvendig likevel']);
        $this->assertSame(ImprovementAction::STATUS_PLANNED, $reopened->fresh()->status);

        $rows = collect($this->props($user, $case)['actions'])->keyBy('title');
        foreach ([$started, $completed, $cancelled, $reopened] as $action) {
            $this->assertFalse($action->fresh()->isDeletable(), $action->title);
            $this->assertFalse($rows[$action->title]['permissions']['can_delete'], $action->title);

            $this->actingAs($user)->delete($this->url($case, $action))
                ->assertRedirect()
                ->assertSessionHas('error', 'Tiltaket kan ikke slettes fordi det har vært startet, fullført eller avbrutt. Bruk «Avbryt tiltak» i stedet.');
            $this->assertNotNull($action->fresh(), $action->title);
        }
    }

    public function test_a_case_with_tiltak_cannot_be_deleted(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->handler($customer, $hr);
        $case = $this->improvementCase($customer, $hr, 'Sak', $user);
        $this->assertTrue($case->isDeletable());

        $this->improvementAction($case, 'Tiltak', $user);

        $this->assertFalse($case->fresh()->isDeletable());
        $this->assertFalse($this->props($user, $case)['permissions']['can_delete']);
        $this->actingAs($user)->delete("/app/improvements/{$case->id}")->assertRedirect()->assertSessionHas('error');
        $this->assertNotNull($case->fresh());
    }

    // ---------------------------------------------------------------------
    // Closing the case
    // ---------------------------------------------------------------------

    public function test_a_case_closes_only_once_every_tiltak_is_completed_or_cancelled(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->handler($customer, $hr);
        $message = 'Saken har tiltak som ikke er ferdig behandlet. Fullfør eller avbryt tiltakene før saken lukkes.';

        // No tiltak: closes as before.
        $none = $this->improvementCase($customer, $hr, 'Uten tiltak', $user);
        $this->actingAs($user)->post("/app/improvements/{$none->id}/close", ['closing_note' => 'Ferdig'])->assertSessionHasNoErrors();
        $this->assertSame(ImprovementCase::STATUS_CLOSED, $none->fresh()->status);

        // A planned and an in-progress tiltak each hold it back, from open and from under arbeid.
        $case = $this->improvementCase($customer, $hr, 'Med tiltak', $user);
        $first = $this->improvementAction($case, 'Første', $user);
        $second = $this->improvementAction($case, 'Andre', $user);

        $this->actingAs($user)->post("/app/improvements/{$case->id}/close", ['closing_note' => 'Ferdig'])
            ->assertSessionHasErrors(['closing_note' => $message]);
        $this->actingAs($user)->post("/app/improvements/{$case->id}/start");
        $this->actingAs($user)->post($this->url($case, $first, '/start'));
        $this->actingAs($user)->post($this->url($case, $second, '/complete'), ['completion_note' => 'Gjort']);
        $this->actingAs($user)->post("/app/improvements/{$case->id}/close", ['closing_note' => 'Ferdig'])
            ->assertSessionHasErrors(['closing_note' => $message]);

        $this->assertSame(ImprovementCase::STATUS_IN_PROGRESS, $case->fresh()->status);
        $this->assertSame(['in_progress'], ImprovementCaseStatusChange::query()->where('improvement_case_id', $case->id)->pluck('to_status')->all());

        // Completed + cancelled: closes.
        $this->actingAs($user)->post($this->url($case, $first, '/cancel'), ['reason' => 'Ikke nødvendig']);
        $this->actingAs($user)->post("/app/improvements/{$case->id}/close", ['closing_note' => 'Ferdig'])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Saken er lukket.');
        $this->assertSame(ImprovementCase::STATUS_CLOSED, $case->fresh()->status);

        // All completed: closes.
        $allDone = $this->improvementCase($customer, $hr, 'Alle fullført', $user);
        foreach (['A', 'B'] as $title) {
            $action = $this->improvementAction($allDone, $title, $user);
            $this->actingAs($user)->post($this->url($allDone, $action, '/complete'), ['completion_note' => 'Gjort']);
        }
        $this->actingAs($user)->post("/app/improvements/{$allDone->id}/close", ['closing_note' => 'Ferdig'])->assertSessionHasNoErrors();
        $this->assertSame(ImprovementCase::STATUS_CLOSED, $allDone->fresh()->status);
    }

    // ---------------------------------------------------------------------
    // Frist passert
    // ---------------------------------------------------------------------

    public function test_frist_passert_is_only_for_a_tiltak_still_to_be_done_after_its_frist_day(): void
    {
        ['customer' => $customer] = $this->context();
        $hr = $this->area($customer, 'HR');
        $user = $this->editor($customer, $hr);
        $case = $this->improvementCase($customer, $hr, 'Sak', $user);

        $tomorrow = $this->improvementAction($case, 'I morgen', $user, '2026-10-06');
        $today = $this->improvementAction($case, 'I dag', $user, '2026-10-05');
        $yesterday = $this->improvementAction($case, 'I går', $user, '2026-10-04');
        $startedLate = $this->improvementAction($case, 'Startet, i går', $user, '2026-10-04');
        $completedLate = $this->improvementAction($case, 'Fullført, i går', $user, '2026-10-04');
        $cancelledLate = $this->improvementAction($case, 'Avbrutt, i går', $user, '2026-10-04');

        Carbon::setTestNow('2026-10-05 23:59:00');
        $this->actingAs($user)->post($this->url($case, $startedLate, '/start'));
        $this->actingAs($user)->post($this->url($case, $completedLate, '/complete'), ['completion_note' => 'Gjort']);
        $this->actingAs($user)->post($this->url($case, $cancelledLate, '/cancel'), ['reason' => 'Unødvendig']);

        $expected = [
            'I morgen' => false,
            'I dag' => false,
            'I går' => true,
            'Startet, i går' => true,
            'Fullført, i går' => false,
            'Avbrutt, i går' => false,
        ];

        $this->assertSame($expected, collect($this->props($user, $case)['actions'])->pluck('is_overdue', 'title')->all());
        foreach ([$tomorrow, $today, $yesterday] as $action) {
            $this->assertSame($expected[$action->title], $action->fresh()->isOverdue(), $action->title);
        }

        // Nothing is stored: the next day, today's frist has passed too.
        Carbon::setTestNow('2026-10-06 00:01:00');
        $this->assertTrue($today->fresh()->isOverdue());
        $this->assertFalse($tomorrow->fresh()->isOverdue());
    }
}
