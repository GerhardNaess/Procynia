<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\SavedNotice;
use App\Models\SavedNoticeAiDocument;
use App\Models\SavedNoticeAiRequirement;
use App\Models\SavedNoticeAiRequirementWikiAnswer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Permanent deletion of extracted requirements, alongside — never replacing — reversible rejection.
 *
 * The two matter separately: rejection takes a requirement out of active work and can be undone,
 * deletion cannot and takes the answer draft with it. These tests pin that distinction, and that
 * scope is decided by the server rather than by whatever id a client sends.
 */
class RequirementDeletionTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
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

    private function context(string $name = 'Delete Test AS'): array
    {
        $customer = $this->createWikiCustomer($name);
        $customer->forceFill(['subscription_plan' => Customer::PLAN_PRO, 'included_ai_credits' => 20])->save();

        $user = User::factory()->create([
            'name' => 'Delete Tester',
            'email' => Str::lower(Str::random(10)).'@example.test',
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        return ['customer' => $customer, 'user' => $user, 'saved_notice' => $this->savedNotice($customer->id)];
    }

    private function savedNotice(int $customerId): SavedNotice
    {
        return SavedNotice::query()->create([
            'customer_id' => $customerId,
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
            'source_type' => SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE,
            'external_id' => 'DEL-'.Str::upper(Str::random(8)),
            'title' => 'Slettetest', 'buyer_name' => 'Procynia',
            'external_url' => 'https://doffin.no/notices/del', 'summary' => 'Kort',
            'publication_date' => '2026-03-20 00:00:00', 'deadline' => '2026-12-31 00:00:00',
            'status' => 'ACTIVE', 'cpv_code' => '72000000',
        ]);
    }

    private function requirement(SavedNotice $savedNotice, array $overrides = []): SavedNoticeAiRequirement
    {
        return SavedNoticeAiRequirement::query()->create(array_merge([
            'saved_notice_id' => $savedNotice->id,
            'source_type' => SavedNoticeAiRequirement::SOURCE_TYPE_AI_CANDIDATE,
            'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_APPROVED,
            'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED,
            'requirement_identifier' => 'K-'.Str::upper(Str::random(4)),
            'requirement_text' => 'Leverandøren skal levere rapport.',
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'extraction_method' => SavedNoticeAiRequirement::EXTRACTION_METHOD_RULE_BASED,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
            'published_at' => now(),
        ], $overrides));
    }

    // ── single delete ────────────────────────────────────────────────────────

    public function test_one_requirement_is_permanently_deleted_and_the_others_stay(): void
    {
        $context = $this->context();
        $target = $this->requirement($context['saved_notice']);
        $bystander = $this->requirement($context['saved_notice']);

        $this->actingAs($context['user'])
            ->delete("/app/ai/{$context['saved_notice']->id}/requirements/{$target->id}")
            ->assertRedirect();

        $this->assertNull(SavedNoticeAiRequirement::query()->find($target->id));
        $this->assertNotNull(SavedNoticeAiRequirement::query()->find($bystander->id));
    }

    public function test_deleting_a_requirement_takes_its_wiki_answer_with_it(): void
    {
        // Documented consequence, not an accident: the FK cascades, and the confirmation text says so.
        $context = $this->context();
        $requirement = $this->requirement($context['saved_notice']);
        SavedNoticeAiRequirementWikiAnswer::query()->create([
            'saved_notice_ai_requirement_id' => $requirement->id,
            'coverage_status' => SavedNoticeAiRequirementWikiAnswer::COVERAGE_FULL,
            'answer_text' => 'Et utkast tilbudslederen har skrevet.',
        ]);

        $this->actingAs($context['user'])
            ->delete("/app/ai/{$context['saved_notice']->id}/requirements/{$requirement->id}");

        $this->assertSame(0, SavedNoticeAiRequirementWikiAnswer::query()
            ->where('saved_notice_ai_requirement_id', $requirement->id)->count());
    }

    public function test_a_requirement_belonging_to_another_case_cannot_be_deleted_through_this_one(): void
    {
        $context = $this->context();
        $otherCase = $this->savedNotice($context['customer']->id);
        $foreign = $this->requirement($otherCase);

        $this->actingAs($context['user'])
            ->delete("/app/ai/{$context['saved_notice']->id}/requirements/{$foreign->id}")
            ->assertNotFound();

        $this->assertNotNull(SavedNoticeAiRequirement::query()->find($foreign->id));
    }

    public function test_another_customers_requirement_is_untouchable(): void
    {
        $owner = $this->context('Eier AS');
        $intruder = $this->context('Inntrenger AS');
        $requirement = $this->requirement($owner['saved_notice']);

        $this->actingAs($intruder['user'])
            ->delete("/app/ai/{$owner['saved_notice']->id}/requirements/{$requirement->id}")
            ->assertNotFound();

        $this->assertNotNull(SavedNoticeAiRequirement::query()->find($requirement->id));
    }

    public function test_a_manually_added_requirement_is_not_deletable_here(): void
    {
        // Import residue is one thing; a requirement the user typed themselves is their own work.
        $context = $this->context();
        $manual = $this->requirement($context['saved_notice'], [
            'source_type' => SavedNoticeAiRequirement::SOURCE_TYPE_MANUAL,
        ]);

        $this->actingAs($context['user'])
            ->delete("/app/ai/{$context['saved_notice']->id}/requirements/{$manual->id}")
            ->assertNotFound();

        $this->assertNotNull(SavedNoticeAiRequirement::query()->find($manual->id));
    }

    public function test_an_unknown_requirement_id_is_refused(): void
    {
        $context = $this->context();

        $this->actingAs($context['user'])
            ->delete("/app/ai/{$context['saved_notice']->id}/requirements/999999")
            ->assertNotFound();
    }

    public function test_a_rejected_requirement_can_still_be_deleted(): void
    {
        $context = $this->context();
        $rejected = $this->requirement($context['saved_notice'], [
            'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_REJECTED,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_REJECTED,
        ]);

        $this->actingAs($context['user'])
            ->delete("/app/ai/{$context['saved_notice']->id}/requirements/{$rejected->id}")
            ->assertRedirect();

        $this->assertNull(SavedNoticeAiRequirement::query()->find($rejected->id));
    }

    // ── bulk delete ──────────────────────────────────────────────────────────

    public function test_bulk_delete_removes_every_extracted_requirement_in_this_case_only(): void
    {
        $context = $this->context();
        $otherCase = $this->savedNotice($context['customer']->id);
        $otherCustomer = $this->context('Annen kunde AS');

        foreach (range(1, 3) as $ignored) {
            $this->requirement($context['saved_notice']);
        }
        $this->requirement($context['saved_notice'], ['approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_REJECTED]);
        $manual = $this->requirement($context['saved_notice'], ['source_type' => SavedNoticeAiRequirement::SOURCE_TYPE_MANUAL]);
        $inOtherCase = $this->requirement($otherCase);
        $inOtherCustomer = $this->requirement($otherCustomer['saved_notice']);

        $this->actingAs($context['user'])
            ->delete("/app/ai/{$context['saved_notice']->id}/requirements/delete-all")
            ->assertRedirect();

        // Rejected candidates count as extracted; manual ones never do.
        $this->assertSame(1, $context['saved_notice']->aiRequirements()->count());
        $this->assertNotNull(SavedNoticeAiRequirement::query()->find($manual->id));
        $this->assertNotNull(SavedNoticeAiRequirement::query()->find($inOtherCase->id));
        $this->assertNotNull(SavedNoticeAiRequirement::query()->find($inOtherCustomer->id));
    }

    public function test_bulk_delete_on_an_empty_case_is_handled_calmly(): void
    {
        $context = $this->context();

        $this->actingAs($context['user'])
            ->delete("/app/ai/{$context['saved_notice']->id}/requirements/delete-all")
            ->assertRedirect()
            ->assertSessionHas('success', __('procynia.ai.requirements_deleted_none'));
    }

    public function test_bulk_delete_keeps_the_source_document_so_the_case_can_be_rebuilt(): void
    {
        $context = $this->context();
        $document = SavedNoticeAiDocument::query()->create([
            'saved_notice_id' => $context['saved_notice']->id,
            'original_filename' => 'Kravspesifikasjon.xlsx',
            'stored_path' => 'tmp/keep.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'file_size_bytes' => 100,
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_TEXT_EXTRACTED,
            'extracted_text' => 'Krav.',
        ]);
        $this->requirement($context['saved_notice'], ['saved_notice_ai_document_id' => $document->id]);

        $this->actingAs($context['user'])->delete("/app/ai/{$context['saved_notice']->id}/requirements/delete-all");

        $this->assertNotNull(SavedNoticeAiDocument::query()->find($document->id));
        $this->assertSame(0, $context['saved_notice']->aiRequirements()->count());
    }

    public function test_a_new_requirement_can_be_created_again_after_deletion(): void
    {
        $context = $this->context();
        $this->requirement($context['saved_notice']);

        $this->actingAs($context['user'])->delete("/app/ai/{$context['saved_notice']->id}/requirements/delete-all");
        $reimported = $this->requirement($context['saved_notice']);

        $this->assertNotNull(SavedNoticeAiRequirement::query()->find($reimported->id));
    }

    // ── rejection is untouched ───────────────────────────────────────────────

    public function test_rejection_still_keeps_the_requirement(): void
    {
        $context = $this->context();
        $requirement = $this->requirement($context['saved_notice']);

        $this->actingAs($context['user'])->patch(
            "/app/ai/{$context['saved_notice']->id}/requirements/{$requirement->id}/review-status",
            ['review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_REJECTED],
        )->assertRedirect();

        $fresh = SavedNoticeAiRequirement::query()->find($requirement->id);
        $this->assertNotNull($fresh, 'Rejection must never remove the row.');
        $this->assertSame(SavedNoticeAiRequirement::APPROVAL_STATUS_REJECTED, $fresh->approval_status);
    }

    public function test_a_rejected_requirement_can_still_be_restored(): void
    {
        $context = $this->context();
        $requirement = $this->requirement($context['saved_notice']);
        $url = "/app/ai/{$context['saved_notice']->id}/requirements/{$requirement->id}/review-status";

        $this->actingAs($context['user'])->patch($url, ['review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_REJECTED]);
        $this->actingAs($context['user'])->patch($url, ['review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_PENDING]);

        $this->assertNotSame(
            SavedNoticeAiRequirement::APPROVAL_STATUS_REJECTED,
            SavedNoticeAiRequirement::query()->find($requirement->id)->approval_status,
        );
    }
}
