<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\CustomerAiCaseUsage;
use App\Models\SavedNotice;
use App\Models\SavedNoticeAiDocument;
use App\Models\SavedNoticeAiDocumentChunk;
use App\Models\SavedNoticeAiRequirement;
use App\Models\SavedNoticeAiRequirementAssessment;
use App\Models\User;
use App\Services\Ai\AiUsageGuard;
use App\Services\Ai\Wiki\RequirementWikiAssessmentAiClient;
use App\Services\Ai\Wiki\RequirementWikiAssessmentService;
use App\Services\Ai\Wiki\RequirementWikiResearchAiClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Purpose: Verify "Vurdering" (assessment refresh) is now Enterprise Wiki-based — the last active
 * Knowledge Base AI consumer moved off it (AI-to-Wiki consolidation, final functional phase).
 * Exercises the REAL RequirementWikiResearchService/RequirementWikiAssessmentService through the
 * HTTP route; only the OpenAI-calling boundaries (RequirementWikiResearchAiClient,
 * RequirementWikiAssessmentAiClient) are faked. Confirms: only confirmed/approved requirements are
 * assessed, cross-customer isolation, ai_instructions reaches the AI client, zero Wiki pages still
 * produces a valid "missing" assessment (never a 500 or hidden Knowledge Base fallback), and AI
 * failures are persisted as a controlled "failed" row without downgrading a prior completed one.
 * Inputs: None.
 * Returns: None.
 * Side effects: None.
 */
class RequirementWikiAssessmentControllerTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
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

    public function test_it_assesses_only_confirmed_requirements_using_approved_wiki_pages(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'WIKI-ASSESS-001', 'Wiki assessment case');
        $page = $this->createWikiPageWithVersion($context['customer'], 'Dokumentasjonsrutine', 'Leverandøren dokumenterer erfaring fra tilsvarende prosjekter i egen rutine.');

        $document = $this->createAiDocument($savedNotice);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal dokumentere erfaring.');
        $confirmedRequirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_text' => 'Leverandøren skal dokumentere erfaring fra tilsvarende prosjekter.',
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
        ]);
        $pendingRequirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_text' => 'Krav som ikke er godkjent ennå.',
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_PENDING,
            'approval_status' => SavedNoticeAiRequirement::APPROVAL_STATUS_DRAFT,
        ]);

        $foreignContext = $this->customerAdminContext('Foreign Assessment AS');
        $foreignNotice = $this->createSavedNotice($foreignContext['customer']->id, 'WIKI-ASSESS-002', 'Foreign assessment case');
        $foreignDocument = $this->createAiDocument($foreignNotice);
        $foreignChunk = $this->createAiDocumentChunk($foreignDocument, 'Krav for annen kunde.');
        $foreignRequirement = $this->createAiRequirement($foreignNotice, $foreignDocument, $foreignChunk, [
            'requirement_text' => 'Krav for annen kunde.',
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
        ]);

        $this->mock(RequirementWikiResearchAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('selectNextAction')
            ->once()
            ->andReturn(['action' => 'read_pages', 'page_ids' => [$page->id], 'search_terms' => [], 'reason' => 'Direkte relevant.']));
        $this->mock(RequirementWikiAssessmentAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('assessRequirement')
            ->once()
            ->withArgs(fn (string $identifier, string $text, array $pages): bool => count($pages) === 1 && $pages[0]['page_id'] === $page->id)
            ->andReturn([
                'coverage_status' => SavedNoticeAiRequirementAssessment::COVERAGE_STATUS_COVERED,
                'has_possible_conflict' => false,
                'risk_level' => SavedNoticeAiRequirementAssessment::RISK_LEVEL_LOW,
                'requirement_summary' => 'Kravet gjelder dokumentert erfaring.',
                'coverage_rationale' => 'Wiki-siden dokumenterer rutinen tydelig.',
                'missing_information' => '',
                'recommended_next_step' => 'Ingen handling nødvendig.',
            ]));

        $response = $this->actingAs($context['user'])
            ->from(route('app.ai.show', ['savedNotice' => $savedNotice->id]))
            ->post(route('app.ai.requirements.assessment.refresh', ['savedNotice' => $savedNotice->id]));

        $response->assertRedirect(route('app.ai.show', ['savedNotice' => $savedNotice->id]));
        $response->assertSessionHas('success', 'Krav analysert.');

        $this->assertDatabaseHas('saved_notice_ai_requirement_assessments', [
            'saved_notice_ai_requirement_id' => $confirmedRequirement->id,
            'assessment_status' => SavedNoticeAiRequirementAssessment::ASSESSMENT_STATUS_COMPLETED,
            'coverage_status' => SavedNoticeAiRequirementAssessment::COVERAGE_STATUS_COVERED,
            'has_possible_conflict' => false,
        ]);
        $this->assertDatabaseMissing('saved_notice_ai_requirement_assessments', [
            'saved_notice_ai_requirement_id' => $pendingRequirement->id,
        ]);
        $this->assertDatabaseMissing('saved_notice_ai_requirement_assessments', [
            'saved_notice_ai_requirement_id' => $foreignRequirement->id,
        ]);

        $assessment = SavedNoticeAiRequirementAssessment::query()
            ->where('saved_notice_ai_requirement_id', $confirmedRequirement->id)
            ->firstOrFail();
        $this->assertSame(RequirementWikiAssessmentService::ENGINE_VERSION, $assessment->engine_version);
        $this->assertCount(1, $assessment->wiki_sources_snapshot);
        $this->assertSame($page->id, $assessment->wiki_sources_snapshot[0]['enterprise_wiki_page_id']);

        $caseUsage = CustomerAiCaseUsage::query()
            ->where('saved_notice_id', $savedNotice->id)
            ->where('source_operation_key', AiUsageGuard::OPERATION_SAVED_NOTICE_ASSESSMENT_REFRESH)
            ->first();
        $this->assertNotNull($caseUsage, 'Assessment refresh must still record one AI case usage row.');
    }

    public function test_the_saved_notices_ai_instructions_reach_the_assessment_ai_client(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'WIKI-ASSESS-003', 'Wiki assessment instructions case');
        $savedNotice->update(['ai_instructions' => 'Skriv kort og presist.']);
        $document = $this->createAiDocument($savedNotice);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal levere dokumentasjon innen ti dager.');
        $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_text' => 'Leverandøren skal levere dokumentasjon innen ti dager.',
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
        ]);

        $this->mock(RequirementWikiResearchAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('selectNextAction'));
        $this->mock(RequirementWikiAssessmentAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('assessRequirement')
            ->once()
            ->withArgs(fn (string $identifier, string $text, array $pages, string $language, ?string $caseInstructions): bool => $caseInstructions === 'Skriv kort og presist.')
            ->andReturn([
                'coverage_status' => SavedNoticeAiRequirementAssessment::COVERAGE_STATUS_MISSING,
                'has_possible_conflict' => false,
                'risk_level' => SavedNoticeAiRequirementAssessment::RISK_LEVEL_MEDIUM,
                'requirement_summary' => 'Kravet gjelder frist for levering.',
                'coverage_rationale' => 'Ingen Wiki-side dekker fristen.',
                'missing_information' => 'Rutine for tidsfrister er ikke dokumentert.',
                'recommended_next_step' => 'Dokumenter rutinen i Enterprise Wiki.',
            ]));

        $response = $this->actingAs($context['user'])
            ->post(route('app.ai.requirements.assessment.refresh', ['savedNotice' => $savedNotice->id]));

        $response->assertRedirect();
        $this->assertDatabaseCount('saved_notice_ai_requirement_assessments', 1);
    }

    public function test_zero_wiki_pages_still_produces_a_valid_missing_assessment(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'WIKI-ASSESS-004', 'Wiki assessment no coverage case');
        $document = $this->createAiDocument($savedNotice);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal ha en beredskapsplan.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_text' => 'Leverandøren skal ha en beredskapsplan.',
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
        ]);

        // No Wiki pages exist for this customer at all — research returns an empty catalog.
        $this->mock(RequirementWikiAssessmentAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('assessRequirement')
            ->once()
            ->withArgs(fn (string $identifier, string $text, array $pages): bool => $pages === [])
            ->andReturn([
                'coverage_status' => SavedNoticeAiRequirementAssessment::COVERAGE_STATUS_MISSING,
                'has_possible_conflict' => false,
                'risk_level' => SavedNoticeAiRequirementAssessment::RISK_LEVEL_MEDIUM,
                'requirement_summary' => 'Kravet gjelder beredskapsplan.',
                'coverage_rationale' => 'Ingen godkjent Wiki-informasjon er tilgjengelig for dette kravet.',
                'missing_information' => 'Beredskapsplan er ikke dokumentert i Enterprise Wiki.',
                'recommended_next_step' => 'Dokumenter beredskapsplanen i Enterprise Wiki.',
            ]));

        $response = $this->actingAs($context['user'])
            ->post(route('app.ai.requirements.assessment.refresh', ['savedNotice' => $savedNotice->id]));

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Krav analysert.');

        $this->assertDatabaseHas('saved_notice_ai_requirement_assessments', [
            'saved_notice_ai_requirement_id' => $requirement->id,
            'assessment_status' => SavedNoticeAiRequirementAssessment::ASSESSMENT_STATUS_COMPLETED,
            'coverage_status' => SavedNoticeAiRequirementAssessment::COVERAGE_STATUS_MISSING,
        ]);
    }

    public function test_has_possible_conflict_is_persisted_and_exposed_in_the_case_view(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'WIKI-ASSESS-005', 'Wiki assessment conflict case');
        $page = $this->createWikiPageWithVersion($context['customer'], 'Ansvarsmodell', 'Kunden har ansvar for driften av løsningen.');
        $document = $this->createAiDocument($savedNotice);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal drifte løsningen.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_text' => 'Leverandøren skal drifte løsningen.',
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
        ]);

        $this->mock(RequirementWikiResearchAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('selectNextAction')
            ->once()
            ->andReturn(['action' => 'read_pages', 'page_ids' => [$page->id], 'search_terms' => [], 'reason' => 'Direkte relevant.']));
        $this->mock(RequirementWikiAssessmentAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('assessRequirement')
            ->once()
            ->andReturn([
                'coverage_status' => SavedNoticeAiRequirementAssessment::COVERAGE_STATUS_PARTIAL,
                'has_possible_conflict' => true,
                'risk_level' => SavedNoticeAiRequirementAssessment::RISK_LEVEL_HIGH,
                'requirement_summary' => 'Kravet gjelder drift av løsningen.',
                'coverage_rationale' => 'Wiki-siden dokumenterer at Kunden har driftsansvaret, motsatt av kravet.',
                'missing_information' => 'Avklar ansvarsfordeling for drift.',
                'recommended_next_step' => 'Avklar motstrid mellom kravet og dokumentert ansvarsmodell.',
            ]));

        $response = $this->actingAs($context['user'])
            ->post(route('app.ai.requirements.assessment.refresh', ['savedNotice' => $savedNotice->id]));
        $response->assertRedirect();
        $response->assertSessionHas('success', 'Krav analysert.');

        $this->assertDatabaseHas('saved_notice_ai_requirement_assessments', [
            'saved_notice_ai_requirement_id' => $requirement->id,
            'has_possible_conflict' => true,
        ]);

        $response = $this->actingAs($context['user'])->get(route('app.ai.show', ['savedNotice' => $savedNotice->id]));
        $response->assertOk();
        $page2 = $this->inertiaPageFromResponse($response);
        $requirementRow = collect(data_get($page2, 'props.requirements', []))->firstWhere('id', $requirement->id);

        $this->assertTrue(data_get($requirementRow, 'assessment.has_possible_conflict'));
        $this->assertSame($page->id, data_get($requirementRow, 'assessment.wiki_sources.0.enterprise_wiki_page_id'));
    }

    public function test_a_failed_assessment_is_persisted_as_failed_without_downgrading_a_prior_completed_row(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'WIKI-ASSESS-006', 'Wiki assessment failure case');
        $document = $this->createAiDocument($savedNotice);
        $chunkA = $this->createAiDocumentChunk($document, 'Krav A: allerede vurdert.');
        $chunkB = $this->createAiDocumentChunk($document, 'Krav B: vurdering feiler.', 1);
        $alreadyAssessedRequirement = $this->createAiRequirement($savedNotice, $document, $chunkA, [
            'requirement_identifier' => '1.1',
            'requirement_text' => 'Krav A: allerede vurdert.',
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
        ]);
        $newRequirement = $this->createAiRequirement($savedNotice, $document, $chunkB, [
            'requirement_identifier' => '1.2',
            'requirement_text' => 'Krav B: vurdering feiler.',
            'review_status' => SavedNoticeAiRequirement::REVIEW_STATUS_CONFIRMED,
        ]);

        SavedNoticeAiRequirementAssessment::query()->create([
            'saved_notice_ai_requirement_id' => $alreadyAssessedRequirement->id,
            'assessment_status' => SavedNoticeAiRequirementAssessment::ASSESSMENT_STATUS_COMPLETED,
            'coverage_status' => SavedNoticeAiRequirementAssessment::COVERAGE_STATUS_COVERED,
            'risk_level' => SavedNoticeAiRequirementAssessment::RISK_LEVEL_LOW,
            'requirement_summary' => 'Eksisterende vurdering.',
            'coverage_rationale' => 'Allerede dekket.',
            'missing_information' => '',
            'recommended_next_step' => 'Ingen handling.',
            'wiki_sources_snapshot' => [],
            'assessed_at' => now(),
        ]);

        $this->mock(RequirementWikiResearchAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('selectNextAction'));
        $this->mock(RequirementWikiAssessmentAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('assessRequirement')
            ->twice()
            ->andThrow(new \RuntimeException('Simulated OpenAI failure.')));

        $response = $this->actingAs($context['user'])
            ->post(route('app.ai.requirements.assessment.refresh', ['savedNotice' => $savedNotice->id]));

        $response->assertRedirect();
        $response->assertSessionHas('warning', 'AI-vurdering feilet for ett eller flere krav.');

        // The prior completed assessment must be preserved untouched, never downgraded to failed.
        $this->assertDatabaseHas('saved_notice_ai_requirement_assessments', [
            'saved_notice_ai_requirement_id' => $alreadyAssessedRequirement->id,
            'assessment_status' => SavedNoticeAiRequirementAssessment::ASSESSMENT_STATUS_COMPLETED,
            'coverage_status' => SavedNoticeAiRequirementAssessment::COVERAGE_STATUS_COVERED,
        ]);

        $this->assertDatabaseHas('saved_notice_ai_requirement_assessments', [
            'saved_notice_ai_requirement_id' => $newRequirement->id,
            'assessment_status' => SavedNoticeAiRequirementAssessment::ASSESSMENT_STATUS_FAILED,
            'coverage_status' => null,
            'risk_level' => null,
        ]);

        $failedAssessment = SavedNoticeAiRequirementAssessment::query()
            ->where('saved_notice_ai_requirement_id', $newRequirement->id)
            ->firstOrFail();
        $this->assertSame([], $failedAssessment->wiki_sources_snapshot);
        $this->assertNull($failedAssessment->assessed_at);
    }

    private function customerAdminContext(string $customerName = 'Wiki Assessment Controller Test AS'): array
    {
        $customer = $this->createWikiCustomer($customerName);
        $customer->forceFill([
            'subscription_plan' => Customer::PLAN_PRO,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'included_ai_credits' => 20,
        ])->save();

        $user = User::factory()->create([
            'name' => 'Wiki Assessment Tester',
            'email' => Str::slug($customerName).'.wiki.assess.'.Str::lower(Str::random(6)).'@example.test',
            'role' => User::ROLE_CUSTOMER_ADMIN,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);

        return ['customer' => $customer, 'user' => $user];
    }

    private function createSavedNotice(int $customerId, string $externalId, string $title): SavedNotice
    {
        return SavedNotice::query()->create([
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
        ]);
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

    private function createAiDocumentChunk(SavedNoticeAiDocument $document, string $content, int $chunkIndex = 0): SavedNoticeAiDocumentChunk
    {
        return SavedNoticeAiDocumentChunk::query()->create([
            'saved_notice_ai_document_id' => $document->id,
            'chunk_index' => $chunkIndex,
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

    /**
     * Purpose: Decode the Inertia page payload from a customer-app test response.
     * Inputs: The HTTP test response returned by the AI page request.
     * Returns: The decoded Inertia page array.
     * Side effects: Throws when the response does not contain a readable Inertia payload.
     */
    private function inertiaPageFromResponse(TestResponse $response): array
    {
        try {
            $page = $response->viewData('page');

            if (is_array($page)) {
                return $page;
            }
        } catch (\Throwable) {
            // Fall through to the HTML payload parser below.
        }

        $content = $response->getContent();
        preg_match('/data-page="([^"]+)"/', $content, $matches);

        if (! isset($matches[1])) {
            throw new \RuntimeException('Unable to extract the Inertia page payload from the response.');
        }

        $page = json_decode(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($page)) {
            throw new \RuntimeException('Decoded Inertia page payload is not an array.');
        }

        return $page;
    }
}
