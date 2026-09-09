<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Wiki\WikiClaimVerificationAiClient;
use App\Services\Ai\Wiki\WikiPageClaimExtractionAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiAppliedRunLintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * wiki:repair-page-version-claims — re-extracts/re-verifies claims for a page whose current
 * version has zero claims because some other repair path (link-semantic repair, incremental
 * relink, article/summary link repair) replaced the version without re-syncing claims — the
 * exact run-39 CODE_PAGE_WITHOUT_CLAIMS drift.
 */
class EnterpriseWikiRepairPageVersionClaimsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_CLAIMS = [
        ['text' => 'Test claim alpha', 'confidence' => 'high', 'excerpt' => 'Supporting excerpt alpha.', 'conflict_note' => null],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // No real OpenAI calls in any test.
        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldReceive('extractClaims')
            ->andReturn(['claims' => self::FAKE_CLAIMS])
            ->byDefault();

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->andReturn($this->verificationResult())
            ->byDefault();
    }

    // =========================================================================
    // Argument validation
    // =========================================================================

    public function test_command_fails_when_run_id_is_not_numeric(): void
    {
        $this->artisan('wiki:repair-page-version-claims', ['--run-id' => 'abc'])
            ->expectsOutputToContain('must be numeric')
            ->assertExitCode(1);
    }

    public function test_command_fails_when_run_not_found(): void
    {
        $this->artisan('wiki:repair-page-version-claims', ['--run-id' => 99999])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    }

    // =========================================================================
    // Core repair behavior
    // =========================================================================

    public function test_new_page_version_gets_claims_extracted_after_repair(): void
    {
        $customer = $this->createCustomer();
        [$run, $page, $version1] = $this->createSupersededPage($customer);

        $this->artisan('wiki:repair-page-version-claims', ['--run-id' => $run->id, '--apply' => true])
            ->assertExitCode(0);

        $currentVersion = $page->fresh()->currentVersion;
        $this->assertNotSame($version1->id, $currentVersion->id);
        $this->assertTrue(
            EnterpriseWikiClaim::query()->where('enterprise_wiki_page_version_id', $currentVersion->id)->exists(),
        );
    }

    public function test_older_claims_are_not_counted_as_claims_for_current_version(): void
    {
        $customer = $this->createCustomer();
        [$run, , $version1, $version2] = $this->createSupersededPage($customer);

        // Before repair: dry run must recognize v2 (current) as missing claims, even though v1
        // (superseded) already has one.
        $this->artisan('wiki:repair-page-version-claims', ['--run-id' => $run->id])
            ->expectsOutputToContain('Pages missing claims (would resync): 1')
            ->assertExitCode(0);

        $this->artisan('wiki:repair-page-version-claims', ['--run-id' => $run->id, '--apply' => true]);

        // The old version's claim is untouched historical record — never moved or deleted.
        $this->assertSame(
            1,
            EnterpriseWikiClaim::query()->where('enterprise_wiki_page_version_id', $version1->id)->count(),
        );
        $this->assertTrue(
            EnterpriseWikiClaim::query()->where('enterprise_wiki_page_version_id', $version2->id)->exists(),
        );
    }

    public function test_repair_does_not_duplicate_claims(): void
    {
        $customer = $this->createCustomer();
        [$run, $page] = $this->createSupersededPage($customer);

        $this->artisan('wiki:repair-page-version-claims', ['--run-id' => $run->id, '--apply' => true]);
        $countAfterFirst = EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_version_id', $page->fresh()->currentVersion->id)
            ->count();

        $this->artisan('wiki:repair-page-version-claims', ['--run-id' => $run->id, '--apply' => true]);
        $countAfterSecond = EnterpriseWikiClaim::query()
            ->where('enterprise_wiki_page_version_id', $page->fresh()->currentVersion->id)
            ->count();

        $this->assertSame($countAfterFirst, $countAfterSecond);
        $this->assertGreaterThan(0, $countAfterFirst);
    }

    public function test_repair_is_idempotent(): void
    {
        $customer = $this->createCustomer();
        [$run] = $this->createSupersededPage($customer);

        $this->artisan('wiki:repair-page-version-claims', ['--run-id' => $run->id, '--apply' => true]);

        $this->artisan('wiki:repair-page-version-claims', ['--run-id' => $run->id, '--apply' => true])
            ->expectsOutputToContain('Pages missing claims (resynced): 0')
            ->assertExitCode(0);
    }

    public function test_dry_run_does_not_persist_any_change(): void
    {
        $customer = $this->createCustomer();
        [$run, $page] = $this->createSupersededPage($customer);

        $this->artisan('wiki:repair-page-version-claims', ['--run-id' => $run->id]);

        $currentVersion = $page->fresh()->currentVersion;
        $this->assertFalse(
            EnterpriseWikiClaim::query()->where('enterprise_wiki_page_version_id', $currentVersion->id)->exists(),
        );
    }

    public function test_page_without_extractable_facts_is_left_as_correctly_empty(): void
    {
        $this->mock(WikiPageClaimExtractionAiClient::class)
            ->shouldReceive('extractClaims')
            ->andReturn(['claims' => []])
            ->byDefault();

        $customer = $this->createCustomer();
        [$run, $page] = $this->createSupersededPage($customer);

        $this->artisan('wiki:repair-page-version-claims', ['--run-id' => $run->id, '--apply' => true])
            ->expectsOutputToContain('Pages missing claims (resynced): 1');

        // Genuinely zero facts — must not be repeatedly re-attempted on the next run.
        $this->assertFalse(
            EnterpriseWikiClaim::query()->where('enterprise_wiki_page_version_id', $page->fresh()->currentVersion->id)->exists(),
        );

        $this->artisan('wiki:repair-page-version-claims', ['--run-id' => $run->id])
            ->expectsOutputToContain('Pages missing claims (would resync): 0')
            ->assertExitCode(0);
    }

    public function test_qa_finding_is_resolved_after_repair(): void
    {
        $customer = $this->createCustomer();
        // CODE_PAGE_WITHOUT_CLAIMS is raised only when the current version has at least one
        // best_practice block: a page built purely from source_based/structural content correctly
        // has zero claims and must not be reported as a defect.
        [$run, $page, , $version2] = $this->createSupersededPage(
            $customer,
            EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
        );

        app(EnterpriseWikiAppliedRunLintService::class)->lint($run->fresh());

        $finding = EnterpriseWikiLintFinding::query()
            ->where('enterprise_wiki_ingest_run_id', $run->id)
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('code', EnterpriseWikiLintFinding::CODE_PAGE_WITHOUT_CLAIMS)
            ->first();

        $this->assertNotNull($finding);
        $this->assertSame(EnterpriseWikiLintFinding::STATUS_OPEN, $finding->status);

        $this->artisan('wiki:repair-page-version-claims', ['--run-id' => $run->id, '--apply' => true]);

        $this->assertSame(EnterpriseWikiLintFinding::STATUS_RESOLVED, $finding->fresh()->status);
    }

    public function test_other_customers_are_not_affected(): void
    {
        $customerA = $this->createCustomer('Customer A');
        $customerB = $this->createCustomer('Customer B');

        [$runA] = $this->createSupersededPage($customerA);
        [$runB, $pageB] = $this->createSupersededPage($customerB);

        $this->artisan('wiki:repair-page-version-claims', ['--run-id' => $runA->id, '--apply' => true]);

        $this->assertFalse(
            EnterpriseWikiClaim::query()->where('enterprise_wiki_page_version_id', $pageB->fresh()->currentVersion->id)->exists(),
        );
    }

    public function test_sweeping_all_runs_still_respects_customer_scoping(): void
    {
        $customerA = $this->createCustomer('Sweep A');
        $customerB = $this->createCustomer('Sweep B');

        [, $pageA] = $this->createSupersededPage($customerA);
        [, $pageB] = $this->createSupersededPage($customerB);

        $this->artisan('wiki:repair-page-version-claims', ['--apply' => true]);

        $this->assertTrue(
            EnterpriseWikiClaim::query()->where('enterprise_wiki_page_version_id', $pageA->fresh()->currentVersion->id)->exists(),
        );
        $this->assertTrue(
            EnterpriseWikiClaim::query()->where('enterprise_wiki_page_version_id', $pageB->fresh()->currentVersion->id)->exists(),
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function verificationResult(): array
    {
        return [
            'verdict' => 'supported',
            'same_meaning_across_languages' => true,
            'claim_language' => 'no',
            'source_language' => 'no',
            'supporting_source_element_keys' => [],
            'reason' => 'Claim matches the cited source excerpt.',
            'unsupported_parts' => '',
            'checks' => [
                'actor' => 'match',
                'action' => 'match',
                'object' => 'match',
                'modality' => 'match',
                'negation' => 'match',
                'numbers_and_units' => 'match',
                'time_and_date' => 'match',
                'scope' => 'match',
                'conditions_and_exceptions' => 'not_applicable',
                'subject_entity' => 'match',
            ],
        ];
    }

    private function createCustomer(string $name = 'Test AS'): Customer
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
            'extracted_text' => 'Source text for testing. Supporting excerpt alpha.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createRunApplied(Customer $customer, EnterpriseWikiDocument $document): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            // Must be a status that expects automatic progress. Since "Stop Wiki claim resync at
            // owner approval" (66cd251) the repair service skips runs where
            // isAwaitingHumanAction() is true — decision_only and awaiting_document_owner_approval
            // — because a run parked on a human decision has no technical job behind it to resync
            // for. verification_linking is the stage this repair actually targets, and is what the
            // maintained sibling EnterpriseWikiPageVersionClaimRepairServiceTest drives too.
            'status' => EnterpriseWikiIngestRun::STATUS_VERIFICATION_LINKING,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
        ]);
    }

    /**
     * A page whose CURRENT version (v2) has no claims because some earlier repair path
     * replaced v1 (which does have one claim) without re-syncing claims for v2 — the exact
     * run-39 drift this repair targets. v2's checkpoint (claims_extracted_at) is left null, as
     * the buggy repair paths this fix generalizes over used to leave it.
     *
     * @return array{0: EnterpriseWikiIngestRun, 1: EnterpriseWikiPage, 2: EnterpriseWikiPageVersion, 3: EnterpriseWikiPageVersion}
     */
    private function createSupersededPage(
        Customer $customer,
        string $blockOrigin = EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
    ): array {
        $document = $this->createDocument($customer);
        $run = $this->createRunApplied($customer, $document);
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'test-page-'.Str::lower(Str::random(8)),
            'title' => 'Test Page',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
            'status' => EnterpriseWikiPage::STATUS_APPROVED,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        $pivot = EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);

        $version1 = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => false,
            'content_markdown' => "# Test Page\n\nOriginal content. Supporting excerpt alpha.",
            'generated_by_model' => 'gpt-5',
        ]);

        EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version1->id,
            'claim_text' => 'Original claim on the superseded version.',
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);

        // Since "Implement source-based Wiki claim extraction" (ba41a58) extraction is driven by
        // content_blocks_json, not by content_markdown: claimCandidateBlocks() only offers blocks
        // whose own content_origin is best_practice or unsupported_generated_content to the AI, and
        // persist() rejects a source_based anchor a second time after the response. A version with
        // no blocks therefore has no claim candidates at all, so the resync this command triggers
        // could never produce a claim. The block below carries the excerpt the mocked
        // extractClaims() returns, so findUniqueBlockForExcerpt() anchors the claim to it.
        // Default origin is unsupported_generated_content: the fake claim text is a plain
        // assertion, not a recommendation. Tests that need lint's CODE_PAGE_WITHOUT_CLAIMS ask for
        // a best_practice block instead — that finding is raised only for best_practice blocks,
        // and persist() also creates a deterministic fallback claim for one, which is exactly what
        // test_page_without_extractable_facts_is_left_as_correctly_empty must NOT get.
        $version2 = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 2,
            'is_current' => true,
            'content_markdown' => "# Test Page\n\nRevised content. Supporting excerpt alpha.",
            'content_blocks_json' => [[
                'block_key' => 'block-0001',
                'position' => 0,
                'markdown' => 'Revised content. Supporting excerpt alpha.',
                'content_origin' => $blockOrigin,
                'source_elements' => [],
                'best_practice_reason' => $blockOrigin === EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE
                    ? 'General recommendation not tied to a specific source element.'
                    : null,
            ]],
            'generated_by_model' => 'deterministic/link-semantic-repair',
        ]);

        // Mirrors the pre-fix bug: the pivot's checkpoint from v1's original extraction was
        // never cleared when v2 became current, and generated_page_version_id still points at v1.
        $pivot->update([
            'claims_extracted_at' => now()->subDay(),
            'generated_page_version_id' => $version1->id,
        ]);

        return [$run, $page, $version1, $version2];
    }
}
