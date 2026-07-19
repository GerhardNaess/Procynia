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
use Illuminate\Support\Str;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\TestCase;

/**
 * Del 7 of the cross-language/paraphrase verification fix: a narrowly-scoped, single-run
 * re-evaluation of already-verified unsupported_generated_content claims — never a broad
 * customer/all-runs apply, never touching best_practice/internal_error/source_based claims,
 * never creating false source references, dry-run by default.
 */
class EnterpriseWikiReevaluateRunClaimVerificationCommandTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use RefreshDatabase;

    public function test_dry_run_reports_reclassification_without_changing_data(): void
    {
        $customer = $this->createCustomer();
        [$run, , , $claim] = $this->createRunWithUnsupportedClaim($customer);

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->once()
            ->andReturn($this->verificationResult(supportingSourceElementKeys: ['paragraph-9']));

        $this->artisan('wiki:reevaluate-run-claim-verification', ['--run-id' => $run->id])
            ->expectsOutputToContain('Read-only analysis')
            ->expectsOutputToContain('Newly supported (cross-language/paraphrase):    1')
            ->assertExitCode(0);

        $fresh = $claim->fresh();
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, $fresh->content_origin);
        $this->assertFalse(EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $claim->id)->exists());
    }

    public function test_apply_reclassifies_the_claim_and_leaves_a_provenance_trail(): void
    {
        $customer = $this->createCustomer();
        [$run, , , $claim] = $this->createRunWithUnsupportedClaim($customer);

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->once()
            ->andReturn($this->verificationResult(
                supportingSourceElementKeys: ['paragraph-9'],
                reason: 'Same fact stated in English in the source clause.',
            ));

        $this->artisan('wiki:reevaluate-run-claim-verification', ['--run-id' => $run->id, '--apply' => true])
            ->expectsOutputToContain('Applied re-evaluation')
            ->expectsOutputToContain('Newly supported (cross-language/paraphrase):    1')
            ->assertExitCode(0);

        $fresh = $claim->fresh();
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, $fresh->content_origin);
        $this->assertSame('scoped_run_reevaluation', $fresh->review_metadata['classification_basis'] ?? null);
        $this->assertSame($run->id, $fresh->review_metadata['reevaluated_run_id'] ?? null);
        $this->assertSame(
            EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            $fresh->review_metadata['reevaluated_from_content_origin'] ?? null,
        );
    }

    public function test_apply_does_not_create_a_duplicate_reference_when_one_already_exists(): void
    {
        $customer = $this->createCustomer();
        [$run, , , $claim] = $this->createRunWithUnsupportedClaim($customer);

        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $run->source_id,
            'source_element_key' => 'paragraph-9',
            'source_label' => 'source.pdf',
            'excerpt' => 'Critical incidents shall be responded to within 30 minutes.',
        ]);

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->once()
            ->andReturn($this->verificationResult(supportingSourceElementKeys: ['paragraph-9']));

        $this->artisan('wiki:reevaluate-run-claim-verification', ['--run-id' => $run->id, '--apply' => true])
            ->assertExitCode(0);

        $this->assertSame(
            1,
            EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $claim->id)->count(),
        );
    }

    public function test_deterministic_conflict_keeps_the_claim_not_supported_and_is_reported(): void
    {
        $customer = $this->createCustomer();
        [$run, , , $claim] = $this->createRunWithUnsupportedClaim($customer, claimText: 'Responstiden er 15 minutter.');

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->once()
            ->andReturn($this->verificationResult(supportingSourceElementKeys: ['paragraph-9']));

        $this->artisan('wiki:reevaluate-run-claim-verification', ['--run-id' => $run->id, '--apply' => true])
            ->expectsOutputToContain('Deterministic conflicts (overrode AI verdict):  1')
            ->expectsOutputToContain('Still not supported:                            1')
            ->assertExitCode(0);

        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, $claim->fresh()->content_origin);
    }

    public function test_internal_error_claims_are_never_touched(): void
    {
        $customer = $this->createCustomer();
        [$run, $page, $version] = $this->createRunWithUnsupportedClaim($customer);

        $internalErrorClaim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Dette finnes ikke i siden.',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR,
            'generation_issue' => 'genuine_content_mismatch',
            'position_order' => 1,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
        ]);

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->once()
            ->andReturn($this->verificationResult(supportingSourceElementKeys: ['paragraph-9']));

        $this->artisan('wiki:reevaluate-run-claim-verification', ['--run-id' => $run->id, '--apply' => true])
            ->assertExitCode(0);

        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR, $internalErrorClaim->fresh()->content_origin);
    }

    public function test_best_practice_and_source_based_claims_are_never_touched(): void
    {
        $customer = $this->createCustomer();
        [$run, $page, $version] = $this->createRunWithUnsupportedClaim($customer);

        $bestPractice = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Det anbefales å gjennomføre årlig tilgangsgjennomgang.',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            'position_order' => 1,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
        ]);
        $sourceBased = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Allerede bekreftet påstand.',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'position_order' => 2,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
        ]);

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->once()
            ->andReturn($this->verificationResult(supportingSourceElementKeys: ['paragraph-9']));

        $this->artisan('wiki:reevaluate-run-claim-verification', ['--run-id' => $run->id, '--apply' => true])
            ->assertExitCode(0);

        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, $bestPractice->fresh()->content_origin);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, $sourceBased->fresh()->content_origin);
    }

    public function test_apply_is_scoped_to_the_selected_run_only(): void
    {
        $customer = $this->createCustomer();
        [$run, , , $claim] = $this->createRunWithUnsupportedClaim($customer);
        [$otherRun, , , $otherClaim] = $this->createRunWithUnsupportedClaim($customer);

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->once()
            ->andReturn($this->verificationResult(supportingSourceElementKeys: ['paragraph-9']));

        $this->artisan('wiki:reevaluate-run-claim-verification', ['--run-id' => $run->id, '--apply' => true])
            ->assertExitCode(0);

        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED, $claim->fresh()->content_origin);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, $otherClaim->fresh()->content_origin);
    }

    public function test_command_fails_without_run_id(): void
    {
        $this->artisan('wiki:reevaluate-run-claim-verification')
            ->expectsOutputToContain('--run-id is required')
            ->assertExitCode(1);
    }

    public function test_command_fails_when_run_not_found(): void
    {
        $this->artisan('wiki:reevaluate-run-claim-verification', ['--run-id' => 999999])
            ->assertExitCode(1);
    }

    /**
     * @return array{0: EnterpriseWikiIngestRun, 1: EnterpriseWikiPage, 2: EnterpriseWikiPageVersion, 3: EnterpriseWikiClaim}
     */
    private function createRunWithUnsupportedClaim(
        Customer $customer,
        string $claimText = 'Kritiske hendelser skal besvares innen 30 minutter.',
    ): array {
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Critical incidents shall be responded to within 30 minutes.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_ESCALATED,
            'qa_status' => EnterpriseWikiIngestRun::QA_STATUS_REPAIR_REQUIRED,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
        ]);

        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'reeval-verify-page-'.Str::lower(Str::random(8)),
            'title' => 'Reeval Verify Page',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
            'status' => EnterpriseWikiPage::STATUS_APPROVED,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# Reeval Verify Page\n\n{$claimText}",
            'content_blocks_json' => [[
                'block_key' => 'block-0001',
                'position' => 0,
                'markdown' => $claimText,
                'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                'source_elements' => [[
                    'source_element_key' => 'paragraph-9',
                    'source_element_type' => 'paragraph',
                    'source_excerpt' => 'Critical incidents shall be responded to within 30 minutes.',
                    'page_reference' => 'Avsnitt 9',
                ]],
            ]],
        ]);

        $claim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => $claimText,
            'page_excerpt' => $claimText,
            'content_block_key' => 'block-0001',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'generation_issue' => 'unsupported_generated_content',
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now()->subDay(),
        ]);

        return [$run, $page, $version, $claim];
    }

    private function createCustomer(string $name = 'Reeval Verify Test AS'): Customer
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
}
