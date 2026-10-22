<?php

namespace Tests\Feature\App;

use App\Exceptions\HumanDecisionRefused;
use App\Models\ManagementReview;
use App\Models\ManagementReviewAmendment;
use App\Models\ManagementReviewSnapshotSection;
use App\Models\User;
use App\Services\ManagementReview\ManagementReviewAccessService;
use App\Services\ManagementReview\ManagementReviewFinalizationService;
use App\Services\ManagementReview\ManagementReviewService;
use App\Services\Security\ActorChannel;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesManagementReviewScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * The human decisions in Ledelsens gjennomgang — Ferdigstill, a formal amendment after it, and
 * deleting a draft — are refused in the services themselves, phase 0 step 11
 * (docs/external-api-phase-0-plan.md §4.4).
 *
 * An integration, any request through the API (delegated mode included), an inactive person and a
 * person without the key, or of another customer, are turned away there, whatever the entry point,
 * and nothing is written. The controllers keep their own checks first.
 */
class ManagementReviewHumanDecisionTest extends TestCase
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

    /** A manager with the keys finalizes, amends and deletes a draft — through the services. */
    public function test_a_person_with_the_keys_takes_every_decision(): void
    {
        $world = $this->world();

        $finalized = app(ManagementReviewFinalizationService::class)->finalize($world['manager'], $world['ready']);
        $this->assertTrue($finalized->isFinalized());
        $this->assertSame((int) $world['manager']->id, (int) $finalized->finalized_by_user_id);
        $this->assertGreaterThan(0, ManagementReviewSnapshotSection::query()->where('management_review_id', $finalized->id)->count());

        $amendment = app(ManagementReviewService::class)->addAmendment($world['manager'], $finalized, 'Tallet var feil.', 'Rettet etter møtet.');
        $this->assertSame((int) $world['manager']->id, (int) $amendment->created_by_user_id);

        app(ManagementReviewService::class)->delete($world['manager'], $world['draft']);
        $this->assertDatabaseMissing('management_reviews', ['id' => $world['draft']->id]);
    }

    /**
     * Unchanged rules for the customer's System Owner: it holds every key of this domain, so it passes
     * the guard and the access check — and then meets readiness, which is computed with the
     * finalizer's own access. Without a role reaching the protected sections, the review is not
     * ready for them, exactly as before.
     */
    public function test_the_system_owner_passes_access_and_then_meets_readiness_as_before(): void
    {
        $world = $this->world();

        $this->assertTrue(app(ManagementReviewAccessService::class)->canFinalizeReview($world['owner'], $world['ready']));

        try {
            app(ManagementReviewFinalizationService::class)->finalize($world['owner'], $world['ready']);
            $this->fail('readiness must be judged with the System Owner\'s own access');
        } catch (ValidationException $e) {
            $this->assertSame(__('procynia.management_review.validation.not_ready'), $e->errors()['review'][0]);
        }

        $this->assertTrue($world['ready']->fresh()->isDraft());

        // Amending and deleting a draft need no section access.
        app(ManagementReviewService::class)->addAmendment($world['owner'], $world['finalized'], 'Presisering.', 'Fra eier.');
        app(ManagementReviewService::class)->delete($world['owner'], $world['draft']);
        $this->assertDatabaseMissing('management_reviews', ['id' => $world['draft']->id]);
    }

    public function test_no_decision_is_taken_by_an_integration(): void
    {
        $world = $this->world();
        $integration = $this->mrManager($world['customer']);
        $integration->forceFill(['role' => User::ROLE_INTEGRATION])->save();

        $this->assertEveryDecisionRefused($world, $integration->fresh(), HumanDecisionRefused::class);
    }

    /** Delegated mode: a person's own account, but the request comes through the API. */
    public function test_no_decision_is_taken_through_the_api_even_on_behalf_of_a_person(): void
    {
        $world = $this->world();
        $this->app->instance(ActorChannel::class, new class implements ActorChannel
        {
            public function isApi(): bool
            {
                return true;
            }
        });

        $this->assertEveryDecisionRefused($world, $world['manager'], HumanDecisionRefused::class);
    }

    public function test_an_inactive_person_takes_no_decision(): void
    {
        $world = $this->world();
        $world['manager']->forceFill(['is_active' => false])->save();

        $this->assertEveryDecisionRefused($world, $world['manager']->fresh(), HumanDecisionRefused::class);
    }

    /** The service refuses on its own: edit is not finalize or delete, and reading is neither. */
    public function test_a_person_without_the_key_is_refused_by_the_service(): void
    {
        $world = $this->world();

        foreach ([
            'reads and edits' => $this->mrReader($world['customer'], [CustomerPermissionCatalog::MANAGEMENT_REVIEW_EDIT]),
            'reads only' => $this->mrReader($world['customer']),
            'finalize and delete without view' => $this->keysWithoutView($world),
        ] as $who => $person) {
            $this->assertEveryDecisionRefused($world, $person, AuthorizationException::class, $who);
        }
    }

    /** Customer separation: a manager, or a System Owner, of another customer is nobody here. */
    public function test_another_customer_can_neither_read_nor_decide_on_a_review(): void
    {
        $world = $this->world();
        ['customer' => $other, 'owner' => $otherOwner] = $this->mrContext();
        $outsider = $this->mrManager($other);

        foreach (['manager' => $outsider, 'System Owner' => $otherOwner] as $who => $person) {
            $this->assertEveryDecisionRefused($world, $person, AuthorizationException::class, "another customer's {$who}");

            $this->actingAs($person)->get("/app/management-reviews/{$world['ready']->id}")->assertNotFound();
            $this->actingAs($person)->post("/app/management-reviews/{$world['ready']->id}/finalize")->assertNotFound();
            $this->actingAs($person)->post("/app/management-reviews/{$world['finalized']->id}/amendments", ['text' => 'Fremmed', 'reason' => 'Fremmed'])->assertNotFound();
            $this->actingAs($person)->delete("/app/management-reviews/{$world['draft']->id}")->assertNotFound();
        }
    }

    /**
     * Ferdigstill, amend and delete, called directly on their services with this actor: each is
     * refused with $refusal, and nothing is finalized, captured, amended or deleted.
     *
     * @param  array<string, mixed>  $world
     * @param  class-string<\Throwable>  $refusal
     */
    private function assertEveryDecisionRefused(array $world, User $actor, string $refusal, string $who = ''): void
    {
        foreach ([
            'finalize' => fn () => app(ManagementReviewFinalizationService::class)->finalize($actor, $world['ready']),
            'amend' => fn () => app(ManagementReviewService::class)->addAmendment($actor, $world['finalized'], 'Uten rett.', 'Uten rett.'),
            'delete' => fn () => app(ManagementReviewService::class)->delete($actor, $world['draft']),
        ] as $decision => $attempt) {
            try {
                $attempt();
                $this->fail("{$who}: {$decision} must be refused");
            } catch (\Throwable $e) {
                $this->assertInstanceOf($refusal, $e, "{$who}: {$decision} — ".$e->getMessage());
            }
        }

        $this->assertTrue($world['ready']->fresh()->isDraft(), $who);
        $this->assertSame(0, ManagementReviewSnapshotSection::query()->where('management_review_id', $world['ready']->id)->count(), $who);
        $this->assertSame(0, ManagementReviewAmendment::query()->where('management_review_id', $world['finalized']->id)->count(), $who);
        $this->assertTrue(ManagementReview::query()->whereKey($world['draft']->id)->exists(), $who);
    }

    /** management_review.finalize and .delete from a role that lacks management_review.view. */
    private function keysWithoutView(array $world): User
    {
        $person = $this->mrMember($world['customer']);
        $this->mrGrant($world['customer'], $person, [
            CustomerPermissionCatalog::MANAGEMENT_REVIEW_FINALIZE,
            CustomerPermissionCatalog::MANAGEMENT_REVIEW_DELETE,
        ]);

        return $person;
    }

    /** @return array<string, mixed> a ready draft, an already finalized review, a plain draft, and its manager */
    private function world(): array
    {
        ['customer' => $customer, 'owner' => $owner] = $this->mrContext();
        $manager = $this->mrManager($customer);

        $finalized = $this->mrReview($manager, ['title' => 'Gjennomgang 2025']);
        $this->makeReady($manager, $finalized);
        app(ManagementReviewFinalizationService::class)->finalize($manager, $finalized->fresh());

        $ready = $this->mrReview($manager, ['title' => 'Gjennomgang 2026']);
        $this->makeReady($manager, $ready);
        $draft = $this->mrReview($manager, ['title' => 'Kladd']);

        return ['customer' => $customer, 'owner' => $owner, 'manager' => $manager, 'finalized' => $finalized->fresh(), 'ready' => $ready->fresh(), 'draft' => $draft];
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
