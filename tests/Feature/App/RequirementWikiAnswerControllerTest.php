<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Models\SavedNotice;
use App\Models\SavedNoticeAiDocument;
use App\Models\SavedNoticeAiDocumentChunk;
use App\Models\SavedNoticeAiRequirement;
use App\Models\SavedNoticeAiRequirementWikiAnswer;
use App\Models\User;
use App\Services\Ai\Wiki\RequirementWikiAnswerAiClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Purpose: Verify the Fase 9 "Generer Wiki-svar" endpoint reuses the existing tenant-safety
 * pattern (visibleAiSavedNotice + aiRequirements()->whereKey()), persists separately from the
 * existing answer-draft flow, and exposes the new URL/payload on the AI case view.
 * Inputs: None.
 * Returns: None.
 * Side effects: None.
 */
class RequirementWikiAnswerControllerTest extends TestCase
{
    use UsesProjectPostgresConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useProjectPostgresConnection();
        DB::beginTransaction();
        config(['services.enterprise_wiki.ai_enabled' => true]);
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::disconnect(DB::getDefaultConnection());

        parent::tearDown();
    }

    public function test_it_generates_and_persists_a_wiki_answer_for_a_visible_requirement(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'WIKI-ANS-001', 'Wiki answer case');
        $document = $this->createAiDocument($savedNotice);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal levere dokumentasjon innen ti dager.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_text' => 'Leverandøren skal levere dokumentasjon innen ti dager.',
        ]);

        $this->mock(RequirementWikiAnswerAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('generateAnswer'));

        $response = $this->actingAs($context['user'])->postJson(
            "/app/ai/{$savedNotice->id}/requirements/{$requirement->id}/wiki-answer",
        );

        $response->assertOk();
        $response->assertJsonPath('requirement_id', $requirement->id);
        $response->assertJsonPath('wiki_answer.coverage_status', SavedNoticeAiRequirementWikiAnswer::COVERAGE_NONE);
        $this->assertDatabaseCount('saved_notice_ai_requirement_wiki_answers', 1);
    }

    public function test_it_returns_404_for_a_requirement_belonging_to_another_customer(): void
    {
        $contextA = $this->customerAdminContext('Customer A AS');
        $contextB = $this->customerAdminContext('Customer B AS');

        $savedNoticeB = $this->createSavedNotice($contextB['customer']->id, 'WIKI-ANS-002', 'Other customer case');
        $documentB = $this->createAiDocument($savedNoticeB);
        $chunkB = $this->createAiDocumentChunk($documentB, 'Krav for kunde B.');
        $requirementB = $this->createAiRequirement($savedNoticeB, $documentB, $chunkB);

        $response = $this->actingAs($contextA['user'])->postJson(
            "/app/ai/{$savedNoticeB->id}/requirements/{$requirementB->id}/wiki-answer",
        );

        $response->assertNotFound();
        $this->assertDatabaseCount('saved_notice_ai_requirement_wiki_answers', 0);
    }

    public function test_it_never_overwrites_the_existing_answer_draft(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'WIKI-ANS-003', 'Wiki answer preserves draft');
        $document = $this->createAiDocument($savedNotice);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal levere dokumentasjon innen ti dager.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_text' => 'Leverandøren skal levere dokumentasjon innen ti dager.',
        ]);
        $requirement->forceFill([
            'answer_draft_text' => 'Eksisterende svarutkast.',
            'answer_draft_generated_at' => now(),
        ])->save();

        $this->actingAs($context['user'])->postJson(
            "/app/ai/{$savedNotice->id}/requirements/{$requirement->id}/wiki-answer",
        )->assertOk();

        $requirement->refresh();

        $this->assertSame('Eksisterende svarutkast.', $requirement->answer_draft_text);
    }

    public function test_it_generates_a_full_coverage_wiki_answer_from_approved_claims_and_reports_sources(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'WIKI-ANS-004', 'Wiki answer full coverage');
        $document = $this->createAiDocument($savedNotice);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal levere dokumentasjon innen ti dager.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_text' => 'Leverandøren skal levere dokumentasjon innen ti dager.',
        ]);

        $claim = $this->createApprovedClaim($context['customer'], 'Dokumentasjon leveres innen ti dager i henhold til avtalen.');

        $this->mock(RequirementWikiAnswerAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('generateAnswer')
            ->once()
            ->andReturn([
                'coverage_status' => 'full',
                'answer_text' => 'Dokumentasjon leveres innen ti dager.',
                'missing_summary' => null,
                'used_claim_keys' => ['claim-'.$claim->id],
            ]));

        $response = $this->actingAs($context['user'])->postJson(
            "/app/ai/{$savedNotice->id}/requirements/{$requirement->id}/wiki-answer",
        );

        $response->assertOk();
        $response->assertJsonPath('wiki_answer.coverage_status', 'full');
        $response->assertJsonPath('wiki_answer.text', 'Dokumentasjon leveres innen ti dager.');
        $response->assertJsonPath('wiki_answer.sources.0.claim_id', $claim->id);
    }

    public function test_the_ai_case_view_exposes_the_wiki_answer_generate_url_and_payload(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'WIKI-ANS-005', 'Wiki answer show page');
        $this->touchSavedNotice($savedNotice, '2026-04-07 12:00:00');
        $document = $this->createAiDocument($savedNotice);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal levere dokumentasjon innen ti dager.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_text' => 'Leverandøren skal levere dokumentasjon innen ti dager.',
        ]);

        $response = $this->actingAs($context['user'])->get("/app/ai/{$savedNotice->id}");

        $response->assertOk();
        $response->assertViewHas('page', function (array $page) use ($requirement): bool {
            $requirements = data_get($page, 'props.requirements', []);
            $row = collect($requirements)->firstWhere('id', $requirement->id);

            return $row !== null
                && str_contains((string) data_get($row, 'wiki_answer_generate_url'), '/wiki-answer')
                && array_key_exists('wiki_answer', $row)
                && data_get($row, 'wiki_answer.coverage_status') === null;
        });
    }

    private function customerAdminContext(string $customerName = 'Wiki Answer Controller Test AS'): array
    {
        $customer = $this->createCustomer($customerName);
        $customer->forceFill([
            'subscription_plan' => Customer::PLAN_PRO,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'included_ai_credits' => 20,
        ])->save();

        $user = User::factory()->create([
            'name' => 'Wiki Answer Tester',
            'email' => Str::slug($customerName).'.wiki.tester.'.Str::lower(Str::random(6)).'@example.test',
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        return ['customer' => $customer, 'user' => $user];
    }

    private function createCustomer(string $name): Customer
    {
        $language = Language::query()->firstOrCreate(
            ['code' => 'no'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk'],
        );

        $nationality = Nationality::query()->firstOrCreate(
            ['code' => 'NO'],
            ['name_en' => 'Norwegian', 'name_no' => 'Norsk', 'flag_emoji' => 'NO'],
        );

        return Customer::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'is_active' => true,
        ]);
    }

    private function createSavedNotice(int $customerId, string $externalId, string $title): SavedNotice
    {
        $attributes = [
            'customer_id' => $customerId,
            'bid_status' => SavedNotice::BID_STATUS_QUALIFYING,
            'source_type' => SavedNotice::SOURCE_TYPE_PUBLIC_NOTICE,
            'external_id' => $externalId,
            'title' => $title,
            'buyer_name' => 'Procynia',
            'external_url' => "https://doffin.no/notices/{$externalId}",
            'summary' => 'Kort oppsummering',
            'publication_date' => '2026-03-20 00:00:00',
            'deadline' => '2026-04-20 00:00:00',
            'status' => 'ACTIVE',
            'cpv_code' => '72000000',
        ];

        return SavedNotice::query()->create($attributes);
    }

    private function touchSavedNotice(SavedNotice $savedNotice, string $timestamp): SavedNotice
    {
        DB::table('saved_notices')->where('id', $savedNotice->id)->update([
            'updated_at' => $timestamp,
            'created_at' => $timestamp,
        ]);

        return $savedNotice->refresh();
    }

    private function createAiDocument(SavedNotice $savedNotice): SavedNoticeAiDocument
    {
        return SavedNoticeAiDocument::query()->create([
            'saved_notice_id' => $savedNotice->id,
            'original_filename' => 'analysis.pdf',
            'stored_path' => sprintf('saved-notices/%d/ai-documents/analysis.pdf', $savedNotice->id),
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 1024,
            'processing_status' => SavedNoticeAiDocument::PROCESSING_STATUS_UPLOADED,
        ]);
    }

    private function createAiDocumentChunk(SavedNoticeAiDocument $document, string $content): SavedNoticeAiDocumentChunk
    {
        return SavedNoticeAiDocumentChunk::query()->create([
            'saved_notice_ai_document_id' => $document->id,
            'chunk_index' => 0,
            'content' => $content,
            'char_start' => 0,
            'char_end' => mb_strlen($content, 'UTF-8'),
            'word_count' => count(preg_split('/\s+/u', trim($content)) ?: []),
        ]);
    }

    private function createAiRequirement(
        SavedNotice $savedNotice,
        SavedNoticeAiDocument $document,
        SavedNoticeAiDocumentChunk $chunk,
        array $overrides = [],
    ): SavedNoticeAiRequirement {
        $requirementText = (string) ($overrides['requirement_text'] ?? 'Dokumentasjon må vedlegges.');

        return SavedNoticeAiRequirement::query()->create(array_merge([
            'saved_notice_id' => $savedNotice->id,
            'saved_notice_ai_document_id' => $document->id,
            'saved_notice_ai_document_chunk_id' => $chunk->id,
            'source_type' => SavedNoticeAiRequirement::SOURCE_TYPE_AI_CANDIDATE,
            'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_APPROVED,
            'publication_status' => SavedNoticeAiRequirement::PUBLICATION_STATUS_PUBLISHED,
            'requirement_identifier' => '1.1',
            'requirement_text' => $requirementText,
            'requirement_type' => SavedNoticeAiRequirement::REQUIREMENT_TYPE_DOCUMENTATION,
            'extraction_method' => SavedNoticeAiRequirement::EXTRACTION_METHOD_RULE_BASED,
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
            'published_at' => now(),
        ], $overrides));
    }

    private function createApprovedClaim(Customer $customer, string $claimText): EnterpriseWikiClaim
    {
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'wiki-answer-page-'.Str::lower(Str::random(8)),
            'title' => 'Dokumentasjonskrav',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_APPROVED,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# Dokumentasjonskrav',
        ]);

        return EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => $claimText,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
        ]);
    }
}
