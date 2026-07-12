<?php

namespace Tests\Feature\App\Wiki;

use App\Jobs\EnterpriseWiki\ContinueEnterpriseWikiDocumentFlowAfterPages;
use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiQaSnapshot;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Wiki\WikiClaimVerificationAiClient;
use App\Services\Ai\Wiki\WikiPageClaimExtractionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiAppliedRunLintService;
use App\Services\EnterpriseWiki\EnterpriseWikiBuildPageLinksService;
use App\Services\EnterpriseWiki\EnterpriseWikiDocumentFlowService;
use App\Services\EnterpriseWiki\EnterpriseWikiExtractPageClaimsService;
use App\Services\EnterpriseWiki\EnterpriseWikiPostIngestQaService;
use App\Services\EnterpriseWiki\EnterpriseWikiVerifyPageClaimsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Concurrency protocol for claim extraction/verification: a (run, page) or claim is reserved
 * via an atomic conditional UPDATE (claims_claimed_at/claims_claim_token,
 * verification_claimed_at/verification_claim_token) BEFORE any AI call, released or reclaimed
 * via a lease, and persisted only if the reservation token is still owned at write time.
 *
 * These tests exercise the actual interleaving the protocol defends against — not just two
 * sequential service calls — using direct DB state to simulate a second worker, and
 * reflection to invoke the private persist() step directly where a real second process can't
 * be forced into the exact race window from a single-threaded test.
 */
class EnterpriseWikiClaimStepLeaseTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // 1: Extraction reservation blocks a second worker
    // =========================================================================

    public function test_active_extraction_reservation_blocks_a_second_worker_from_calling_ai(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        [$row] = $this->addPage($run, 'Side A');

        // Worker A already holds a fresh (non-stale) reservation.
        $row->update([
            'claims_claimed_at' => now(),
            'claims_claim_token' => 'worker-a-token',
        ]);

        $this->mock(WikiPageClaimExtractionAiClient::class)->shouldNotReceive('extractClaims');

        $result = app(EnterpriseWikiExtractPageClaimsService::class)->extract($run->fresh());

        $this->assertSame(0, $result['pages']);
        $this->assertSame(1, $result['busy']);
        $this->assertSame('worker-a-token', $row->fresh()->claims_claim_token);
        $this->assertSame(0, EnterpriseWikiClaim::query()->count());
    }

    // =========================================================================
    // 2: Verification reservation blocks a second worker
    // =========================================================================

    public function test_active_verification_reservation_blocks_a_second_worker_from_calling_ai(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        [, , $version] = $this->addPage($run, 'Side B');
        $claim = $this->createClaim($version);

        $claim->update([
            'verification_claimed_at' => now(),
            'verification_claim_token' => 'worker-a-token',
        ]);

        $this->mock(WikiClaimVerificationAiClient::class)->shouldNotReceive('verifyClaim');

        $result = app(EnterpriseWikiVerifyPageClaimsService::class)->verify($run->fresh());

        $this->assertSame(0, $result['references']);
        $this->assertSame(1, $result['busy']);
        $this->assertSame('worker-a-token', $claim->fresh()->verification_claim_token);
        $this->assertSame(0, EnterpriseWikiSourceReference::query()->count());
    }

    // =========================================================================
    // 3: Stale extraction lease is reclaimed; old token is rejected
    // =========================================================================

    public function test_stale_extraction_lease_is_reclaimed_and_the_old_token_is_rejected(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        [$row, $page, $version] = $this->addPage($run, 'Side C');

        $staleToken = 'stale-worker-a-token';
        $row->update([
            'claims_claimed_at' => now()->subSeconds(700), // older than the 600s lease
            'claims_claim_token' => $staleToken,
        ]);

        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldReceive('extractClaims')
            ->once()
            ->andReturn(['claims' => [
                ['text' => 'Påstand fra B 1', 'confidence' => 'high'],
                ['text' => 'Påstand fra B 2', 'confidence' => 'medium'],
            ]]);

        $service = app(EnterpriseWikiExtractPageClaimsService::class);
        $result = $service->extract($run->fresh());

        $this->assertSame(1, $result['pages']);
        $this->assertSame(2, $result['claims']);
        $this->assertSame(0, $result['busy']);

        $fresh = $row->fresh();
        $this->assertNotNull($fresh->claims_extracted_at);
        $this->assertNull($fresh->claims_claim_token);
        $this->assertSame(2, EnterpriseWikiClaim::query()->where('enterprise_wiki_page_version_id', $version->id)->count());

        // Worker A, holding the now-superseded token, belatedly tries to persist its own
        // result — must be rejected, and must not create any claims.
        $persist = new ReflectionMethod(EnterpriseWikiExtractPageClaimsService::class, 'persist');
        $lateResult = $persist->invoke($service, $row->id, $page, $version, $staleToken, [
            'claims' => [['text' => 'Duplikat fra A', 'confidence' => 'high']],
        ]);

        $this->assertNull($lateResult);
        $this->assertSame(2, EnterpriseWikiClaim::query()->where('enterprise_wiki_page_version_id', $version->id)->count());
    }

    // =========================================================================
    // 4: Stale verification lease is reclaimed; old token is rejected
    // =========================================================================

    public function test_stale_verification_lease_is_reclaimed_and_the_old_token_is_rejected(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        [, , $version] = $this->addPage($run, 'Side D');
        $claim = $this->createClaim($version);

        $staleToken = 'stale-worker-a-token';
        $claim->update([
            'verification_claimed_at' => now()->subSeconds(700),
            'verification_claim_token' => $staleToken,
        ]);

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->once()
            ->andReturn(['supported' => true, 'excerpt' => 'Utdrag fra B.']);

        $service = app(EnterpriseWikiVerifyPageClaimsService::class);
        $result = $service->verify($run->fresh());

        $this->assertSame(1, $result['references']);
        $this->assertSame(0, $result['busy']);

        $fresh = $claim->fresh();
        $this->assertNotNull($fresh->verified_at);
        $this->assertNull($fresh->verification_claim_token);
        $this->assertSame(1, EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $claim->id)->count());

        $document = EnterpriseWikiDocument::query()->findOrFail($run->source_id);

        $persist = new ReflectionMethod(EnterpriseWikiVerifyPageClaimsService::class, 'persist');
        $lateOutcome = $persist->invoke($service, $claim->id, $staleToken, $document, [
            'supported' => true,
            'excerpt' => 'Duplikat utdrag fra A.',
        ]);

        $this->assertNull($lateOutcome);
        $this->assertSame(1, EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $claim->id)->count());
    }

    // =========================================================================
    // 5: A completed checkpoint can never be reserved, even with leftover reservation fields
    // =========================================================================

    public function test_completed_extraction_checkpoint_is_never_reserved_even_with_leftover_reservation_fields(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        [$row] = $this->addPage($run, 'Ferdig side');

        $row->update([
            'claims_extracted_at' => now(),
            'claims_claimed_at' => now()->subSeconds(9999),
            'claims_claim_token' => 'leftover-token',
        ]);

        $reserve = new ReflectionMethod(EnterpriseWikiExtractPageClaimsService::class, 'reserve');
        $outcome = $reserve->invoke(app(EnterpriseWikiExtractPageClaimsService::class), $row->fresh(), 'new-attempt-token');

        $this->assertSame('completed', $outcome);
        $this->assertSame('leftover-token', $row->fresh()->claims_claim_token);

        $this->mock(WikiPageClaimExtractionAiClient::class)->shouldNotReceive('extractClaims');

        $result = app(EnterpriseWikiExtractPageClaimsService::class)->extract($run->fresh());
        $this->assertSame(0, $result['pages']);
        $this->assertSame(0, $result['busy']);
    }

    public function test_completed_verification_checkpoint_is_never_reserved_even_with_leftover_reservation_fields(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createAppliedRun($customer);
        [, , $version] = $this->addPage($run, 'Side med verifisert påstand');
        $claim = $this->createClaim($version);

        $claim->update([
            'verified_at' => now(),
            'verification_claimed_at' => now()->subSeconds(9999),
            'verification_claim_token' => 'leftover-token',
        ]);

        $reserve = new ReflectionMethod(EnterpriseWikiVerifyPageClaimsService::class, 'reserve');
        $outcome = $reserve->invoke(app(EnterpriseWikiVerifyPageClaimsService::class), $claim->fresh(), 'new-attempt-token');

        $this->assertSame('completed', $outcome);
        $this->assertSame('leftover-token', $claim->fresh()->verification_claim_token);

        $this->mock(WikiClaimVerificationAiClient::class)->shouldNotReceive('verifyClaim');

        $result = app(EnterpriseWikiVerifyPageClaimsService::class)->verify($run->fresh());
        $this->assertSame(0, $result['references']);
        $this->assertSame(0, $result['no_support']);
        $this->assertSame(0, $result['busy']);
    }

    // =========================================================================
    // 6: No duplicates under the new lease layer, across two full continuation passes
    // =========================================================================

    public function test_two_full_continuation_passes_invoke_ai_exactly_once_per_step_with_no_duplicates(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunAwaitingContinuation($customer);

        $this->configureLinkAndLintMocks();

        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldReceive('extractClaims')
            ->once()
            ->andReturn(['claims' => [['text' => 'Én påstand.', 'confidence' => 'high']]]);

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->once()
            ->andReturn(['supported' => true, 'excerpt' => 'Utdrag.']);

        $this->mockQaClaims($run, EnterpriseWikiIngestRun::QA_STATUS_PASSED);

        $flowService = app(EnterpriseWikiDocumentFlowService::class);
        $flowService->continueAfterPagesGenerated($run->id);

        $this->assertSame(EnterpriseWikiIngestRun::STATUS_COMPLETED, $run->fresh()->status);

        $claimsAfterFirst = EnterpriseWikiClaim::query()->count();
        $referencesAfterFirst = EnterpriseWikiSourceReference::query()->count();
        $snapshotsAfterFirst = EnterpriseWikiQaSnapshot::query()->count();
        $attemptCountAfterFirst = $run->fresh()->qa_attempt_count;

        $this->assertSame(1, $claimsAfterFirst);
        $this->assertSame(1, $referencesAfterFirst);

        // Second pass: run is already terminal, so continuation must no-op entirely — but
        // even if it weren't, the AI mocks above are still bound to exactly one call each for
        // the whole test, so a second AI invocation would fail the test.
        $flowService->continueAfterPagesGenerated($run->id);

        $this->assertSame($claimsAfterFirst, EnterpriseWikiClaim::query()->count());
        $this->assertSame($referencesAfterFirst, EnterpriseWikiSourceReference::query()->count());
        $this->assertSame($snapshotsAfterFirst, EnterpriseWikiQaSnapshot::query()->count());
        $this->assertSame($attemptCountAfterFirst, $run->fresh()->qa_attempt_count);
    }

    // =========================================================================
    // 7: Continuation encountering an active reservation does not fail the run or call AI
    // =========================================================================

    public function test_continuation_meeting_an_active_extraction_reservation_defers_without_failing_or_calling_ai(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $run = $this->createRunAwaitingContinuation($customer);

        $pivotRow = EnterpriseWikiIngestRunPage::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->firstOrFail();

        // Simulate another live worker actively (non-stale) extracting this page right now.
        $pivotRow->update([
            'claims_claimed_at' => now(),
            'claims_claim_token' => 'other-live-worker',
        ]);

        // Only materialize wikilinks runs before extraction is reached — lint/QA must never
        // be invoked at all, since the flow must defer before getting there.
        $this->mock(EnterpriseWikiBuildPageLinksService::class)
            ->shouldReceive('materializeWikilinksForRun')
            ->once()
            ->andReturn([
                'pages_processed' => 1, 'occurrences_found' => 0, 'valid_links' => 0,
                'broken_slugs' => 0, 'self_links' => 0, 'created' => 0, 'updated' => 0,
                'stale_links_removed' => 0,
            ]);
        $this->mock(EnterpriseWikiAppliedRunLintService::class)->shouldNotReceive('lint');
        $this->mock(WikiPageClaimExtractionAiClient::class)->shouldNotReceive('extractClaims');
        $this->mock(WikiClaimVerificationAiClient::class)->shouldNotReceive('verifyClaim');
        $this->mock(EnterpriseWikiPostIngestQaService::class)->shouldNotReceive('runForRun');

        app(EnterpriseWikiDocumentFlowService::class)->continueAfterPagesGenerated($run->id);

        $fresh = $run->fresh();
        $this->assertNotSame(EnterpriseWikiIngestRun::STATUS_FAILED, $fresh->status);
        $this->assertNull($fresh->error_message);
        $this->assertNull($fresh->finished_at);

        Queue::assertPushed(ContinueEnterpriseWikiDocumentFlowAfterPages::class, fn ($j) => $j->runId === $run->id);

        // The reservation made by "the other worker" is untouched.
        $this->assertSame('other-live-worker', $pivotRow->fresh()->claims_claim_token);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createCustomer(): Customer
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
            'name' => 'Test AS',
            'slug' => 'test-as-'.Str::lower(Str::random(6)),
            'language_id' => $language->id,
            'nationality_id' => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active' => true,
        ]);
    }

    private function createDocument(Customer $customer): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Source text for claim step lease tests.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createAppliedRun(Customer $customer): EnterpriseWikiIngestRun
    {
        $document = $this->createDocument($customer);

        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
        ]);
    }

    /**
     * A run with one article page (current version present) attached, positioned exactly
     * where FinalizeEnterpriseWikiPageGeneration would hand off to
     * ContinueEnterpriseWikiDocumentFlowAfterPages.
     */
    private function createRunAwaitingContinuation(Customer $customer): EnterpriseWikiIngestRun
    {
        $run = $this->createAppliedRun($customer);
        $this->addPage($run, 'Artikkel');

        return $run;
    }

    /**
     * @return array{0: EnterpriseWikiIngestRunPage, 1: EnterpriseWikiPage, 2: EnterpriseWikiPageVersion}
     */
    private function addPage(EnterpriseWikiIngestRun $run, string $title): array
    {
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $run->customer_id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'title' => $title,
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        $row = EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# {$title}\n\nInnhold.",
        ]);

        return [$row, $page, $version];
    }

    private function createClaim(EnterpriseWikiPageVersion $version): EnterpriseWikiClaim
    {
        return EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $version->enterprise_wiki_page_id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'En påstand.',
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);
    }

    /**
     * Mocks the two fully-deterministic, non-AI-gated stages so a full continuation pass can
     * exercise the real extraction/verification services (and their lease protocol) without
     * depending on wikilink content — mirrors the pattern used for QA-focused continuation
     * tests elsewhere, but deliberately leaves extraction/verification unmocked.
     */
    private function configureLinkAndLintMocks(): void
    {
        $this->mock(EnterpriseWikiBuildPageLinksService::class)
            ->shouldReceive('materializeWikilinksForRun')
            ->once()
            ->andReturn([
                'pages_processed' => 1, 'occurrences_found' => 0, 'valid_links' => 0,
                'broken_slugs' => 0, 'self_links' => 0, 'created' => 0, 'updated' => 0,
                'stale_links_removed' => 0,
            ]);

        $this->mock(EnterpriseWikiAppliedRunLintService::class)
            ->shouldReceive('lint')
            ->once()
            ->andReturn([
                'pages_checked' => 1, 'claims_checked' => 0, 'source_refs_checked' => 0,
                'links_checked' => 0, 'findings_created' => 0, 'findings_skipped' => 0,
                'findings_resolved' => 0, 'errors' => 0, 'warnings' => 0, 'info' => 0,
            ]);
    }

    private function mockQaClaims(EnterpriseWikiIngestRun $run, string $qaStatus): void
    {
        $this->mock(EnterpriseWikiPostIngestQaService::class)
            ->shouldReceive('runForRun')
            ->once()
            ->andReturnUsing(function (EnterpriseWikiIngestRun $r) use ($run, $qaStatus) {
                $run->update([
                    'qa_status' => $qaStatus,
                    'qa_completed_at' => now(),
                    'qa_attempt_count' => 1,
                ]);

                return ['pass' => true];
            });
    }
}
