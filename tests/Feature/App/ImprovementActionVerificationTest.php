<?php

namespace Tests\Feature\App;

use App\Models\ImprovementAction;
use App\Models\ImprovementActionStatusChange;
use App\Models\ImprovementActionVerification;
use App\Models\ImprovementCase;
use App\Models\User;
use App\Services\Improvements\ImprovementActionVerificationResolver;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use LogicException;
use Tests\Concerns\CreatesImprovementCaseScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Effektverifisering of tiltak, and what it does to ending the case.
 *
 * What these tests defend:
 *
 *  - Only a completed tiltak under an active case is verified, Effekt bekreftet or Ikke effektivt,
 *    always with a comment. The tiltak stays completed either way; nothing reopens on its own.
 *  - A verification judges one completion: the status change that completed the tiltak. A newer
 *    judgement of the same completion is current and the older one stays; after a reopening the
 *    old judgement is history and the new completion awaits its own.
 *  - Verifications are immutable, in the model and in the database, which also refuses one that
 *    points at another tiltak's, another customer's or a non-completing status change.
 *  - improvement.close verifies; improvement.edit alone does not. Permission and area pair per role;
 *    System Owner and other customers reach nothing.
 *  - Lukk sak needs every tiltak completed or cancelled and every completed one judged Effekt
 *    bekreftet. Avbryt sak needs every tiltak completed or cancelled, but no verification.
 *  - Frist passert is never shown under an ended case.
 */
class ImprovementActionVerificationTest extends TestCase
{
    use CreatesImprovementCaseScenarios;
    use UsesProjectPostgresConnection;

    private const NOT_FINISHED = 'Saken har tiltak som ikke er ferdig behandlet. Fullfør eller avbryt tiltakene før saken avsluttes.';

    private const NOT_VERIFIED = 'Ett eller flere fullførte tiltak er ikke effektverifisert. Verifiser effekten før saken lukkes.';

    private const NOT_EFFECTIVE = 'Ett eller flere tiltak er vurdert som ikke effektive. Følg opp tiltakene før saken lukkes.';

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
    // Verifying
    // ---------------------------------------------------------------------

    public function test_a_completed_tiltak_is_verified_and_stays_completed_whatever_the_result(): void
    {
        ['user' => $user, 'case' => $case] = $this->world();
        $works = $this->completed($user, $case, 'Virker');
        $fails = $this->completed($user, $case, 'Virker ikke');

        $this->verify($user, $case, $works, 'effective', 'Feilraten har vært under terskelverdien i fire uker etter endringen.')
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Effekten er bekreftet.');
        $this->verify($user, $case, $fails, 'not_effective', 'Samme feil har oppstått på nytt etter at tiltaket ble gjennomført.')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Effekten er ikke bekreftet. Gjenåpne tiltaket dersom det må arbeides videre med.');

        // Neither result moves the tiltak: no reopening behind the person's back.
        $this->assertSame([ImprovementAction::STATUS_COMPLETED, ImprovementAction::STATUS_COMPLETED], [$works->fresh()->status, $fails->fresh()->status]);
        $this->assertSame(1, ImprovementActionStatusChange::query()->where('improvement_action_id', $fails->id)->count());

        $row = ImprovementActionVerification::query()->where('improvement_action_id', $works->id)->sole();
        $completion = ImprovementActionStatusChange::query()->where('improvement_action_id', $works->id)->sole();
        $this->assertSame(
            [(int) $case->customer_id, (int) $completion->id, 'effective', (int) $user->id],
            [(int) $row->customer_id, (int) $row->completion_status_change_id, $row->result, (int) $row->verified_by_user_id],
        );
        $this->assertNotNull($row->verified_at);

        $actions = collect($this->props($user, $case)['actions'])->keyBy('title');
        $this->assertSame('effective', $actions['Virker']['verification']['current']['result']);
        $this->assertFalse($actions['Virker']['verification']['awaiting']);
        $this->assertSame('not_effective', $actions['Virker ikke']['verification']['current']['result']);
        $this->assertSame('Samme feil har oppstått på nytt etter at tiltaket ble gjennomført.', $actions['Virker ikke']['verification']['current']['note']);
        $this->assertSame($user->name, $actions['Virker ikke']['verification']['current']['verified_by_name']);
        $this->assertFalse($actions['Virker ikke']['verification']['current']['earlier_completion']);
    }

    public function test_only_a_completed_tiltak_can_be_verified(): void
    {
        ['user' => $user, 'case' => $case] = $this->world();
        $planned = $this->improvementAction($case, 'Planlagt', $user);
        $started = $this->improvementAction($case, 'Startet', $user);
        $this->actingAs($user)->post($this->url($case, $started, '/start'));
        $cancelled = $this->improvementAction($case, 'Avbrutt', $user);
        $this->actingAs($user)->post($this->url($case, $cancelled, '/cancel'), ['reason' => 'Unødvendig']);

        foreach ([$planned, $started, $cancelled] as $action) {
            $this->verify($user, $case, $action, 'effective', 'Virket')
                ->assertSessionHasErrors(['result' => 'Bare fullførte tiltak kan effektverifiseres. Last siden på nytt.']);
        }

        $this->assertSame(0, ImprovementActionVerification::query()->where('customer_id', $case->customer_id)->count());

        $actions = collect($this->props($user, $case)['actions'])->keyBy('title');
        foreach (['Planlagt', 'Startet', 'Avbrutt'] as $title) {
            $this->assertFalse($actions[$title]['permissions']['can_verify'], $title);
            $this->assertFalse($actions[$title]['verification']['awaiting'], $title);
            $this->assertNull($actions[$title]['verification']['current'], $title);
        }
    }

    public function test_result_and_comment_are_required(): void
    {
        ['user' => $user, 'case' => $case] = $this->world();
        $action = $this->completed($user, $case, 'Tiltak');

        $this->actingAs($user)->post($this->url($case, $action, '/verify'), ['note' => 'Virket'])
            ->assertSessionHasErrors(['result' => 'Velg resultat.']);
        $this->verify($user, $case, $action, 'partially_effective', 'Delvis')
            ->assertSessionHasErrors(['result' => 'Velg resultat.']);
        $this->verify($user, $case, $action, 'effective', '')
            ->assertSessionHasErrors(['note' => 'Kommentar må fylles ut.']);
        $this->verify($user, $case, $action, 'not_effective', '   ')
            ->assertSessionHasErrors(['note' => 'Kommentar må fylles ut.']);

        $this->assertSame(0, ImprovementActionVerification::query()->where('improvement_action_id', $action->id)->count());
    }

    public function test_a_verification_is_never_changed_or_deleted(): void
    {
        ['user' => $user, 'case' => $case] = $this->world();
        $action = $this->completed($user, $case, 'Tiltak');
        $this->verify($user, $case, $action, 'effective', 'Virket');
        $row = ImprovementActionVerification::query()->where('improvement_action_id', $action->id)->sole();

        try {
            $row->forceFill(['result' => 'not_effective'])->save();
            $this->fail('The model let a verification change.');
        } catch (LogicException) {
        }

        try {
            $row->delete();
            $this->fail('The model let a verification be deleted.');
        } catch (LogicException) {
        }

        foreach (['result' => 'not_effective', 'note' => 'Omskrevet', 'verified_at' => now()->subYear()] as $column => $value) {
            $this->assertRefused(fn () => DB::table('improvement_action_verifications')->where('id', $row->id)->update([$column => $value]));
        }

        // Only the foreign key nulling a deleted verifier gets through.
        DB::table('improvement_action_verifications')->where('id', $row->id)->update(['verified_by_user_id' => null]);
        $this->assertNull($row->fresh()->verified_by_user_id);
        $this->assertSame(['effective', 'Virket'], [$row->fresh()->result, $row->fresh()->note]);
    }

    public function test_the_database_refuses_a_verification_that_judges_anything_but_this_tiltaks_completion(): void
    {
        ['user' => $user, 'case' => $case, 'customer' => $customer] = $this->world();
        $action = $this->completed($user, $case, 'Tiltak');
        $other = $this->completed($user, $case, 'Annet tiltak');
        $completion = fn (ImprovementAction $action, string $to = 'completed') => ImprovementActionStatusChange::query()
            ->where('improvement_action_id', $action->id)->where('to_status', $to)->value('id');
        $row = fn (array $overrides) => fn () => DB::table('improvement_action_verifications')->insert($overrides + [
            'customer_id' => $customer->id,
            'improvement_action_id' => $action->id,
            'completion_status_change_id' => $completion($action),
            'result' => 'effective',
            'note' => 'Virket',
            'verified_by_user_id' => $user->id,
            'verified_at' => now(),
        ]);

        // Another tiltak's completion.
        $this->assertRefused($row(['completion_status_change_id' => $completion($other)]));
        // Another customer.
        ['customer' => $otherCustomer] = $this->context();
        $this->assertRefused($row(['customer_id' => $otherCustomer->id]));
        // A status change that is not a completion.
        $started = $this->improvementAction($case, 'Startet', $user);
        $this->actingAs($user)->post($this->url($case, $started, '/start'));
        $this->assertRefused($row(['improvement_action_id' => $started->id, 'completion_status_change_id' => $completion($started, 'in_progress')]));
        // An unknown result, and no comment.
        $this->assertRefused($row(['result' => 'partially_effective']));
        $this->assertRefused($row(['note' => '  ']));

        $row([])();
        $this->assertSame(1, ImprovementActionVerification::query()->where('improvement_action_id', $action->id)->count());
    }

    public function test_a_new_judgement_of_the_same_completion_is_current_and_the_old_one_stays(): void
    {
        ['user' => $user, 'case' => $case] = $this->world();
        $action = $this->completed($user, $case, 'Tiltak');

        Carbon::setTestNow('2026-10-05 09:00:00');
        $this->verify($user, $case, $action, 'not_effective', 'Feilen kom tilbake.');
        Carbon::setTestNow('2026-10-12 09:00:00');
        $this->verify($user, $case, $action, 'effective', 'Feilen var en annen; denne er borte.');

        $this->assertSame(2, ImprovementActionVerification::query()->where('improvement_action_id', $action->id)->count());
        $this->assertSame(1, ImprovementActionVerification::query()->where('improvement_action_id', $action->id)->distinct()->count('completion_status_change_id'));

        $verification = $this->props($user, $case)['actions'][0]['verification'];
        $this->assertSame('effective', $verification['current']['result']);
        $this->assertSame(['not_effective'], array_column($verification['earlier'], 'result'));
        $this->assertFalse($verification['earlier'][0]['earlier_completion']);

        $current = app(ImprovementActionVerificationResolver::class)->forActions([$action->fresh()]);
        $this->assertSame('effective', $current[$action->id]['verification']->result);

        // Within the same second, the later row wins.
        $this->verify($user, $case, $action, 'not_effective', 'Likevel ikke.');
        $this->verify($user, $case, $action, 'effective', 'Jo, likevel.');
        $current = app(ImprovementActionVerificationResolver::class)->forActions([$action->fresh()]);
        $this->assertSame('Jo, likevel.', $current[$action->id]['verification']->note);
    }

    public function test_a_reopened_tiltak_keeps_its_old_verification_as_history_and_a_new_completion_awaits_its_own(): void
    {
        ['user' => $user, 'case' => $case] = $this->world();
        $action = $this->completed($user, $case, 'Tiltak');
        $this->verify($user, $case, $action, 'not_effective', 'Feilen kom tilbake.');
        $firstCompletion = ImprovementActionStatusChange::query()->where('improvement_action_id', $action->id)->where('to_status', 'completed')->value('id');

        $this->actingAs($user)->post($this->url($case, $action, '/reopen'), ['reason' => 'Må arbeides videre med'])->assertSessionHasNoErrors();

        $verification = $this->props($user, $case)['actions'][0]['verification'];
        $this->assertNull($verification['current']);
        $this->assertFalse($verification['awaiting']);
        $this->assertSame([['not_effective', true]], array_map(fn (array $entry): array => [$entry['result'], $entry['earlier_completion']], $verification['earlier']));
        $this->assertSame([], app(ImprovementActionVerificationResolver::class)->forActions([$action->fresh()]));

        $this->actingAs($user)->post($this->url($case, $action, '/complete'), ['completion_note' => 'Gjort på nytt'])->assertSessionHasNoErrors();

        // The new completion has no judgement, whatever the old one said.
        $verification = $this->props($user, $case)['actions'][0]['verification'];
        $this->assertTrue($verification['awaiting']);
        $this->assertNull($verification['current']);
        $this->assertTrue($verification['earlier'][0]['earlier_completion']);
        $current = app(ImprovementActionVerificationResolver::class)->forActions([$action->fresh()]);
        $this->assertNull($current[$action->id]['verification']);
        $this->assertNotSame((int) $firstCompletion, (int) $current[$action->id]['completion']->id);

        $this->verify($user, $case, $action, 'effective', 'Nå virker det.');
        $verification = $this->props($user, $case)['actions'][0]['verification'];
        $this->assertSame(['effective', false], [$verification['current']['result'], $verification['current']['earlier_completion']]);
        $this->assertSame([['not_effective', true]], array_map(fn (array $entry): array => [$entry['result'], $entry['earlier_completion']], $verification['earlier']));
        $this->assertSame((int) $firstCompletion, (int) ImprovementActionVerification::query()->where('result', 'not_effective')->where('improvement_action_id', $action->id)->value('completion_status_change_id'));
    }

    public function test_a_verification_waits_while_the_case_is_ended(): void
    {
        ['user' => $user, 'case' => $case] = $this->world();
        $action = $this->completed($user, $case, 'Tiltak');
        $this->actingAs($user)->post("/app/improvements/{$case->id}/cancel", ['reason' => 'Duplikat'])->assertSessionHasNoErrors();

        $this->verify($user, $case, $action, 'effective', 'Virket')
            ->assertSessionHasErrors(['result' => 'Saken er lukket eller avbrutt. Gjenåpne saken før du endrer tiltakene.']);
        $this->assertFalse($this->props($user, $case)['actions'][0]['permissions']['can_verify']);
        $this->assertSame(0, ImprovementActionVerification::query()->where('improvement_action_id', $action->id)->count());
    }

    // ---------------------------------------------------------------------
    // Access
    // ---------------------------------------------------------------------

    public function test_close_verifies_and_edit_alone_does_not(): void
    {
        ['customer' => $customer, 'area' => $hr, 'user' => $handler, 'case' => $case] = $this->world();
        $action = $this->completed($handler, $case, 'Tiltak');

        $editor = $this->editor($customer, $hr);
        $this->assertFalse($this->props($editor, $case)['actions'][0]['permissions']['can_verify']);
        $this->verify($editor, $case, $action, 'effective', 'Virket')->assertForbidden();

        // improvement.close without improvement.edit: may judge the outcome, not do the work.
        $closer = $this->member($customer);
        $this->grant($customer, $closer, [CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::IMPROVEMENT_CLOSE], [$hr]);
        $props = $this->props($closer, $case);
        $this->assertTrue($props['actions'][0]['permissions']['can_verify']);
        $this->assertFalse($props['actions'][0]['permissions']['can_reopen']);
        $this->verify($closer, $case, $action, 'effective', 'Virket')->assertSessionHasNoErrors();
        $this->assertSame((int) $closer->id, (int) ImprovementActionVerification::query()->where('improvement_action_id', $action->id)->value('verified_by_user_id'));
    }

    public function test_the_wrong_area_another_role_system_owner_and_another_customer_cannot_verify(): void
    {
        ['customer' => $customer, 'owner' => $systemOwner, 'area' => $hr, 'user' => $handler, 'case' => $case] = $this->world();
        $action = $this->completed($handler, $case, 'Tiltak');
        $finance = $this->area($customer, 'Økonomi');

        // Close in another area: the case is not even visible.
        $elsewhere = $this->member($customer);
        $this->grant($customer, $elsewhere, [CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::IMPROVEMENT_CLOSE], [$finance]);
        $this->verify($elsewhere, $case, $action, 'effective', 'Virket')->assertNotFound();

        // View here from one role, close only from a role in another area: no pairing across roles.
        $split = $this->member($customer);
        $this->grant($customer, $split, [CustomerPermissionCatalog::IMPROVEMENT_VIEW], [$hr]);
        $this->grant($customer, $split, [CustomerPermissionCatalog::IMPROVEMENT_VIEW, CustomerPermissionCatalog::IMPROVEMENT_CLOSE], [$finance]);
        $this->verify($split, $case, $action, 'effective', 'Virket')->assertForbidden();

        // System Owner holds the keys but no fagområde.
        $this->verify($systemOwner, $case, $action, 'effective', 'Virket')->assertNotFound();

        // Another customer's handler.
        ['customer' => $other] = $this->context();
        $otherHandler = $this->handler($other, $this->area($other, 'HR'));
        $this->verify($otherHandler, $case, $action, 'effective', 'Virket')->assertNotFound();

        // A tiltak id that is not this case's.
        $otherCase = $this->improvementCase($customer, $hr, 'Annen sak', $handler);
        $this->verify($handler, $otherCase, $action, 'effective', 'Virket')->assertNotFound();

        $this->assertSame(0, ImprovementActionVerification::query()->where('improvement_action_id', $action->id)->count());
    }

    // ---------------------------------------------------------------------
    // Ending the case
    // ---------------------------------------------------------------------

    public function test_lukk_needs_every_completed_tiltak_judged_effective(): void
    {
        ['customer' => $customer, 'area' => $hr, 'user' => $user] = $this->world();

        // No tiltak: no verification asked for.
        $none = $this->improvementCase($customer, $hr, 'Uten tiltak', $user);
        $this->close($user, $none)->assertSessionHasNoErrors();

        // Planned and under arbeid hold it back.
        $case = $this->improvementCase($customer, $hr, 'Med tiltak', $user);
        $action = $this->improvementAction($case, 'Tiltak', $user);
        $this->close($user, $case)->assertSessionHasErrors(['closing_note' => self::NOT_FINISHED]);
        $this->actingAs($user)->post($this->url($case, $action, '/start'));
        $this->close($user, $case)->assertSessionHasErrors(['closing_note' => self::NOT_FINISHED]);

        // Completed, not judged.
        $this->actingAs($user)->post($this->url($case, $action, '/complete'), ['completion_note' => 'Gjort']);
        $this->close($user, $case)->assertSessionHasErrors(['closing_note' => self::NOT_VERIFIED]);

        // Judged not effective.
        $this->verify($user, $case, $action, 'not_effective', 'Virket ikke');
        $this->close($user, $case)->assertSessionHasErrors(['closing_note' => self::NOT_EFFECTIVE]);

        // A cancelled tiltak beside it needs no judgement; the not effective one still holds it back.
        $dropped = $this->improvementAction($case, 'Droppet', $user);
        $this->actingAs($user)->post($this->url($case, $dropped, '/cancel'), ['reason' => 'Unødvendig']);
        $this->close($user, $case)->assertSessionHasErrors(['closing_note' => self::NOT_EFFECTIVE]);

        // Reopened and completed again: the new completion awaits its own judgement.
        $this->actingAs($user)->post($this->url($case, $action, '/reopen'), ['reason' => 'Igjen']);
        $this->actingAs($user)->post($this->url($case, $action, '/complete'), ['completion_note' => 'Gjort bedre']);
        $this->close($user, $case)->assertSessionHasErrors(['closing_note' => self::NOT_VERIFIED]);

        $this->verify($user, $case, $action, 'effective', 'Virket nå');
        $this->assertSame(ImprovementCase::STATUS_OPEN, $case->fresh()->status);
        $this->close($user, $case)->assertSessionHasNoErrors()->assertSessionHas('success', 'Saken er lukket.');
        $this->assertSame(ImprovementCase::STATUS_CLOSED, $case->fresh()->status);

        // Only cancelled tiltak: closes without any judgement.
        $onlyCancelled = $this->improvementCase($customer, $hr, 'Bare avbrutte', $user);
        $cancelled = $this->improvementAction($onlyCancelled, 'Avbrutt', $user);
        $this->actingAs($user)->post($this->url($onlyCancelled, $cancelled, '/cancel'), ['reason' => 'Unødvendig']);
        $this->close($user, $onlyCancelled)->assertSessionHasNoErrors();
        $this->assertSame(ImprovementCase::STATUS_CLOSED, $onlyCancelled->fresh()->status);
    }

    public function test_avbryt_needs_every_tiltak_ended_but_no_verification(): void
    {
        ['customer' => $customer, 'area' => $hr, 'user' => $user] = $this->world();

        $case = $this->improvementCase($customer, $hr, 'Sak', $user);
        $action = $this->improvementAction($case, 'Tiltak', $user);
        $this->cancelCase($user, $case)->assertSessionHasErrors(['reason' => self::NOT_FINISHED]);
        $this->actingAs($user)->post($this->url($case, $action, '/start'));
        $this->cancelCase($user, $case)->assertSessionHasErrors(['reason' => self::NOT_FINISHED]);
        $this->assertSame(ImprovementCase::STATUS_OPEN, $case->fresh()->status);
        // Nothing was ended on the person's behalf.
        $this->assertSame(ImprovementAction::STATUS_IN_PROGRESS, $action->fresh()->status);

        // Completed without judgement, plus cancelled: Avbryt goes through.
        $this->actingAs($user)->post($this->url($case, $action, '/complete'), ['completion_note' => 'Gjort']);
        $dropped = $this->improvementAction($case, 'Droppet', $user);
        $this->actingAs($user)->post($this->url($case, $dropped, '/cancel'), ['reason' => 'Unødvendig']);
        $this->cancelCase($user, $case)->assertSessionHasNoErrors()->assertSessionHas('success', 'Saken er avbrutt.');
        $this->assertSame(ImprovementCase::STATUS_CANCELLED, $case->fresh()->status);

        // Judged not effective: Avbryt goes through too, and still says why.
        $other = $this->improvementCase($customer, $hr, 'Annen sak', $user);
        $failed = $this->completed($user, $other, 'Virket ikke');
        $this->verify($user, $other, $failed, 'not_effective', 'Virket ikke');
        $this->cancelCase($user, $other, '')->assertSessionHasErrors('reason');
        $this->cancelCase($user, $other)->assertSessionHasNoErrors();
        $this->assertSame(ImprovementCase::STATUS_CANCELLED, $other->fresh()->status);
    }

    // ---------------------------------------------------------------------
    // Frist passert under an ended case
    // ---------------------------------------------------------------------

    public function test_frist_passert_is_never_shown_under_an_ended_case(): void
    {
        Carbon::setTestNow('2026-10-05 10:00:00');
        ['user' => $user, 'case' => $case] = $this->world();
        $late = $this->improvementAction($case, 'Forsinket', $user, '2026-09-30');
        $this->assertTrue($this->props($user, $case)['actions'][0]['is_overdue']);

        foreach ([ImprovementCase::STATUS_CLOSED, ImprovementCase::STATUS_CANCELLED] as $status) {
            // Old data, against today's rules: an ended case still holding a planned tiltak.
            $case->forceFill(['status' => $status, 'closed_at' => now(), 'closing_note' => 'Eldre data'])->save();
            $this->assertFalse($this->props($user, $case)['actions'][0]['is_overdue'], $status);
        }

        $this->assertSame(ImprovementAction::STATUS_PLANNED, $late->fresh()->status);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** @return array{customer: mixed, owner: User, area: mixed, user: User, case: ImprovementCase} */
    private function world(): array
    {
        ['customer' => $customer, 'owner' => $owner] = $this->context();
        $area = $this->area($customer, 'HR');
        $user = $this->handler($customer, $area);
        $case = $this->improvementCase($customer, $area, 'Sak', $user);

        return ['customer' => $customer, 'owner' => $owner, 'area' => $area, 'user' => $user, 'case' => $case];
    }

    private function completed(User $user, ImprovementCase $case, string $title): ImprovementAction
    {
        $action = $this->improvementAction($case, $title, $user);
        $this->actingAs($user)->post($this->url($case, $action, '/complete'), ['completion_note' => 'Gjort'])->assertSessionHasNoErrors();

        return $action;
    }

    private function verify(User $user, ImprovementCase $case, ImprovementAction $action, string $result, string $note): TestResponse
    {
        return $this->actingAs($user)->post($this->url($case, $action, '/verify'), ['result' => $result, 'note' => $note]);
    }

    private function close(User $user, ImprovementCase $case): TestResponse
    {
        return $this->actingAs($user)->post("/app/improvements/{$case->id}/close", ['closing_note' => 'Ferdig']);
    }

    private function cancelCase(User $user, ImprovementCase $case, string $reason = 'Duplikat'): TestResponse
    {
        return $this->actingAs($user)->post("/app/improvements/{$case->id}/cancel", ['reason' => $reason]);
    }

    private function url(ImprovementCase $case, ImprovementAction $action, string $suffix = ''): string
    {
        return "/app/improvements/{$case->id}/actions/{$action->id}{$suffix}";
    }

    /** @return array<string, mixed> */
    private function props(User $user, ImprovementCase $case): array
    {
        return $this->actingAs($user)->get("/app/improvements/{$case->id}")->assertOk()->viewData('page')['props'];
    }

    /** Runs a statement the database must refuse, inside a savepoint so the test's transaction survives. */
    private function assertRefused(callable $statement): void
    {
        DB::beginTransaction();

        try {
            $statement();
            $this->fail('The database accepted a statement it should refuse.');
        } catch (QueryException) {
            // refused, as it should be
        } finally {
            DB::rollBack();
        }
    }
}
