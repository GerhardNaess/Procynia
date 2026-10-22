<?php

namespace Tests\Feature\App;

use App\Models\ManagementReview;
use App\Models\ManagementReviewDecision;
use App\Models\ManagementReviewParticipant;
use App\Models\User;
use App\Services\ManagementReview\ManagementReviewDecisionService;
use App\Services\ManagementReview\ManagementReviewService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesManagementReviewScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * The ordinary writes in Ledelsens gjennomgang — a draft review, its conclusion, participants and
 * sections, the next review and owner, and its decisions and tiltak — check management_review.edit
 * for a review of the person's own customer in their own services (phase 0 step 11), on the review
 * under its row lock. A tiltak's follow-up is also open to its owner. A later entry point inherits
 * the rules instead of re-implementing them.
 *
 * Ordinary editing, not a human-only decision: an integration with management_review.edit may do it.
 */
class ManagementReviewServiceAccessTest extends TestCase
{
    use CreatesManagementReviewScenarios;
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
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

    public function test_an_editor_works_a_draft_through_the_services(): void
    {
        $world = $this->world();
        $reviews = app(ManagementReviewService::class);
        $decisions = app(ManagementReviewDecisionService::class);

        $reviews->update($world['editor'], $world['review'], $this->mrPayload($world['editor'], ['title' => 'Gjennomgang høst']));
        $reviews->updateConclusion($world['editor'], $world['review'], 'Systemet virker.');
        $participant = $reviews->addParticipant($world['editor'], $world['review'], ['name' => 'Ekstern revisor']);
        $reviews->removeParticipant($world['editor'], $world['review'], $participant);
        $reviews->updateNextReview($world['editor'], $world['review'], '2027-06-01');
        $decision = $decisions->create($world['editor'], $world['review'], ['kind' => 'decision', 'text' => 'Policyen videreføres.']);
        $decisions->update($world['editor'], $decision, ['kind' => 'decision', 'text' => 'Policyen videreføres uendret.']);
        $decisions->delete($world['editor'], $decision);

        $review = $world['review']->fresh();
        $this->assertSame(['Gjennomgang høst', 'Systemet virker.', '2027-06-01'], [$review->title, $review->conclusion, $review->next_review_due_on?->toDateString()]);
        $this->assertSame(0, ManagementReviewParticipant::query()->where('management_review_id', $review->id)->where('name', 'Ekstern revisor')->count());
        $this->assertDatabaseMissing('management_review_decisions', ['id' => $decision->id]);
    }

    /** Not a human decision: an integration with management_review.edit edits a draft. */
    public function test_an_integration_with_management_review_edit_may_edit_a_draft(): void
    {
        $world = $this->world();
        $integration = $this->mrReader($world['customer'], [CustomerPermissionCatalog::MANAGEMENT_REVIEW_EDIT]);
        $integration->forceFill(['role' => User::ROLE_INTEGRATION])->save();

        app(ManagementReviewService::class)->updateConclusion($integration->fresh(), $world['review'], 'Fra integrasjon.');

        $this->assertSame('Fra integrasjon.', $world['review']->fresh()->conclusion);
    }

    public function test_every_write_is_refused_in_the_service_without_edit_in_the_customer(): void
    {
        $world = $this->world();
        ['customer' => $other, 'owner' => $otherOwner] = $this->mrContext();

        foreach ([
            'reads only' => $this->mrReader($world['customer']),
            'edit without view' => $this->editWithoutView($world),
            "another customer's manager" => $this->mrManager($other),
            "another customer's System Owner" => $otherOwner,
        ] as $who => $person) {
            $this->assertEveryWriteRefused($world, $person, $who);
        }
    }

    /** Creating a review takes management_review.edit too; a reader creates none. */
    public function test_a_reader_creates_no_review(): void
    {
        $world = $this->world();
        $reader = $this->mrReader($world['customer']);
        $before = ManagementReview::query()->where('customer_id', $world['customer']->id)->count();

        try {
            app(ManagementReviewService::class)->create($reader, $this->mrPayload($reader));
            $this->fail('a reader must not create a review');
        } catch (AuthorizationException) {
            $this->assertSame($before, ManagementReview::query()->where('customer_id', $world['customer']->id)->count());
        }
    }

    /** A tiltak's follow-up: its owner (who only reads) or an editor — nobody else, no other customer. */
    public function test_a_tiltak_is_followed_up_by_its_owner_or_an_editor_only(): void
    {
        $world = $this->world();
        $decisions = app(ManagementReviewDecisionService::class);
        ['customer' => $other] = $this->mrContext();

        foreach (['another reader' => $this->mrReader($world['customer']), "another customer's manager" => $this->mrManager($other)] as $who => $person) {
            try {
                $decisions->changeStatus($person, $world['tiltak'], ManagementReviewDecision::STATUS_COMPLETED, 'Ferdig.');
                $this->fail("{$who} must be refused");
            } catch (AuthorizationException) {
                $this->assertSame(ManagementReviewDecision::STATUS_OPEN, $world['tiltak']->fresh()->status, $who);
            }
        }

        $decisions->changeStatus($world['tiltakOwner'], $world['tiltak'], ManagementReviewDecision::STATUS_COMPLETED, 'Ferdig.');
        $this->assertSame(ManagementReviewDecision::STATUS_COMPLETED, $world['tiltak']->fresh()->status);
        $this->assertSame((int) $world['tiltakOwner']->id, (int) $world['tiltak']->fresh()->completed_by_user_id);

        $decisions->changeStatus($world['editor'], $world['tiltak'], ManagementReviewDecision::STATUS_OPEN, null);
        $this->assertSame(ManagementReviewDecision::STATUS_OPEN, $world['tiltak']->fresh()->status);
    }

    /** @param array<string, mixed> $world */
    private function assertEveryWriteRefused(array $world, User $person, string $who): void
    {
        $reviews = app(ManagementReviewService::class);
        $decisions = app(ManagementReviewDecisionService::class);
        $participant = $world['review']->participants()->firstOrFail();

        foreach ([
            'update' => fn () => $reviews->update($person, $world['review'], $this->mrPayload($world['editor'], ['title' => 'Overtatt'])),
            'conclusion' => fn () => $reviews->updateConclusion($person, $world['review'], 'Overtatt'),
            'add participant' => fn () => $reviews->addParticipant($person, $world['review'], ['name' => 'Overtatt']),
            'remove participant' => fn () => $reviews->removeParticipant($person, $world['review'], $participant),
            'section' => fn () => $reviews->saveSection($person, $world['review'], 'resources', 'satisfactory', null, null),
            'next review' => fn () => $reviews->updateNextReview($person, $world['review'], '2030-01-01'),
            'owner' => fn () => $reviews->updateOwner($person, $world['review'], (int) $world['editor']->id),
            'decision create' => fn () => $decisions->create($person, $world['review'], ['kind' => 'decision', 'text' => 'Overtatt']),
            'decision update' => fn () => $decisions->update($person, $world['tiltak'], ['kind' => 'decision', 'text' => 'Overtatt']),
            'decision delete' => fn () => $decisions->delete($person, $world['tiltak']),
            'reassign' => fn () => $decisions->reassign($person, $world['tiltak'], (int) $world['editor']->id, '2030-01-01'),
        ] as $write => $attempt) {
            try {
                $attempt();
                $this->fail("{$who}: {$write} must be refused");
            } catch (\Throwable $e) {
                $this->assertInstanceOf(AuthorizationException::class, $e, "{$who}: {$write} — ".$e->getMessage());
            }
        }

        $review = $world['review']->fresh();
        $this->assertSame([ManagementReview::STATUS_DRAFT, 'Ledelsens gjennomgang 2026', null, null], [$review->status, $review->title, $review->conclusion, $review->next_review_due_on], $who);
        $this->assertSame(1, $review->participants()->count(), $who);
        $tiltak = $world['tiltak']->fresh();
        $this->assertSame(['Oppdatere kompetanseplanen', (int) $world['tiltakOwner']->id], [$tiltak->text, (int) $tiltak->owner_user_id], $who);
    }

    private function editWithoutView(array $world): User
    {
        $person = $this->mrMember($world['customer']);
        $this->mrGrant($world['customer'], $person, [CustomerPermissionCatalog::MANAGEMENT_REVIEW_EDIT]);

        return $person;
    }

    /** @return array<string, mixed> a draft with one participant and one tiltak owned by a reader */
    private function world(): array
    {
        ['customer' => $customer] = $this->mrContext();
        $editor = $this->mrReader($customer, [CustomerPermissionCatalog::MANAGEMENT_REVIEW_EDIT]);
        $tiltakOwner = $this->mrReader($customer);
        $review = $this->mrReview($editor, ['participant_user_ids' => [$editor->id]]);

        if ($review->participants()->count() === 0) {
            app(ManagementReviewService::class)->addParticipant($editor, $review, ['user_id' => $editor->id]);
        }

        $tiltak = app(ManagementReviewDecisionService::class)->create($editor, $review, [
            'kind' => 'action', 'text' => 'Oppdatere kompetanseplanen', 'section_key' => null,
            'owner_user_id' => $tiltakOwner->id, 'due_date' => '2026-12-01',
        ]);

        return compact('customer', 'editor', 'tiltakOwner', 'review', 'tiltak');
    }
}
