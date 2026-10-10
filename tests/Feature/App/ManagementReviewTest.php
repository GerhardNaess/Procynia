<?php

namespace Tests\Feature\App;

use App\Models\ManagementReview;
use App\Models\ManagementReviewAmendment;
use App\Models\ManagementReviewEvent;
use App\Models\ManagementReviewSection;
use App\Models\ManagementReviewSnapshotSection;
use App\Services\ManagementReview\ManagementReviewService;
use App\Services\Modules\ModuleEntitlementService;
use App\Support\CustomerPermissionCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Concerns\CreatesManagementReviewScenarios;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Ledelsens gjennomgåelse, from database to page: the review itself, who may do what, and the
 * lifecycle Utkast → Ferdigstilt (docs/management-review-v1-plan.md §6, §8).
 *
 * What these tests defend:
 *
 *  - The module is part of Basis and needs management_review.view; edit, finalize and delete are
 *    their own keys. System Owner holds them (not an explicit-grant domain).
 *  - Another customer's review is undiscoverable: not listed, and a 404 for read and every write.
 *  - Owners and participants are people of the same customer; fagområder are the customer's own.
 *  - Two stored statuses. «Klar for ferdigstilling» is computed, and finalizing refuses a review
 *    that is not ready.
 *  - A finalized review is frozen by the database too: the review row, its participants, sections
 *    and fagområder, and the snapshot. Corrections are amendments, append-only. Only the next review
 *    date and the owner stay open.
 *  - Every step that matters to the trail writes an event.
 */
class ManagementReviewTest extends TestCase
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

    // ---------------------------------------------------------------------
    // Access
    // ---------------------------------------------------------------------

    public function test_basis_carries_the_module_and_a_reader_sees_the_overview(): void
    {
        ['customer' => $customer] = $this->mrContext();
        $reader = $this->mrReader($customer);

        $this->assertTrue(app(ModuleEntitlementService::class)->hasModule($customer, 'management_review'));

        $this->actingAs($reader)->get('/app/management-reviews')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('App/ManagementReview/Index')
                ->where('permissions.can_create', false));
    }

    public function test_without_the_view_permission_or_the_module_every_route_is_forbidden(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->mrContext();
        $review = $this->mrReview($owner);
        $outsider = $this->mrMember($customer);

        $this->actingAs($outsider)->get('/app/management-reviews')->assertForbidden();
        $this->actingAs($outsider)->get("/app/management-reviews/{$review->id}")->assertForbidden();

        ['customer' => $tenderOnly] = $this->mrContext(['tender']);
        $tenderReader = $this->mrReader($tenderOnly);
        // The module guard sends a customer without the module away before the controller runs.
        $this->assertNotSame(200, $this->actingAs($tenderReader)->get('/app/management-reviews')->getStatusCode());
    }

    public function test_system_owner_reads_and_runs_reviews_without_a_role(): void
    {
        ['owner' => $owner] = $this->mrContext();

        $this->actingAs($owner)->post('/app/management-reviews', $this->mrPayload($owner))->assertSessionHasNoErrors();

        $review = ManagementReview::query()->where('customer_id', $owner->customer_id)->sole();
        $this->actingAs($owner)->get("/app/management-reviews/{$review->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('App/ManagementReview/Show')
                ->where('permissions.can_edit', true)
                ->where('permissions.can_finalize', true));
    }

    public function test_another_customers_review_is_a_404_for_read_and_write(): void
    {
        ['owner' => $owner] = $this->mrContext();
        $review = $this->mrReview($owner);
        ['customer' => $other] = $this->mrContext();
        $stranger = $this->mrManager($other);

        $this->actingAs($stranger)->get("/app/management-reviews/{$review->id}")->assertNotFound();
        $this->actingAs($stranger)->patch("/app/management-reviews/{$review->id}", $this->mrPayload($stranger))->assertNotFound();
        $this->actingAs($stranger)->post("/app/management-reviews/{$review->id}/finalize")->assertNotFound();
        $this->actingAs($stranger)->get("/app/management-reviews/{$review->id}/report")->assertNotFound();
        $this->actingAs($stranger)->get("/app/management-reviews/{$review->id}/report.pdf")->assertNotFound();
        $this->actingAs($stranger)->delete("/app/management-reviews/{$review->id}")->assertNotFound();

        $this->actingAs($stranger)->get('/app/management-reviews')
            ->assertInertia(fn (Assert $page) => $page->has('reviews', 0));
    }

    public function test_a_reader_cannot_create_edit_finalize_or_delete(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->mrContext();
        $review = $this->mrReview($owner);
        $reader = $this->mrReader($customer);

        $this->actingAs($reader)->post('/app/management-reviews', $this->mrPayload($reader))->assertForbidden();
        $this->actingAs($reader)->patch("/app/management-reviews/{$review->id}", $this->mrPayload($owner))->assertForbidden();
        $this->actingAs($reader)->post("/app/management-reviews/{$review->id}/finalize")->assertForbidden();
        $this->actingAs($reader)->delete("/app/management-reviews/{$review->id}")->assertForbidden();
        $this->actingAs($reader)->put("/app/management-reviews/{$review->id}/sections/resources", ['judgement' => 'satisfactory'])->assertForbidden();
    }

    // ---------------------------------------------------------------------
    // Creating and editing
    // ---------------------------------------------------------------------

    public function test_a_manager_creates_a_draft_with_participants_scope_and_frameworks(): void
    {
        ['customer' => $customer] = $this->mrContext();
        $manager = $this->mrManager($customer);
        $colleague = $this->mrMember($customer, 'Kari Leder');
        $area = $this->mrArea($customer, 'Drift');

        $this->actingAs($manager)->post('/app/management-reviews', $this->mrPayload($manager, [
            'all_business_areas' => false,
            'business_area_ids' => [$area->id],
            'frameworks' => ['iso9001', 'not-a-framework'],
            'participant_user_ids' => [$colleague->id],
            'status' => 'finalized',
        ]))->assertSessionHasErrors('frameworks.1');

        $this->actingAs($manager)->post('/app/management-reviews', $this->mrPayload($manager, [
            'all_business_areas' => false,
            'business_area_ids' => [$area->id],
            'frameworks' => ['iso9001'],
            'participant_user_ids' => [$colleague->id],
            'status' => 'finalized',
        ]))->assertSessionHasNoErrors();

        $review = ManagementReview::query()->where('customer_id', $customer->id)->sole();
        // Status is never a form field.
        $this->assertSame(ManagementReview::STATUS_DRAFT, $review->status);
        $this->assertSame([$area->id], $review->scopeAreaIds());
        $this->assertSame(['iso9001'], $review->frameworks);
        $this->assertSame(['Kari Leder'], $review->participants()->pluck('name')->all());
        $this->assertSame([ManagementReviewEvent::CREATED], ManagementReviewEvent::query()->where('management_review_id', $review->id)->pluck('event')->all());
    }

    public function test_owners_participants_and_areas_must_belong_to_the_customer(): void
    {
        ['customer' => $customer] = $this->mrContext();
        $manager = $this->mrManager($customer);
        ['customer' => $other] = $this->mrContext();
        $foreigner = $this->mrManager($other);
        $foreignArea = $this->mrArea($other, 'Fremmed');
        $noAccess = $this->mrMember($customer);

        $this->actingAs($manager)->post('/app/management-reviews', $this->mrPayload($manager, ['owner_user_id' => $foreigner->id]))
            ->assertSessionHasErrors('owner_user_id');
        // An owner must be able to read reviews.
        $this->actingAs($manager)->post('/app/management-reviews', $this->mrPayload($manager, ['owner_user_id' => $noAccess->id]))
            ->assertSessionHasErrors('owner_user_id');
        $this->actingAs($manager)->post('/app/management-reviews', $this->mrPayload($manager, ['all_business_areas' => false, 'business_area_ids' => [$foreignArea->id]]))
            ->assertSessionHasErrors('business_area_ids');
        $this->actingAs($manager)->post('/app/management-reviews', $this->mrPayload($manager, ['period_end' => '2025-12-31']))
            ->assertSessionHasErrors('period_end');

        $review = $this->mrReview($manager);
        $this->actingAs($manager)->post("/app/management-reviews/{$review->id}/participants", ['user_id' => $foreigner->id])
            ->assertSessionHasErrors('user_id');
        $this->actingAs($manager)->post("/app/management-reviews/{$review->id}/participants", ['name' => 'Ekstern revisor', 'role_label' => 'Revisor'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['Ekstern revisor'], $review->participants()->pluck('name')->all());
        $this->assertSame(0, ManagementReview::query()->where('customer_id', $customer->id)->whereKeyNot($review->id)->count());
    }

    public function test_a_section_judgement_and_the_conclusion_are_saved_on_the_draft(): void
    {
        ['customer' => $customer] = $this->mrContext();
        $manager = $this->mrManager($customer);
        $review = $this->mrReview($manager);

        $this->actingAs($manager)->put("/app/management-reviews/{$review->id}/sections/resources", [
            'judgement' => 'needs_improvement',
            'comment' => 'Behov for mer kapasitet.',
            'notes' => 'To nye rådgivere ansatt.',
        ])->assertSessionHasNoErrors();
        $this->actingAs($manager)->put("/app/management-reviews/{$review->id}/sections/not_a_section", ['judgement' => 'satisfactory'])
            ->assertNotFound();
        $this->actingAs($manager)->put("/app/management-reviews/{$review->id}/conclusion", ['conclusion' => 'Systemet er egnet.'])
            ->assertSessionHasNoErrors();

        $section = ManagementReviewSection::query()->where('management_review_id', $review->id)->sole();
        $this->assertSame(['needs_improvement', 'Behov for mer kapasitet.', 'To nye rådgivere ansatt.'], [$section->judgement, $section->comment, $section->notes]);
        $this->assertSame('Systemet er egnet.', $review->fresh()->conclusion);
    }

    // ---------------------------------------------------------------------
    // Readiness and finalizing
    // ---------------------------------------------------------------------

    public function test_finalizing_is_refused_until_the_review_is_ready(): void
    {
        ['customer' => $customer] = $this->mrContext();
        $manager = $this->mrManager($customer, [CustomerPermissionCatalog::QUALITY_VIEW]);
        $review = $this->mrReview($manager, ['meeting_date' => null]);

        $this->actingAs($manager)->get("/app/management-reviews/{$review->id}")
            ->assertInertia(fn (Assert $page) => $page->where('readiness.ready', false));

        $this->actingAs($manager)->post("/app/management-reviews/{$review->id}/finalize")->assertSessionHasErrors('review');
        $this->assertTrue($review->fresh()->isDraft());

        $this->makeReady($manager, $review);

        $this->actingAs($manager)->get("/app/management-reviews/{$review->id}")
            ->assertInertia(fn (Assert $page) => $page->where('readiness.ready', true));
        $this->actingAs($manager)->post("/app/management-reviews/{$review->id}/finalize")->assertSessionHasNoErrors();

        $review->refresh();
        $this->assertTrue($review->isFinalized());
        $this->assertSame($manager->name, $review->finalized_by_name);
        $this->assertGreaterThan(0, ManagementReviewSnapshotSection::query()->where('management_review_id', $review->id)->count());
        $this->assertTrue(ManagementReviewEvent::query()->where('management_review_id', $review->id)->where('event', ManagementReviewEvent::FINALIZED)->exists());
    }

    public function test_a_finalized_review_is_locked_in_the_application_and_the_database(): void
    {
        ['customer' => $customer] = $this->mrContext();
        $manager = $this->mrManager($customer);
        $review = $this->finalized($manager);

        $this->actingAs($manager)->patch("/app/management-reviews/{$review->id}", $this->mrPayload($manager, ['title' => 'Ny tittel']))
            ->assertSessionHasErrors('review');
        $this->actingAs($manager)->put("/app/management-reviews/{$review->id}/sections/resources", ['judgement' => 'not_satisfactory'])
            ->assertSessionHasErrors('review');
        $this->actingAs($manager)->post("/app/management-reviews/{$review->id}/participants", ['name' => 'Etternøler'])
            ->assertSessionHasErrors('review');
        $this->actingAs($manager)->delete("/app/management-reviews/{$review->id}")->assertSessionHasErrors('review');
        $this->assertSame('Ledelsens gjennomgåelse 2026', $review->fresh()->title);

        $this->assertDatabaseRefuses(fn () => DB::table('management_reviews')->where('id', $review->id)->update(['title' => 'Endret']));
        $this->assertDatabaseRefuses(fn () => DB::table('management_reviews')->where('id', $review->id)->update(['status' => 'draft']));
        $this->assertDatabaseRefuses(fn () => DB::table('management_reviews')->where('id', $review->id)->delete());
        $this->assertDatabaseRefuses(fn () => DB::table('management_review_sections')->where('management_review_id', $review->id)->update(['judgement' => 'not_satisfactory']));
        $this->assertDatabaseRefuses(fn () => DB::table('management_review_participants')->where('management_review_id', $review->id)->delete());
        $this->assertDatabaseRefuses(fn () => DB::table('management_review_participants')->insert([
            'customer_id' => $customer->id, 'management_review_id' => $review->id, 'name' => 'Sniker', 'position' => 9,
        ]));
        $this->assertDatabaseRefuses(fn () => DB::table('management_review_snapshot_sections')->where('management_review_id', $review->id)->update(['payload' => '{}']));
        $this->assertDatabaseRefuses(fn () => DB::table('management_review_snapshot_sections')->where('management_review_id', $review->id)->delete());

        $snapshot = ManagementReviewSnapshotSection::query()->where('management_review_id', $review->id)->first();
        $this->expectException(LogicException::class);
        $snapshot->delete();
    }

    public function test_the_next_review_date_and_owner_stay_open_after_finalizing_and_are_logged(): void
    {
        ['customer' => $customer] = $this->mrContext();
        $manager = $this->mrManager($customer);
        $successor = $this->mrReader($customer);
        $review = $this->finalized($manager);

        $this->actingAs($manager)->put("/app/management-reviews/{$review->id}/next-review", [
            'next_review_due_on' => '2027-06-30',
            'owner_user_id' => $successor->id,
        ])->assertSessionHasNoErrors();

        $review->refresh();
        $this->assertSame('2027-06-30', $review->next_review_due_on->toDateString());
        $this->assertSame($successor->id, $review->owner_user_id);
        $this->assertTrue(ManagementReviewEvent::query()->where('management_review_id', $review->id)->where('event', ManagementReviewEvent::NEXT_REVIEW_CHANGED)->exists());
    }

    public function test_corrections_are_amendments_and_are_append_only(): void
    {
        ['customer' => $customer, 'owner' => $owner] = $this->mrContext();
        $manager = $this->mrManager($customer);
        $editorOnly = $this->mrMember($customer);
        $this->mrGrant($customer, $editorOnly, [CustomerPermissionCatalog::MANAGEMENT_REVIEW_VIEW, CustomerPermissionCatalog::MANAGEMENT_REVIEW_EDIT]);
        $draft = $this->mrReview($manager);
        $review = $this->finalized($manager);

        $this->actingAs($manager)->post("/app/management-reviews/{$draft->id}/amendments", ['text' => 'Rettelse', 'reason' => 'Feil'])
            ->assertSessionHasErrors('text');
        $this->actingAs($editorOnly)->post("/app/management-reviews/{$review->id}/amendments", ['text' => 'Rettelse', 'reason' => 'Feil'])
            ->assertForbidden();
        $this->actingAs($manager)->post("/app/management-reviews/{$review->id}/amendments", ['text' => 'Møtedato var 2. juli.', 'reason' => 'Skrivefeil'])
            ->assertSessionHasNoErrors();

        $amendment = ManagementReviewAmendment::query()->where('management_review_id', $review->id)->sole();
        $this->assertSame($manager->name, $amendment->created_by_name);
        $this->assertDatabaseRefuses(fn () => DB::table('management_review_amendments')->where('id', $amendment->id)->update(['text' => 'Annet']));
        $this->assertDatabaseRefuses(fn () => DB::table('management_review_amendments')->where('id', $amendment->id)->delete());
        $this->assertTrue(ManagementReviewEvent::query()->where('management_review_id', $review->id)->where('event', ManagementReviewEvent::AMENDMENT_ADDED)->exists());

        $this->actingAs($owner)->get("/app/management-reviews/{$review->id}")
            ->assertInertia(fn (Assert $page) => $page->has('amendments', 1)->where('permissions.can_amend', true));
    }

    public function test_a_draft_is_deleted_and_its_deletion_stays_in_the_trail(): void
    {
        ['customer' => $customer] = $this->mrContext();
        $manager = $this->mrManager($customer);
        $review = $this->mrReview($manager);

        $this->actingAs($manager)->delete("/app/management-reviews/{$review->id}")->assertRedirect('/app/management-reviews');

        $this->assertNull(ManagementReview::query()->find($review->id));
        $deleted = ManagementReviewEvent::query()->where('customer_id', $customer->id)->where('event', ManagementReviewEvent::DELETED)->sole();
        $this->assertNull($deleted->management_review_id);
        $this->assertSame('Ledelsens gjennomgåelse 2026', $deleted->metadata['title']);
    }

    public function test_deleting_a_customer_still_cascades_through_the_locked_rows(): void
    {
        ['customer' => $customer] = $this->mrContext();
        $manager = $this->mrManager($customer);
        $review = $this->finalized($manager);
        app(ManagementReviewService::class)->addAmendment($manager, $review, 'Tillegg', 'Grunn');

        $customer->delete();

        $this->assertSame(0, DB::table('management_reviews')->where('customer_id', $customer->id)->count());
        $this->assertSame(0, DB::table('management_review_snapshot_sections')->where('customer_id', $customer->id)->count());
        $this->assertSame(0, DB::table('management_review_amendments')->where('customer_id', $customer->id)->count());
    }

    public function test_the_permissions_appear_in_the_catalogue_with_labels(): void
    {
        $this->assertSame([
            'management_review.view', 'management_review.edit', 'management_review.finalize', 'management_review.delete',
        ], CustomerPermissionCatalog::domains()[CustomerPermissionCatalog::DOMAIN_MANAGEMENT_REVIEW]);
        $this->assertFalse(CustomerPermissionCatalog::requiresExplicitGrant(CustomerPermissionCatalog::MANAGEMENT_REVIEW_VIEW));
        $this->assertFalse(CustomerPermissionCatalog::isAreaScoped(CustomerPermissionCatalog::MANAGEMENT_REVIEW_VIEW));

        foreach (['no', 'en'] as $locale) {
            app()->setLocale($locale);
            $this->assertStringNotContainsString('procynia.', CustomerPermissionCatalog::label(CustomerPermissionCatalog::MANAGEMENT_REVIEW_FINALIZE));
            $this->assertStringNotContainsString('procynia.', CustomerPermissionCatalog::domainLabel(CustomerPermissionCatalog::DOMAIN_MANAGEMENT_REVIEW));
        }
    }

    private function makeReady($manager, ManagementReview $review): void
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

    private function finalized($manager): ManagementReview
    {
        $review = $this->mrReview($manager);
        $this->makeReady($manager, $review);
        $this->actingAs($manager)->post("/app/management-reviews/{$review->id}/finalize")->assertSessionHasNoErrors();

        return $review->fresh();
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
