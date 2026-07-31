<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiIngestRunPage;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Del 7: a narrowly-scoped, single-run re-evaluation — never a broad customer/all-runs apply,
 * never inventing best-practice status for a block that was never tagged as such (that would be
 * constructing false history), never touching internal_error claims (a separate, unrelated
 * anchor/matching problem).
 */
class EnterpriseWikiRunBestPracticeReevaluationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_eligible_claim_without_changing_it(): void
    {
        $customer = $this->createCustomer();
        [$run, , , $claim] = $this->createRunWithClaim(
            $customer,
            claimText: 'Kunden har allerede etablert fast eskaleringsrutine.',
            blockOrigin: EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            bestPracticeReason: 'Regelmessig oppfølging reduserer risiko.',
        );
        // Claim text itself is genuine best-practice wording, independent of the fixture default.
        $claim->update(['claim_text' => 'Det anbefales å etablere en fast eskaleringsrutine.', 'page_excerpt' => 'Det anbefales å etablere en fast eskaleringsrutine.']);

        $this->artisan('wiki:reevaluate-run-best-practice-claims', ['--run-id' => $run->id])
            ->expectsOutputToContain('Read-only analysis')
            ->expectsOutputToContain('Eligible for best_practice:                     1')
            ->expectsOutputToContain('Reclassified:                                   0')
            ->assertExitCode(0);

        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, $claim->fresh()->content_origin);
    }

    public function test_apply_reclassifies_eligible_claim_and_preserves_audit_trail(): void
    {
        $customer = $this->createCustomer();
        [$run, , , $claim] = $this->createRunWithClaim(
            $customer,
            claimText: 'placeholder',
            blockOrigin: EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            bestPracticeReason: 'Regelmessig oppfølging reduserer risiko.',
        );
        $claim->update(['claim_text' => 'Det anbefales å etablere en fast eskaleringsrutine.', 'page_excerpt' => 'Det anbefales å etablere en fast eskaleringsrutine.']);
        $originalVerifiedAt = now()->subDay();
        $claim->update(['verified_at' => $originalVerifiedAt]);

        $this->artisan('wiki:reevaluate-run-best-practice-claims', ['--run-id' => $run->id, '--apply' => true])
            ->expectsOutputToContain('Applied reclassifications')
            ->expectsOutputToContain('Reclassified:                                   1')
            ->assertExitCode(0);

        $fresh = $claim->fresh();
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, $fresh->content_origin);
        $this->assertSame('Regelmessig oppfølging reduserer risiko.', $fresh->review_reason);
        $this->assertSame('scoped_run_reevaluation', $fresh->review_metadata['classification_basis'] ?? null);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, $fresh->review_metadata['reevaluated_from_content_origin'] ?? null);
        $this->assertSame($run->id, $fresh->review_metadata['reevaluated_run_id'] ?? null);
        // verified_at is preserved, not reset — the original verification timestamp remains an
        // honest record even though this scoped correction supersedes its verdict.
        $this->assertSame($originalVerifiedAt->format('Y-m-d H:i:s'), $fresh->verified_at->format('Y-m-d H:i:s'));
    }

    public function test_claim_without_a_best_practice_block_is_not_reclassified(): void
    {
        $customer = $this->createCustomer();
        [$run, , , $claim] = $this->createRunWithClaim(
            $customer,
            claimText: 'Det anbefales å etablere en fast eskaleringsrutine.',
            blockOrigin: EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            bestPracticeReason: null,
        );

        $this->artisan('wiki:reevaluate-run-best-practice-claims', ['--run-id' => $run->id, '--apply' => true])
            ->expectsOutputToContain('Eligible for best_practice:                     0')
            ->expectsOutputToContain('Skipped — no matching best_practice block:      1')
            ->assertExitCode(0);

        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, $claim->fresh()->content_origin);
    }

    public function test_best_practice_block_missing_reason_is_not_reclassified(): void
    {
        $customer = $this->createCustomer();
        [$run, , $version, $claim] = $this->createRunWithClaim(
            $customer,
            claimText: 'Det anbefales å etablere en fast eskaleringsrutine.',
            blockOrigin: EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            bestPracticeReason: '',
        );

        $this->artisan('wiki:reevaluate-run-best-practice-claims', ['--run-id' => $run->id, '--apply' => true])
            ->expectsOutputToContain('Skipped — block missing best_practice_reason:   1')
            ->assertExitCode(0);

        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, $claim->fresh()->content_origin);
        $this->assertNotNull($version);
    }

    public function test_current_state_assertion_is_not_reclassified_even_with_best_practice_block(): void
    {
        $customer = $this->createCustomer();
        [$run, , , $claim] = $this->createRunWithClaim(
            $customer,
            claimText: 'Kunden har allerede etablert fast eskaleringsrutine.',
            blockOrigin: EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            bestPracticeReason: 'Regelmessig oppfølging reduserer risiko.',
        );

        $this->artisan('wiki:reevaluate-run-best-practice-claims', ['--run-id' => $run->id, '--apply' => true])
            ->expectsOutputToContain('Skipped — text not a genuine recommendation:    1')
            ->assertExitCode(0);

        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, $claim->fresh()->content_origin);
    }

    /**
     * Regression for ingest run 486: real production wording split from a best_practice block —
     * no marker of its own ("bør"/"anbefales" lived in a sibling sentence of the same paragraph),
     * but also no current-state assertion. Unlike the marker-based check this command used to
     * apply, it must still be eligible and reclassified — the block's own tag is enough.
     */
    public function test_claim_without_its_own_marker_but_no_drift_is_reclassified(): void
    {
        $customer = $this->createCustomer();
        [$run, , , $claim] = $this->createRunWithClaim(
            $customer,
            claimText: 'Typiske grenseflater omfatter problemhåndtering, endringsstyring, kunnskapsforvaltning og forespørselshåndtering i ITIL.',
            blockOrigin: EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            bestPracticeReason: 'Regelmessig oppfølging reduserer risiko.',
        );

        $this->artisan('wiki:reevaluate-run-best-practice-claims', ['--run-id' => $run->id, '--apply' => true])
            ->expectsOutputToContain('Eligible for best_practice:                     1')
            ->expectsOutputToContain('Reclassified:                                   1')
            ->assertExitCode(0);

        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE, $claim->fresh()->content_origin);
    }

    public function test_internal_error_claims_are_never_touched(): void
    {
        $customer = $this->createCustomer();
        [$run, $page, $version] = $this->createRunWithClaim(
            $customer,
            claimText: 'Dette finnes ikke i siden.',
            blockOrigin: EnterpriseWikiClaim::CONTENT_ORIGIN_BEST_PRACTICE,
            bestPracticeReason: 'Regelmessig oppfølging reduserer risiko.',
        );

        $internalErrorClaim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Det anbefales å gjøre noe som ikke finnes i blokken.',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR,
            'generation_issue' => 'genuine_content_mismatch',
            'position_order' => 1,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);

        $this->artisan('wiki:reevaluate-run-best-practice-claims', ['--run-id' => $run->id, '--apply' => true])
            ->assertExitCode(0);

        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_INTERNAL_ERROR, $internalErrorClaim->fresh()->content_origin);
    }

    public function test_command_fails_without_run_id(): void
    {
        $this->artisan('wiki:reevaluate-run-best-practice-claims')
            ->expectsOutputToContain('--run-id is required')
            ->assertExitCode(1);
    }

    public function test_command_fails_when_run_not_found(): void
    {
        $this->artisan('wiki:reevaluate-run-best-practice-claims', ['--run-id' => 999999])
            ->assertExitCode(1);
    }

    /**
     * @return array{0: EnterpriseWikiIngestRun, 1: EnterpriseWikiPage, 2: EnterpriseWikiPageVersion, 3: EnterpriseWikiClaim}
     */
    private function createRunWithClaim(
        Customer $customer,
        string $claimText,
        string $blockOrigin,
        ?string $bestPracticeReason,
    ): array {
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Source text for testing.',
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
            'slug' => 'reeval-page-'.Str::lower(Str::random(6)),
            'title' => 'Reeval Page',
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
            'content_markdown' => "# Reeval Page\n\n{$claimText}",
            'content_blocks_json' => [[
                'block_key' => 'block-0001',
                'position' => 0,
                'markdown' => $claimText,
                'content_origin' => $blockOrigin,
                'best_practice_reason' => $bestPracticeReason,
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
            'verified_at' => now(),
        ]);

        return [$run, $page, $version, $claim];
    }

    private function createCustomer(string $name = 'Reeval Test AS'): Customer
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
