<?php

namespace Tests\Feature\App;

use App\Models\BusinessArea;
use App\Models\Customer;
use App\Models\ImprovementCase;
use App\Models\ManagementReview;
use App\Models\ManagementReviewDecision;
use App\Models\ManagementReviewEvent;
use App\Models\ManagementReviewSection;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\ManagementReview\ManagementReviewFinalizationService;
use App\Services\ManagementReview\ManagementReviewSectionCatalog;
use App\Services\MyTasks\MyTasksService;
use App\Services\MyTasks\TaskDeadlineReminderService;
use App\Services\Notifications\UserNotificationAccessScope;
use App\Support\CustomerPermissionCatalog as P;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesManagementReviewScenarios;
use Tests\TestCase;

/**
 * Beslutninger og tiltak (plan §9): one follow-up per tiltak, in the modules that already follow up.
 *
 * What these tests defend:
 *
 *  - A tiltak works for every customer that has the module: followed up here, in the owner's Mine
 *    oppgaver, with assignment notice and deadline reminders — no other module needed.
 *  - Where the person may register cases in Avvik og forbedringer, the tiltak can be handed off or
 *    linked; it then appears only as the case's task, never twice.
 *  - What was decided is frozen once handed off or finalized (also in the database); the follow-up of
 *    a tiltak continues after finalization, and each step is in the trail.
 *  - Open tiltak come back in the next review; a case's details only to someone who may read it.
 *  - The bell and the case page reveal nothing to someone who cannot read reviews.
 */
class ManagementReviewDecisionTest extends TestCase
{
    use CreatesManagementReviewScenarios;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([VerifyCsrfToken::class, ValidateCsrfToken::class]);
        Carbon::setTestNow('2026-10-07 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_tiltak_is_followed_up_here_and_lands_in_the_owners_tasks(): void
    {
        ['customer' => $customer] = $this->mrContext();
        $manager = $this->mrManager($customer);
        $owner = $this->mrReader($customer);
        $review = $this->mrReview($manager);

        $this->actingAs($manager)->post("/app/management-reviews/{$review->id}/decisions", ['kind' => 'action', 'text' => 'Uten frist', 'owner_user_id' => $owner->id])
            ->assertSessionHasErrors('due_date');
        $this->actingAs($manager)->post("/app/management-reviews/{$review->id}/decisions", ['kind' => 'action', 'text' => 'Uten tilgang', 'owner_user_id' => $this->mrMember($customer)->id, 'due_date' => '2026-12-01'])
            ->assertSessionHasErrors('owner_user_id');

        $this->actingAs($manager)->post("/app/management-reviews/{$review->id}/decisions", [
            'kind' => 'decision', 'text' => 'Kvalitetspolicyen videreføres.', 'section_key' => 'quality',
        ])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post("/app/management-reviews/{$review->id}/decisions", [
            'kind' => 'action', 'text' => 'Oppdatere kompetanseplanen', 'section_key' => 'resources',
            'owner_user_id' => $owner->id, 'due_date' => '2026-10-01',
        ])->assertSessionHasNoErrors();

        $action = ManagementReviewDecision::query()->where('kind', 'action')->sole();
        $this->assertSame([ManagementReviewDecision::FOLLOW_UP_OWN, ManagementReviewDecision::STATUS_OPEN], [$action->follow_up, $action->status]);

        $tasks = app(MyTasksService::class)->tasksFor($owner, $customer->id);
        $task = $tasks->firstWhere('id', 'management-review-action-'.$action->id);
        $this->assertNotNull($task);
        $this->assertSame('review_action', $task->type);
        $this->assertTrue($task->overdue);
        $this->assertSame('management_review', $task->subject['prefix']);

        // Assigned: the owner is told; the bell shows it only to someone who can read reviews.
        $notice = UserNotification::query()->where('user_id', $owner->id)->where('event_type', 'management_review.action_assigned')->sole();
        $this->assertSame($review->id, (int) $notice->metadata['management_review_id']);
        $this->assertTrue(app(UserNotificationAccessScope::class)->apply(UserNotification::query(), $owner)->whereKey($notice->id)->exists());
        $owner->customerRoles()->detach();
        $this->assertFalse(app(UserNotificationAccessScope::class)->apply(UserNotification::query(), $owner->fresh())->whereKey($notice->id)->exists());
    }

    public function test_an_overdue_tiltak_is_reminded_through_the_shared_sweep(): void
    {
        ['customer' => $customer] = $this->mrContext();
        $manager = $this->mrManager($customer);
        $owner = $this->mrReader($customer);
        $review = $this->mrReview($manager);
        $this->action($manager, $review, $owner, 'Forfalt tiltak', '2026-10-01');

        app(TaskDeadlineReminderService::class)->remindCustomer($customer, Carbon::parse('2026-10-07'));

        $this->assertTrue(UserNotification::query()->where('user_id', $owner->id)->where('event_type', 'management_review.task_overdue')->exists());
    }

    public function test_the_owner_completes_a_tiltak_after_finalization_and_the_decision_stays_frozen(): void
    {
        ['customer' => $customer] = $this->mrContext();
        $manager = $this->mrManager($customer);
        $owner = $this->mrReader($customer);
        $stranger = $this->mrReader($customer);
        $review = $this->mrReview($manager);
        $action = $this->action($manager, $review, $owner, 'Revidere beredskapsplan', '2026-12-01');
        $this->finalize($manager, $review);

        $this->actingAs($stranger)->post("/app/management-reviews/{$review->id}/decisions/{$action->id}/status", ['status' => 'completed'])->assertForbidden();
        $this->actingAs($owner)->post("/app/management-reviews/{$review->id}/decisions/{$action->id}/status", ['status' => 'completed', 'note' => 'Planen er revidert.'])
            ->assertSessionHasNoErrors();

        $action->refresh();
        $this->assertSame(ManagementReviewDecision::STATUS_COMPLETED, $action->status);
        $this->assertTrue(ManagementReviewEvent::query()->where('decision_id', $action->id)->where('event', ManagementReviewEvent::ACTION_COMPLETED)->exists());
        $this->assertNull(app(MyTasksService::class)->tasksFor($owner, $customer->id)->firstWhere('id', 'management-review-action-'.$action->id));

        // What was decided is frozen; new decisions cannot be added.
        $this->actingAs($manager)->patch("/app/management-reviews/{$review->id}/decisions/{$action->id}", ['kind' => 'action', 'text' => 'Noe annet', 'owner_user_id' => $owner->id, 'due_date' => '2026-12-01'])
            ->assertSessionHasErrors('review');
        $this->actingAs($manager)->post("/app/management-reviews/{$review->id}/decisions", ['kind' => 'decision', 'text' => 'Etterpå'])->assertSessionHasErrors('review');
        $this->assertDatabaseRefuses(fn () => DB::table('management_review_decisions')->where('id', $action->id)->update(['text' => 'Endret']));
        $this->assertDatabaseRefuses(fn () => DB::table('management_review_decisions')->where('id', $action->id)->delete());

        // At finalization it was open; now it is completed — both are shown.
        $row = collect($this->props($manager, $review)['decisions'])->firstWhere('id', $action->id);
        $this->assertSame('completed', $row['status']);
        $this->assertSame('Åpent', $row['at_finalization']['cells']['action_status']);
    }

    public function test_a_tiltak_handed_to_avvik_is_one_task_there_and_the_case_knows_where_it_came_from(): void
    {
        ['customer' => $customer] = $this->mrContext();
        $area = $this->mrArea($customer, 'Drift');
        $manager = $this->mrManager($customer, [P::IMPROVEMENT_VIEW, P::IMPROVEMENT_EDIT], [$area]);
        $caseOwner = $this->mrReader($customer, [P::IMPROVEMENT_VIEW], [$area]);
        $review = $this->mrReview($manager);
        $action = $this->action($manager, $review, $caseOwner, 'Forbedre opplæring', '2026-12-01');

        $props = $this->props($manager, $review);
        $this->assertTrue($props['permissions']['can_hand_off']);
        $this->assertSame([$area->id], array_column($props['handoff_options']['area_options'], 'id'));

        $this->actingAs($manager)->post("/app/management-reviews/{$review->id}/decisions/{$action->id}/handoff", [
            'title' => 'Forbedre opplæring',
            'description' => 'Besluttet i ledelsens gjennomgåelse.',
            'business_area_id' => $area->id,
            'owner_user_id' => $caseOwner->id,
            'due_date' => '2026-12-01',
        ])->assertSessionHasNoErrors();

        $action->refresh();
        $case = ImprovementCase::query()->findOrFail($action->improvement_case_id);
        $this->assertSame([ImprovementCase::TYPE_IMPROVEMENT, ManagementReviewDecision::FOLLOW_UP_IMPROVEMENT_CASE, null], [$case->type, $action->follow_up, $action->status]);

        // One task, in Avvik og forbedringer — never also here.
        $tasks = app(MyTasksService::class)->tasksFor($caseOwner, $customer->id);
        $this->assertNull($tasks->firstWhere('id', 'management-review-action-'.$action->id));
        $this->assertNotNull($tasks->firstWhere('id', 'improvement-case-'.$case->id));

        // The case cannot be deleted, the decision not changed, the draft not deleted.
        $this->assertFalse($case->isDeletable());
        $this->assertFalse($review->fresh()->isDeletable());
        $this->assertDatabaseRefuses(fn () => DB::table('management_review_decisions')->where('id', $action->id)->update(['text' => 'Endret']));
        $this->assertDatabaseRefuses(fn () => DB::table('management_review_decisions')->where('id', $action->id)->delete());

        // «Fra ledelsens gjennomgåelse» on the case — only to someone who can read reviews.
        $this->actingAs($caseOwner)->get("/app/improvements/{$case->id}")->assertOk()->assertInertia(fn ($page) => $page->where('review_origin.review_title', $review->title));
        $plainHandler = $this->mrMember($customer);
        $this->mrGrant($customer, $plainHandler, [P::IMPROVEMENT_VIEW], [$area]);
        $this->actingAs($plainHandler)->get("/app/improvements/{$case->id}")->assertOk()->assertInertia(fn ($page) => $page->where('review_origin', null));
    }

    public function test_a_tiltak_can_be_linked_to_an_existing_case(): void
    {
        ['customer' => $customer] = $this->mrContext();
        $area = $this->mrArea($customer, 'Drift');
        $manager = $this->mrManager($customer, [P::IMPROVEMENT_VIEW, P::IMPROVEMENT_EDIT], [$area]);
        $review = $this->mrReview($manager);
        $action = $this->action($manager, $review, $manager, 'Følge opp leverandøravvik', '2026-12-01');
        $case = ImprovementCase::query()->create([
            'customer_id' => $customer->id, 'business_area_id' => $area->id, 'type' => 'deviation',
            'title' => 'Leverandøravvik', 'description' => 'Beskrivelse', 'owner_user_id' => $manager->id,
        ]);

        $this->actingAs($manager)->post("/app/management-reviews/{$review->id}/decisions/{$action->id}/link", ['improvement_case_id' => $case->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($case->id, $action->fresh()->improvement_case_id);
        $this->assertSame(ManagementReviewDecision::ORIGIN_LINKED, $action->fresh()->improvement_origin);
        $this->assertTrue(ManagementReviewEvent::query()->where('decision_id', $action->id)->where('event', ManagementReviewEvent::DECISION_LINKED)->exists());
    }

    public function test_without_avvik_rights_a_tiltak_still_works_and_hand_off_is_not_offered(): void
    {
        ['customer' => $customer] = $this->mrContext();
        $this->mrArea($customer, 'Drift');
        $manager = $this->mrManager($customer);
        $review = $this->mrReview($manager);
        $action = $this->action($manager, $review, $manager, 'Rydde i dokumentasjon', '2026-12-01');

        $props = $this->props($manager, $review);
        $this->assertFalse($props['permissions']['can_hand_off']);
        $this->assertNull($props['handoff_options']);
        $this->assertFalse(collect($props['decisions'])->firstWhere('id', $action->id)['permissions']['can_hand_off']);

        $this->actingAs($manager)->post("/app/management-reviews/{$review->id}/decisions/{$action->id}/handoff", [
            'title' => 'X', 'description' => 'Y', 'business_area_id' => BusinessArea::query()->where('customer_id', $customer->id)->value('id'), 'owner_user_id' => $manager->id,
        ])->assertForbidden();

        $this->assertNotNull(app(MyTasksService::class)->tasksFor($manager, $customer->id)->firstWhere('id', 'management-review-action-'.$action->id));
    }

    public function test_open_tiltak_come_back_in_the_next_review_with_case_details_only_for_case_readers(): void
    {
        ['customer' => $customer] = $this->mrContext();
        $area = $this->mrArea($customer, 'Drift');
        $manager = $this->mrManager($customer, [P::IMPROVEMENT_VIEW, P::IMPROVEMENT_EDIT], [$area]);
        $first = $this->mrReview($manager, ['title' => 'LG 2025', 'period_start' => '2025-01-01', 'period_end' => '2025-12-31']);
        $own = $this->action($manager, $first, $manager, 'Eget tiltak', '2026-03-01');
        $handed = $this->action($manager, $first, $manager, 'Tiltak i Avvik', '2026-03-01');
        $this->actingAs($manager)->post("/app/management-reviews/{$first->id}/decisions/{$handed->id}/handoff", [
            'title' => 'Sak fra LG', 'description' => 'Fra ledelsens gjennomgåelse.', 'business_area_id' => $area->id, 'owner_user_id' => $manager->id, 'due_date' => '2026-03-01',
        ])->assertSessionHasNoErrors();
        $this->finalize($manager, $first);

        $second = $this->mrReview($manager, ['title' => 'LG 2026']);
        $section = collect($this->props($manager, $second)['sections'])->firstWhere('key', 'previous_decisions');
        $rows = collect(collect($section['basis']['lists'])->firstWhere('key', 'decisions')['rows'])->keyBy('id');
        $this->assertSame('Åpent', $rows[$own->id]['cells']['action_status']);
        $this->assertSame('Ja', $rows[$own->id]['cells']['overdue']);
        $this->assertSame('Sak fra LG', $rows[$handed->id]['case']['title']);

        $reader = $this->mrReader($customer);
        $section = collect($this->props($reader, $second)['sections'])->firstWhere('key', 'previous_decisions');
        $rows = collect(collect($section['basis']['lists'])->firstWhere('key', 'decisions')['rows'])->keyBy('id');
        $this->assertArrayNotHasKey('case', $rows[$handed->id]);
        $this->assertTrue($rows[$handed->id]['case_hidden']);
        $this->assertStringNotContainsString('Sak fra LG', json_encode($this->props($reader, $second)));
    }

    public function test_the_review_itself_asks_its_owner_to_finish_and_later_to_plan_the_next(): void
    {
        ['customer' => $customer] = $this->mrContext();
        $manager = $this->mrManager($customer);
        $review = $this->mrReview($manager, ['meeting_date' => '2026-10-01']);

        $task = app(MyTasksService::class)->tasksFor($manager, $customer->id)->firstWhere('id', 'management-review-'.$review->id);
        $this->assertSame('review_complete', $task->type);
        $this->assertTrue($task->overdue);
        $this->assertSame('meeting_passed', $task->reasons[0]['key']);

        $this->finalize($manager, $review);
        $review->forceFill(['next_review_due_on' => '2026-10-30'])->save();

        $tasks = app(MyTasksService::class)->tasksFor($manager, $customer->id);
        $this->assertNull($tasks->firstWhere('id', 'management-review-'.$review->id));
        $this->assertSame('review_due', $tasks->firstWhere('id', 'management-review-due-'.$review->id)?->type);

        // A newer review takes the task away.
        $this->mrReview($manager, ['title' => 'Neste', 'meeting_date' => null]);
        $this->assertNull(app(MyTasksService::class)->tasksFor($manager, $customer->id)->firstWhere('id', 'management-review-due-'.$review->id));
    }

    // ---------------------------------------------------------------------

    private function action(User $actor, ManagementReview $review, User $owner, string $text, string $due): ManagementReviewDecision
    {
        $this->actingAs($actor)->post("/app/management-reviews/{$review->id}/decisions", [
            'kind' => 'action', 'text' => $text, 'owner_user_id' => $owner->id, 'due_date' => $due,
        ])->assertSessionHasNoErrors();

        return ManagementReviewDecision::query()->where('management_review_id', $review->id)->where('text', $text)->sole();
    }

    /** @return array<string, mixed> */
    private function props(User $user, ManagementReview $review): array
    {
        return $this->actingAs($user)->get("/app/management-reviews/{$review->id}")->assertOk()->viewData('page')['props'];
    }

    private function finalize(User $manager, ManagementReview $review): void
    {
        $review->participants()->create(['customer_id' => $review->customer_id, 'name' => $manager->name, 'user_id' => $manager->id]);
        $review->forceFill(['conclusion' => 'Egnet og virker.', 'meeting_date' => '2026-07-01'])->save();

        foreach (array_keys(ManagementReviewSectionCatalog::DEFINITIONS) as $key) {
            ManagementReviewSection::query()->create([
                'customer_id' => $review->customer_id, 'management_review_id' => $review->id, 'section_key' => $key, 'judgement' => 'satisfactory',
            ]);
        }

        $this->actingAs($manager);
        app(ManagementReviewFinalizationService::class)->finalize($manager, $review->fresh());
    }

    private function assertDatabaseRefuses(callable $write): void
    {
        DB::statement('SAVEPOINT mr_refusal');

        try {
            $write();
            DB::statement('RELEASE SAVEPOINT mr_refusal');
            $this->fail('The database accepted a write it should refuse.');
        } catch (QueryException) {
            DB::statement('ROLLBACK TO SAVEPOINT mr_refusal');
            $this->addToAssertionCount(1);
        }
    }
}
