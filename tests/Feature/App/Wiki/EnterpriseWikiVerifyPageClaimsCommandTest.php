<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Wiki\WikiClaimVerificationAiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnterpriseWikiVerifyPageClaimsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_EXCERPT = 'This is the exact text from the source that supports the claim.';

    protected function setUp(): void
    {
        parent::setUp();

        // No real OpenAI calls in any test.
        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->andReturn(['supported' => true, 'excerpt' => self::FAKE_EXCERPT])
            ->byDefault();
    }

    // =========================================================================
    // Argument validation
    // =========================================================================

    public function test_command_fails_when_run_id_is_missing(): void
    {
        $this->artisan('wiki:verify-page-claims')
            ->expectsOutputToContain('--run-id is required')
            ->assertExitCode(1);
    }

    public function test_command_fails_when_run_not_found(): void
    {
        $this->artisan('wiki:verify-page-claims', ['--run-id' => 99999])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    }

    // =========================================================================
    // Guard: run not applied
    // =========================================================================

    public function test_command_fails_when_run_not_applied(): void
    {
        $customer = $this->createCustomer();
        $run      = $this->createRunPending($customer);

        $this->artisan('wiki:verify-page-claims', ['--run-id' => $run->id])
            ->expectsOutputToContain("only 'applied'")
            ->assertExitCode(1);
    }

    // =========================================================================
    // Successful verification
    // =========================================================================

    public function test_command_exits_zero_on_success(): void
    {
        $customer = $this->createCustomer();
        [$run]    = $this->createAppliedRunWithClaimedPage($customer);

        $this->artisan('wiki:verify-page-claims', ['--run-id' => $run->id])
            ->assertExitCode(0);
    }

    public function test_command_creates_source_reference_for_supported_claim(): void
    {
        $customer         = $this->createCustomer();
        [$run, , , $claim] = $this->createAppliedRunWithClaimedPage($customer);

        Artisan::call('wiki:verify-page-claims', ['--run-id' => $run->id]);

        $this->assertTrue(
            EnterpriseWikiSourceReference::query()
                ->where('enterprise_wiki_claim_id', $claim->id)
                ->exists()
        );
    }

    // =========================================================================
    // Source reference field values
    // =========================================================================

    public function test_source_reference_has_correct_claim_id(): void
    {
        $customer         = $this->createCustomer();
        [$run, , , $claim] = $this->createAppliedRunWithClaimedPage($customer);

        Artisan::call('wiki:verify-page-claims', ['--run-id' => $run->id]);

        $ref = EnterpriseWikiSourceReference::query()
            ->where('enterprise_wiki_claim_id', $claim->id)
            ->first();

        $this->assertSame($claim->id, $ref->enterprise_wiki_claim_id);
    }

    public function test_source_reference_has_correct_source_type(): void
    {
        $customer         = $this->createCustomer();
        [$run, , , $claim] = $this->createAppliedRunWithClaimedPage($customer);

        Artisan::call('wiki:verify-page-claims', ['--run-id' => $run->id]);

        $ref = EnterpriseWikiSourceReference::query()
            ->where('enterprise_wiki_claim_id', $claim->id)
            ->first();

        $this->assertSame(EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT, $ref->source_type);
    }

    public function test_source_reference_has_correct_source_id(): void
    {
        $customer                   = $this->createCustomer();
        [$run, , , $claim, $document] = $this->createAppliedRunWithClaimedPage($customer);

        Artisan::call('wiki:verify-page-claims', ['--run-id' => $run->id]);

        $ref = EnterpriseWikiSourceReference::query()
            ->where('enterprise_wiki_claim_id', $claim->id)
            ->first();

        $this->assertSame($document->id, $ref->source_id);
    }

    public function test_source_reference_has_correct_source_label(): void
    {
        $customer                   = $this->createCustomer();
        [$run, , , $claim, $document] = $this->createAppliedRunWithClaimedPage($customer);

        Artisan::call('wiki:verify-page-claims', ['--run-id' => $run->id]);

        $ref = EnterpriseWikiSourceReference::query()
            ->where('enterprise_wiki_claim_id', $claim->id)
            ->first();

        $this->assertSame($document->original_filename, $ref->source_label);
    }

    public function test_source_reference_has_correct_excerpt(): void
    {
        $customer         = $this->createCustomer();
        [$run, , , $claim] = $this->createAppliedRunWithClaimedPage($customer);

        Artisan::call('wiki:verify-page-claims', ['--run-id' => $run->id]);

        $ref = EnterpriseWikiSourceReference::query()
            ->where('enterprise_wiki_claim_id', $claim->id)
            ->first();

        $this->assertSame(self::FAKE_EXCERPT, $ref->excerpt);
    }

    public function test_supported_claim_is_marked_source_based(): void
    {
        $customer = $this->createCustomer();
        [$run, , , $claim] = $this->createAppliedRunWithClaimedPage($customer);

        Artisan::call('wiki:verify-page-claims', ['--run-id' => $run->id]);

        $claim->refresh();

        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, $claim->content_origin);
        $this->assertNull($claim->review_reason);
        $this->assertNull($claim->generation_issue);
    }

    // =========================================================================
    // Idempotency: claims with existing references are verified once and not duplicated
    // =========================================================================

    public function test_command_verifies_claim_with_existing_source_reference_without_creating_duplicate_reference(): void
    {
        $customer                   = $this->createCustomer();
        [$run, , , $claim, $document] = $this->createAppliedRunWithClaimedPage($customer);
        $verified = false;

        // Pre-create a reference for this claim
        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type'              => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id'                => $document->id,
            'source_label'             => $document->original_filename,
            'excerpt'                  => 'Pre-existing excerpt.',
            'source_hash'              => $document->file_hash_sha256,
        ]);

        $refsBefore = EnterpriseWikiSourceReference::query()->count();

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->once()
            ->andReturnUsing(function () use (&$verified): array {
                $verified = true;

                return ['supported' => true, 'excerpt' => self::FAKE_EXCERPT];
            });

        Artisan::call('wiki:verify-page-claims', ['--run-id' => $run->id]);

        $this->assertTrue($verified);
        $this->assertSame($refsBefore, EnterpriseWikiSourceReference::query()->count());
        $this->assertNotNull($claim->fresh()->verified_at);
    }

    public function test_command_does_not_report_existing_unverified_references_as_skipped(): void
    {
        $customer                   = $this->createCustomer();
        [$run, , , $claim, $document] = $this->createAppliedRunWithClaimedPage($customer);

        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type'              => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id'                => $document->id,
            'source_label'             => $document->original_filename,
            'excerpt'                  => 'Pre-existing excerpt.',
            'source_hash'              => $document->file_hash_sha256,
        ]);

        Artisan::call('wiki:verify-page-claims', ['--run-id' => $run->id]);

        $this->assertStringContainsString('Claims checked:      1', Artisan::output());
        $this->assertNotNull($claim->fresh()->verified_at);
    }

    public function test_existing_source_reference_does_not_prevent_unsupported_verdict(): void
    {
        $customer = $this->createCustomer();
        [$run, , , $claim, $document] = $this->createAppliedRunWithClaimedPage($customer);

        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_label' => $document->original_filename,
            'excerpt' => 'Pre-existing but insufficient excerpt.',
            'source_hash' => $document->file_hash_sha256,
        ]);

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->once()
            ->andReturn(['supported' => false, 'excerpt' => '']);

        Artisan::call('wiki:verify-page-claims', ['--run-id' => $run->id]);

        $claim->refresh();

        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, $claim->content_origin);
        $this->assertSame('unsupported_generated_content', $claim->generation_issue);
        $this->assertNotNull($claim->verified_at);
    }

    // =========================================================================
    // No support: AI finds no supporting excerpt
    // =========================================================================

    public function test_command_does_not_create_reference_when_not_supported(): void
    {
        $customer         = $this->createCustomer();
        [$run, , , $claim] = $this->createAppliedRunWithClaimedPage($customer);

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->once()
            ->andReturn(['supported' => false, 'excerpt' => '']);

        $refsBefore = EnterpriseWikiSourceReference::query()->count();

        Artisan::call('wiki:verify-page-claims', ['--run-id' => $run->id]);

        $this->assertSame($refsBefore, EnterpriseWikiSourceReference::query()->count());
    }

    public function test_unsupported_factual_claim_with_page_anchor_becomes_unsupported_generated_content(): void
    {
        $customer = $this->createCustomer();
        [$run, , , $claim] = $this->createAppliedRunWithClaimedPage($customer);

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->once()
            ->andReturn(['supported' => false, 'excerpt' => '']);

        Artisan::call('wiki:verify-page-claims', ['--run-id' => $run->id]);

        $claim->refresh();

        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, $claim->content_origin);
        $this->assertSame(EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN, $claim->confidence);
        $this->assertSame(EnterpriseWikiClaim::SOURCE_STATUS_UNSUPPORTED_GENERATED_CONTENT, $claim->sourceStatus());
        $this->assertFalse($claim->needsSourceWarning());
        $this->assertSame('unsupported_generated_content', $claim->generation_issue);
        $this->assertNull($claim->review_reason);
    }

    public function test_explicit_best_practice_block_remains_best_practice_review_when_source_verification_finds_no_support(): void
    {
        $customer = $this->createCustomer();
        [$run, $page, $version, $claim] = $this->createAppliedRunWithClaimedPage($customer);
        $text = 'Virksomheten bør gjennomføre årlig tilgangsgjennomgang.';

        $version->update(['content_markdown' => "# {$page->title}\n\n{$text}"]);
        $claim->update([
            'claim_text' => $text,
            'page_excerpt' => $text,
            'content_block_key' => 'block-0001',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'review_metadata' => [
                'statement_kind' => 'recommendation',
                'classification_basis' => 'ai_block_content_origin',
                'suggested_placement' => 'block-0001',
                'visible_wiki_link_recommendation' => 'not_needed',
            ],
        ]);

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->once()
            ->andReturn(['supported' => false, 'excerpt' => '']);

        Artisan::call('wiki:verify-page-claims', ['--run-id' => $run->id]);

        $claim->refresh();

        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, $claim->content_origin);
        $this->assertSame(EnterpriseWikiClaim::SOURCE_STATUS_BEST_PRACTICE_REVIEW, $claim->sourceStatus());
        $this->assertSame('recommendation', $claim->review_metadata['statement_kind'] ?? null);
        $this->assertNotNull($claim->review_reason);
    }

    public function test_claim_without_current_page_anchor_is_internal_error_and_skips_ai(): void
    {
        $customer = $this->createCustomer();
        [$run, , , $claim] = $this->createAppliedRunWithClaimedPage($customer);

        $claim->update([
            'page_excerpt' => 'This text is not present in the page.',
        ]);

        $this->mock(WikiClaimVerificationAiClient::class)->shouldNotReceive('verifyClaim');

        Artisan::call('wiki:verify-page-claims', ['--run-id' => $run->id]);

        $claim->refresh();

        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR, $claim->content_origin);
        $this->assertSame(EnterpriseWikiClaim::SOURCE_STATUS_INTERNAL_ERROR, $claim->sourceStatus());
        $this->assertSame('claim_anchor_not_found_in_current_page_version', $claim->generation_issue);
        $this->assertFalse($claim->needsSourceWarning());
    }

    public function test_command_outputs_no_support_count(): void
    {
        $customer = $this->createCustomer();
        [$run]    = $this->createAppliedRunWithClaimedPage($customer);

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->once()
            ->andReturn(['supported' => false, 'excerpt' => '']);

        Artisan::call('wiki:verify-page-claims', ['--run-id' => $run->id]);

        $this->assertStringContainsString('No support found:    1', Artisan::output());
    }

    // =========================================================================
    // Edge cases: pages without versions or claims
    // =========================================================================

    public function test_command_skips_page_without_current_version(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run      = $this->createRunApplied($customer, $document);
        $page     = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'No Version');

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id'       => $page->id,
            'action'                        => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);

        $refsBefore = EnterpriseWikiSourceReference::query()->count();

        Artisan::call('wiki:verify-page-claims', ['--run-id' => $run->id]);

        $this->assertSame($refsBefore, EnterpriseWikiSourceReference::query()->count());
    }

    public function test_command_skips_page_without_claims(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $run      = $this->createRunApplied($customer, $document);
        $page     = $this->createPage($customer, EnterpriseWikiPage::PAGE_TYPE_ARTICLE, 'No Claims');

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id'       => $page->id,
            'action'                        => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number'          => 1,
            'is_current'              => true,
            'content_markdown'        => '# No Claims',
            'generated_by_model'      => 'gpt-5',
        ]);

        $refsBefore = EnterpriseWikiSourceReference::query()->count();

        Artisan::call('wiki:verify-page-claims', ['--run-id' => $run->id]);

        $this->assertSame($refsBefore, EnterpriseWikiSourceReference::query()->count());
    }

    // =========================================================================
    // CLI output
    // =========================================================================

    public function test_command_outputs_pages_checked_count(): void
    {
        $customer = $this->createCustomer();
        [$run]    = $this->createAppliedRunWithClaimedPage($customer);

        Artisan::call('wiki:verify-page-claims', ['--run-id' => $run->id]);

        $this->assertStringContainsString('Pages checked:       1', Artisan::output());
    }

    public function test_command_outputs_claims_checked_count(): void
    {
        $customer = $this->createCustomer();
        [$run]    = $this->createAppliedRunWithClaimedPage($customer);

        Artisan::call('wiki:verify-page-claims', ['--run-id' => $run->id]);

        $this->assertStringContainsString('Claims checked:      1', Artisan::output());
    }

    public function test_command_outputs_references_created_count(): void
    {
        $customer = $this->createCustomer();
        [$run]    = $this->createAppliedRunWithClaimedPage($customer);

        Artisan::call('wiki:verify-page-claims', ['--run-id' => $run->id]);

        $this->assertStringContainsString('References created:  1', Artisan::output());
    }

    public function test_command_outputs_skipped_count(): void
    {
        $customer = $this->createCustomer();
        [$run, , , $claim] = $this->createAppliedRunWithClaimedPage($customer);
        $claim->update(['verified_at' => now()]);

        Artisan::call('wiki:verify-page-claims', ['--run-id' => $run->id]);

        $this->assertStringContainsString('Skipped:             1', Artisan::output());
    }

    // =========================================================================
    // No side effects
    // =========================================================================

    public function test_command_does_not_modify_run_status(): void
    {
        $customer = $this->createCustomer();
        [$run]    = $this->createAppliedRunWithClaimedPage($customer);

        Artisan::call('wiki:verify-page-claims', ['--run-id' => $run->id]);

        $this->assertSame(
            EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            $run->fresh()->maintainer_decision_status,
        );
    }

    public function test_command_does_not_modify_existing_page_versions(): void
    {
        $customer          = $this->createCustomer();
        [$run, , $version] = $this->createAppliedRunWithClaimedPage($customer);
        $originalMarkdown  = $version->content_markdown;

        Artisan::call('wiki:verify-page-claims', ['--run-id' => $run->id]);

        $this->assertSame($originalMarkdown, $version->fresh()->content_markdown);
    }

    public function test_command_does_not_create_additional_claims(): void
    {
        $customer    = $this->createCustomer();
        [$run]       = $this->createAppliedRunWithClaimedPage($customer);
        $claimsBefore = EnterpriseWikiClaim::query()->count();

        Artisan::call('wiki:verify-page-claims', ['--run-id' => $run->id]);

        $this->assertSame($claimsBefore, EnterpriseWikiClaim::query()->count());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

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
            'name'             => $name,
            'slug'             => Str::slug($name) . '-' . Str::lower(Str::random(6)),
            'language_id'      => $language->id,
            'nationality_id'   => $nationality->id,
            'billing_interval' => Customer::BILLING_MONTHLY,
            'is_active'        => true,
        ]);
    }

    private function createDocument(Customer $customer): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id'       => $customer->id,
            'original_filename' => 'source-doc.pdf',
            'file_path'         => 'customers/' . $customer->id . '/wiki/' . Str::random(8) . '.pdf',
            'file_hash_sha256'  => hash('sha256', Str::random(32)),
            'extracted_text'    => 'This is the exact text from the source that supports the claim. Additional context follows.',
            'document_status'   => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createPage(Customer $customer, string $pageType, string $title): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id'      => $customer->id,
            'slug'             => Str::slug($title) . '-' . Str::lower(Str::random(4)),
            'title'            => $title,
            'page_type'        => $pageType,
            'status'           => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by'     => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function createRunPending(Customer $customer): EnterpriseWikiIngestRun
    {
        $document = $this->createDocument($customer);

        return EnterpriseWikiIngestRun::query()->create([
            'uuid'                             => Str::uuid()->toString(),
            'customer_id'                      => $customer->id,
            'trigger_type'                     => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type'                      => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id'                        => $document->id,
            'status'                           => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status'       => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_PENDING,
            'maintainer_decision_generated_at' => now(),
        ]);
    }

    private function createRunApplied(Customer $customer, EnterpriseWikiDocument $document): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid'                             => Str::uuid()->toString(),
            'customer_id'                      => $customer->id,
            'trigger_type'                     => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type'                      => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id'                        => $document->id,
            'status'                           => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status'       => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
        ]);
    }

    /**
     * Applied run with one article page in the pivot, having a current version and a single claim.
     *
     * @return array{0: EnterpriseWikiIngestRun, 1: EnterpriseWikiPage, 2: EnterpriseWikiPageVersion, 3: EnterpriseWikiClaim, 4: EnterpriseWikiDocument}
     */
    private function createAppliedRunWithClaimedPage(
        Customer $customer,
        string $pageType = EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
    ): array {
        $document = $this->createDocument($customer);
        $run      = $this->createRunApplied($customer, $document);
        $page     = $this->createPage($customer, $pageType, 'Verified Page ' . Str::random(4));

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id'       => $page->id,
            'action'                        => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number'          => 1,
            'is_current'              => true,
            'content_markdown'        => "# Verified Page\n\nThis is the exact text from the source that supports the claim.",
            'generated_by_model'      => 'gpt-5',
        ]);

        $claim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id'         => $page->id,
            'enterprise_wiki_page_version_id'  => $version->id,
            'claim_text'                       => 'Test claim text for verification.',
            'page_excerpt'                      => self::FAKE_EXCERPT,
            'position_order'                   => 0,
            'confidence'                       => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag'                    => false,
            'approval_status'                  => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);

        return [$run, $page, $version, $claim, $document];
    }
}
