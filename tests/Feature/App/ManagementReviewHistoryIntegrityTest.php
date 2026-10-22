<?php

namespace Tests\Feature\App;

use App\Models\ManagementReview;
use App\Models\ManagementReviewEvent;
use App\Models\User;
use App\Services\ManagementReview\ManagementReviewDecisionService;
use App\Services\ManagementReview\ManagementReviewFinalizationService;
use App\Services\ManagementReview\ManagementReviewService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesManagementReviewScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * A finalized Ledelsens gjennomgang stays the snapshot it was, phase 0 step 11.
 *
 * The protection that already existed — a finalized review, its decisions, snapshot, amendments and
 * events locked in the database — is checked here as one picture, together with what this step adds:
 * a draft becomes finalized only with its snapshot and the person who finalized it. Corrections are
 * amendments; every change after finalization is an event with its actor and time; only the
 * customer's own deletion takes it all along.
 */
class ManagementReviewHistoryIntegrityTest extends TestCase
{
    use CreatesManagementReviewScenarios;
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        $this->withoutMiddleware([VerifyCsrfToken::class, ValidateCsrfToken::class]);
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

    /** New: no «ferdigstilt» review without a frozen basis and a finalizer — not even in SQL. */
    public function test_a_draft_becomes_finalized_only_with_its_snapshot_and_finalizer(): void
    {
        ['manager' => $manager, 'draft' => $draft] = $this->world();

        $this->assertRefused(fn () => DB::table('management_reviews')->where('id', $draft->id)->update([
            'status' => 'finalized', 'finalized_at' => now(), 'finalized_by_name' => 'Noen', 'finalized_by_user_id' => $manager->id,
        ]), 'finalizing without a snapshot');

        $this->assertRefused(fn () => DB::table('management_reviews')->where('id', $draft->id)->update([
            'status' => 'finalized', 'finalized_at' => now(), 'finalized_by_name' => 'Noen',
        ]), 'finalizing without a finalizer');

        $this->assertTrue($draft->fresh()->isDraft());
    }

    /** What was decided cannot be changed or removed, and a correction is an amendment beside it. */
    public function test_a_finalized_review_is_an_immutable_snapshot_and_corrections_are_amendments(): void
    {
        ['manager' => $manager, 'finalized' => $finalized] = $this->world();
        $snapshot = DB::table('management_review_snapshot_sections')->where('management_review_id', $finalized->id)->first();
        $decision = DB::table('management_review_decisions')->where('management_review_id', $finalized->id)->first();

        $this->assertRefused(fn () => DB::table('management_reviews')->where('id', $finalized->id)->update(['conclusion' => 'Omskrevet']), 'rewriting the conclusion');
        $this->assertRefused(fn () => DB::table('management_reviews')->where('id', $finalized->id)->update(['status' => 'draft', 'finalized_at' => null]), 'reopening');
        $this->assertRefused(fn () => DB::table('management_reviews')->where('id', $finalized->id)->delete(), 'deleting a finalized review');
        $this->assertRefused(fn () => DB::table('management_review_snapshot_sections')->where('id', $snapshot->id)->update(['state' => 'not_captured']), 'rewriting the snapshot');
        $this->assertRefused(fn () => DB::table('management_review_snapshot_sections')->where('id', $snapshot->id)->delete(), 'deleting the snapshot');
        $this->assertRefused(fn () => DB::table('management_review_decisions')->where('id', $decision->id)->update(['text' => 'Omskrevet']), 'rewriting a decision');
        $this->assertRefused(fn () => DB::table('management_review_decisions')->where('id', $decision->id)->delete(), 'deleting a decision');

        // The service says so too, and a correction is an amendment that leaves the original.
        try {
            app(ManagementReviewService::class)->updateConclusion($manager, $finalized, 'Omskrevet');
            $this->fail('a finalized review is not edited');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $amendment = app(ManagementReviewService::class)->addAmendment($manager, $finalized, 'Tallet var 12, ikke 21.', 'Skrivefeil.');
        $this->assertSame('Styringssystemet er egnet og virker.', $finalized->fresh()->conclusion);
        $this->assertRefused(fn () => DB::table('management_review_amendments')->where('id', $amendment->id)->update(['text' => 'Endret']), 'rewriting an amendment');
        $this->assertRefused(fn () => DB::table('management_review_amendments')->where('id', $amendment->id)->delete(), 'deleting an amendment');
    }

    /** Every change after finalization is an event with its actor and time, and events stay. */
    public function test_changes_after_finalization_are_events_with_actor_and_time(): void
    {
        ['manager' => $manager, 'finalized' => $finalized] = $this->world();
        $tiltak = $finalized->decisions()->where('kind', 'action')->firstOrFail();

        app(ManagementReviewService::class)->updateNextReview($manager, $finalized, '2027-07-01');
        app(ManagementReviewService::class)->addAmendment($manager, $finalized, 'Presisering.', 'Etter møtet.');
        app(ManagementReviewDecisionService::class)->changeStatus($manager, $tiltak, 'completed', 'Gjort.');

        $events = ManagementReviewEvent::query()->where('management_review_id', $finalized->id)->orderBy('id')->get();
        $this->assertSame(['finalized', 'next_review_changed', 'amendment_added', 'action_completed'], array_values(array_intersect(
            $events->pluck('event')->all(),
            ['finalized', 'next_review_changed', 'amendment_added', 'action_completed'],
        )));
        $this->assertTrue($events->every(fn (ManagementReviewEvent $e): bool => $e->actor_user_id !== null && $e->actor_name !== null && $e->occurred_at !== null));

        $event = $events->last();
        $this->assertRefused(fn () => DB::table('management_review_events')->where('id', $event->id)->update(['event' => 'x']), 'rewriting an event');
        $this->assertRefused(fn () => DB::table('management_review_events')->where('id', $event->id)->delete(), 'deleting an event');
    }

    /** Deleting a draft is itself recorded, and the record outlives the draft. */
    public function test_deleting_a_draft_leaves_its_deletion_on_record(): void
    {
        ['manager' => $manager, 'draft' => $draft] = $this->world();

        app(ManagementReviewService::class)->delete($manager, $draft);

        $this->assertDatabaseMissing('management_reviews', ['id' => $draft->id]);
        $deleted = ManagementReviewEvent::query()->where('event', ManagementReviewEvent::DELETED)->where('actor_user_id', $manager->id)->latest('id')->firstOrFail();
        $this->assertSame('Kladd', $deleted->metadata['title'] ?? null);
    }

    /** The one deletion that takes a finalized review along: the customer's own. */
    public function test_deleting_the_customer_takes_the_finalized_review_along(): void
    {
        ['customer' => $customer, 'finalized' => $finalized] = $this->world();

        DB::table('customers')->where('id', $customer->id)->delete();

        $this->assertDatabaseMissing('management_reviews', ['id' => $finalized->id]);
        $this->assertDatabaseMissing('management_review_snapshot_sections', ['management_review_id' => $finalized->id]);
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

    /** @return array<string, mixed> a finalized review with a decision and a tiltak, and a draft */
    private function world(): array
    {
        ['customer' => $customer] = $this->mrContext();
        $manager = $this->mrManager($customer);

        $finalized = $this->mrReview($manager, ['title' => 'Gjennomgang 2026']);
        $this->makeReady($manager, $finalized);
        $decisions = app(ManagementReviewDecisionService::class);
        $decisions->create($manager, $finalized, ['kind' => 'decision', 'text' => 'Policyen videreføres.']);
        $decisions->create($manager, $finalized, ['kind' => 'action', 'text' => 'Oppdatere planen', 'owner_user_id' => $manager->id, 'due_date' => '2026-12-01']);
        app(ManagementReviewFinalizationService::class)->finalize($manager, $finalized->fresh());

        $draft = $this->mrReview($manager, ['title' => 'Kladd']);

        return ['customer' => $customer, 'manager' => $manager, 'finalized' => $finalized->fresh(), 'draft' => $draft];
    }

    private function makeReady(User $manager, ManagementReview $review): void
    {
        $review->forceFill(['meeting_date' => '2026-07-01'])->save();
        $this->actingAs($manager)->post("/app/management-reviews/{$review->id}/participants", ['user_id' => $manager->id])->assertSessionHasNoErrors();
        $this->actingAs($manager)->put("/app/management-reviews/{$review->id}/conclusion", ['conclusion' => 'Styringssystemet er egnet og virker.'])->assertSessionHasNoErrors();

        $page = $this->actingAs($manager)->get("/app/management-reviews/{$review->id}")->viewData('page');

        foreach ($page['props']['sections'] as $section) {
            if ($section['can_assess']) {
                $this->actingAs($manager)->put("/app/management-reviews/{$review->id}/sections/{$section['key']}", ['judgement' => 'satisfactory'])->assertSessionHasNoErrors();
            }
        }
    }
}
