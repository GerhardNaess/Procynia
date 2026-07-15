<?php

namespace Tests\Feature\App;

use App\Models\Customer;
use App\Models\SavedNotice;
use App\Models\SavedNoticeAiDocument;
use App\Models\SavedNoticeAiDocumentChunk;
use App\Models\SavedNoticeAiRequirement;
use App\Models\SavedNoticeAiRequirementWikiAnswer;
use App\Models\User;
use App\Services\Ai\Wiki\RequirementWikiAlignmentAiClient;
use App\Services\Ai\Wiki\RequirementWikiAnswerAiClient;
use App\Services\Ai\Wiki\RequirementWikiAnswerRevisionAiClient;
use App\Services\Ai\Wiki\RequirementWikiAnswerService;
use App\Services\Ai\Wiki\RequirementWikiResearchAiClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\Concerns\UsesProjectPostgresConnection;
use Tests\TestCase;

/**
 * Purpose: Verify the Fase 9 "Generer Wiki-svar" endpoint reuses the existing tenant-safety
 * pattern (visibleAiSavedNotice + aiRequirements()->whereKey()), persists separately from the
 * existing answer-draft flow, and exposes the new URL/payload — including page citations, alignment
 * classification, and discovery provenance — on the AI case view. Exercises the REAL
 * RequirementWikiResearchService/RequirementWikiAnswerService through the HTTP route; only the
 * OpenAI-calling boundaries (RequirementWikiResearchAiClient, RequirementWikiAnswerAiClient,
 * RequirementWikiAlignmentAiClient, RequirementWikiAnswerRevisionAiClient) are faked.
 * Inputs: None.
 * Returns: None.
 * Side effects: None.
 */
class RequirementWikiAnswerControllerTest extends TestCase
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

    public function test_it_generates_a_none_coverage_best_practice_wiki_answer_when_no_wiki_content_exists(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'WIKI-ANS-001', 'Wiki answer case');
        $document = $this->createAiDocument($savedNotice);
        $chunk = $this->createAiDocumentChunk($document, 'Leverandøren skal levere dokumentasjon innen ti dager.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_text' => 'Leverandøren skal levere dokumentasjon innen ti dager.',
        ]);

        $this->mock(RequirementWikiResearchAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('selectNextAction'));
        // Zero Wiki candidates still produces a real best-practice expert draft — the answer
        // client IS called, just with an empty page set, and alignment is deterministic (no
        // possible_conflict can exist without any Wiki content to conflict with).
        $this->mock(RequirementWikiAnswerAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('generateAnswer')
            ->once()
            ->withArgs(fn (string $identifier, string $text, array $pages, string $language): bool => $pages === [])
            ->andReturn(['answer_sections' => [['key' => 'S1', 'heading' => '', 'text' => 'Anbefalt fremgangsmåte basert på beste praksis.', 'used_page_ids' => []]]]));
        $this->mock(RequirementWikiAlignmentAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('assessAlignment'));
        $this->mock(RequirementWikiAnswerRevisionAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('reviseSections'));

        $response = $this->actingAs($context['user'])->postJson(
            "/app/ai/{$savedNotice->id}/requirements/{$requirement->id}/wiki-answer",
        );

        $response->assertOk();
        $response->assertJsonPath('requirement_id', $requirement->id);
        $response->assertJsonPath('wiki_answer.coverage_status', SavedNoticeAiRequirementWikiAnswer::COVERAGE_NONE);
        $response->assertJsonPath('wiki_answer.text', 'Anbefalt fremgangsmåte basert på beste praksis.');
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

        $this->mock(RequirementWikiAnswerAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('generateAnswer')
            ->once()
            ->andReturn(['answer_sections' => [['key' => 'S1', 'heading' => '', 'text' => 'Beste praksis.', 'used_page_ids' => []]]]));

        $this->actingAs($context['user'])->postJson(
            "/app/ai/{$savedNotice->id}/requirements/{$requirement->id}/wiki-answer",
        )->assertOk();

        $requirement->refresh();

        $this->assertSame('Eksisterende svarutkast.', $requirement->answer_draft_text);
    }

    public function test_it_generates_a_full_coverage_wiki_answer_and_reports_page_citations(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'WIKI-ANS-004', 'Wiki answer full coverage');
        $document = $this->createAiDocument($savedNotice);
        $chunk = $this->createAiDocumentChunk($document, 'Beskriv Problem Management.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_text' => 'Beskriv Problem Management.',
        ]);

        $page = $this->createWikiPageWithVersion($context['customer'], 'Problem Management', 'Innhold om Problem Management og rotårsaksanalyse.');
        $this->createWikiClaim($page, 'Problem Management gjennomfører rotårsaksanalyse.');

        $this->mock(RequirementWikiResearchAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('selectNextAction')
            ->once()
            ->andReturn(['action' => 'read_pages', 'page_ids' => [$page->id], 'search_terms' => [], 'reason' => 'Direkte relevant.']));

        $this->mock(RequirementWikiAnswerAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('generateAnswer')
            ->once()
            ->andReturn([
                'answer_sections' => [['key' => 'S1', 'heading' => 'Problem Management', 'text' => 'Problem Management gjennomfører rotårsaksanalyse.', 'used_page_ids' => [$page->id]]],
            ]));

        $this->mock(RequirementWikiAlignmentAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('assessAlignment')
            ->once()
            ->andReturn([[
                'section_key' => 'S1',
                'alignment_status' => 'aligned',
                'supporting_page_ids' => [$page->id],
                'supported_points' => ['Rotårsaksanalyse er dokumentert.'],
                'uncovered_points' => [],
                'conflict_summary' => null,
                'review_note' => null,
            ]]));

        $response = $this->actingAs($context['user'])->postJson(
            "/app/ai/{$savedNotice->id}/requirements/{$requirement->id}/wiki-answer",
        );

        $response->assertOk();
        $response->assertJsonPath('wiki_answer.coverage_status', 'full');
        $response->assertJsonPath('wiki_answer.text', 'Problem Management gjennomfører rotårsaksanalyse.');
        $response->assertJsonPath('wiki_answer.sources.0.enterprise_wiki_page_id', $page->id);
        $response->assertJsonPath('wiki_answer.sections.0.page_titles.0', 'Problem Management');
        $response->assertJsonPath('wiki_answer.sections.0.alignment_status', 'aligned');
        $response->assertJsonPath('wiki_answer.main_pages.0.enterprise_wiki_page_id', $page->id);
        $response->assertJsonPath('wiki_answer.alignment_summary.aligned', 1);
        $response->assertJsonPath('wiki_answer.has_possible_conflict', false);

        $answer = SavedNoticeAiRequirementWikiAnswer::query()->where('saved_notice_ai_requirement_id', $requirement->id)->firstOrFail();
        $this->assertSame(RequirementWikiAnswerService::ENGINE_VERSION, $answer->engine_version);
    }

    public function test_a_page_discovered_via_a_wikilink_is_reported_as_discovered_not_a_main_page(): void
    {
        $context = $this->customerAdminContext();
        $savedNotice = $this->createSavedNotice($context['customer']->id, 'WIKI-ANS-007', 'Wiki answer navigation');
        $document = $this->createAiDocument($savedNotice);
        $chunk = $this->createAiDocumentChunk($document, 'Beskriv rutinen for problembehandling.');
        $requirement = $this->createAiRequirement($savedNotice, $document, $chunk, [
            'requirement_text' => 'Beskriv rutinen for problembehandling.',
        ]);

        // Only mainPage's title/content overlaps the requirement text — linkedPage must be
        // reachable ONLY via the wikilink, never as a round-1 direct-search hit.
        $mainPage = $this->createWikiPageWithVersion($context['customer'], 'Problembehandling', 'Innhold om problembehandling som lenker videre.');
        $linkedPage = $this->createWikiPageWithVersion($context['customer'], 'Kontinuerlig forbedring', 'Innhold uten det opprinnelige kravordet i det hele tatt.');
        $this->createWikilink($context['customer'], $mainPage, $linkedPage);

        $callCount = 0;
        $this->mock(RequirementWikiResearchAiClient::class, function (MockInterface $mock) use (&$callCount, $mainPage, $linkedPage): void {
            $mock->shouldReceive('selectNextAction')->twice()->andReturnUsing(
                function (string $identifier, string $text, array $candidates) use (&$callCount, $mainPage, $linkedPage): array {
                    $callCount++;

                    if ($callCount === 1) {
                        return ['action' => 'read_pages', 'page_ids' => [$mainPage->id], 'search_terms' => [], 'reason' => 'Direkte treff.'];
                    }

                    $candidateIds = array_column($candidates, 'page_id');

                    return in_array($linkedPage->id, $candidateIds, true)
                        ? ['action' => 'read_pages', 'page_ids' => [$linkedPage->id], 'search_terms' => [], 'reason' => 'Oppdaget via lenke.']
                        : ['action' => 'enough_context', 'page_ids' => [], 'search_terms' => [], 'reason' => 'Ferdig.'];
                },
            );
        });

        $this->mock(RequirementWikiAnswerAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('generateAnswer')
            ->once()
            ->andReturn([
                'answer_sections' => [['key' => 'S1', 'heading' => '', 'text' => 'Svar basert på begge sider.', 'used_page_ids' => [$mainPage->id, $linkedPage->id]]],
            ]));

        $this->mock(RequirementWikiAlignmentAiClient::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('assessAlignment')
            ->once()
            ->andReturn([[
                'section_key' => 'S1',
                'alignment_status' => 'aligned',
                'supporting_page_ids' => [$mainPage->id, $linkedPage->id],
                'supported_points' => [],
                'uncovered_points' => [],
                'conflict_summary' => null,
                'review_note' => null,
            ]]));

        $response = $this->actingAs($context['user'])->postJson(
            "/app/ai/{$savedNotice->id}/requirements/{$requirement->id}/wiki-answer",
        );

        $response->assertOk();
        $mainPages = $response->json('wiki_answer.main_pages');
        $discoveredPages = $response->json('wiki_answer.discovered_pages');

        $this->assertCount(1, $mainPages);
        $this->assertSame($mainPage->id, $mainPages[0]['enterprise_wiki_page_id']);
        $this->assertCount(1, $discoveredPages);
        $this->assertSame($linkedPage->id, $discoveredPages[0]['enterprise_wiki_page_id']);
        $this->assertSame('Problembehandling', $discoveredPages[0]['discovered_from_title']);
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
        $customer = $this->createWikiCustomer($customerName);
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
}
