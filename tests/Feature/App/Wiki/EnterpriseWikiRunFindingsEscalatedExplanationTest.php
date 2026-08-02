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
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Wiki run-585: EnterpriseWikiRunFindingsService::buildExplanation() used to give a single,
 * misleading "needs_resync" message for ANY escalated/failed run with no open blocking findings —
 * even when 14 claims were genuinely still unverified (verification_incomplete), because it only
 * ever checked the lint findings table, never incomplete_steps. These tests prove the explanation
 * now distinguishes: (1) genuinely stale status (nothing left incomplete — the old message is
 * still correct here), from (2) verification_incomplete with claims genuinely still unverified,
 * further split into "will resume automatically" vs "requires new processing" based on
 * EnterpriseWikiEscalatedRunRecoveryService's own real decision. All fixtures are synthetic; run
 * 585's real data/IDs are never used.
 */
class EnterpriseWikiRunFindingsEscalatedExplanationTest extends TestCase
{
    use RefreshDatabase;

    public function test_explanation_offers_automatic_resume_when_genuinely_resumable(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $run = $this->createEscalatedRun($customer, unverifiedClaims: 2);

        $explanation = $this->fetchExplanation($user, $run);

        $this->assertSame(
            __('procynia.wiki.runs_findings_explanation_verification_incomplete_auto'),
            $explanation,
        );
    }

    public function test_explanation_requires_new_processing_when_error_is_permanent(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $run = $this->createEscalatedRun(
            $customer,
            unverifiedClaims: 1,
            qaStatus: EnterpriseWikiIngestRun::QA_STATUS_FAILED,
            qaLastError: 'TypeError: Argument #1 must be of type array, null given',
        );

        $explanation = $this->fetchExplanation($user, $run);

        $this->assertSame(
            __('procynia.wiki.runs_findings_explanation_verification_incomplete_manual'),
            $explanation,
        );
    }

    public function test_explanation_stays_generic_needs_resync_when_genuinely_stale(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        // No unverified claims and intact content — nothing incomplete, status is just stale.
        $run = $this->createEscalatedRun($customer, unverifiedClaims: 0);

        $explanation = $this->fetchExplanation($user, $run);

        $this->assertSame(
            __('procynia.wiki.runs_findings_explanation_needs_resync'),
            $explanation,
        );
    }

    public function test_explanation_never_claims_automatic_resume_when_an_active_lease_blocks_it(): void
    {
        $customer = $this->createCustomer();
        $user = $this->createUser($customer);
        $run = $this->createEscalatedRun($customer, unverifiedClaims: 2, leaseActiveOnClaim: true);

        $explanation = $this->fetchExplanation($user, $run);

        $this->assertSame(
            __('procynia.wiki.runs_findings_explanation_verification_incomplete_manual'),
            $explanation,
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function fetchExplanation(User $user, EnterpriseWikiIngestRun $run): string
    {
        $response = $this->actingAs($user)->getJson("/app/wiki/runs/{$run->id}/findings");
        $response->assertOk();

        return $response->json('summary.explanation');
    }

    private function createCustomer(string $name = 'Findings Explanation Test AS'): Customer
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

    private function createUser(Customer $customer): User
    {
        return User::query()->create([
            'name' => 'System Owner',
            'email' => Str::lower(Str::random(8)).'@findings-explanation-test.invalid',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_USER,
            'bid_role' => User::BID_ROLE_SYSTEM_OWNER,
            'customer_id' => $customer->id,
            'is_active' => true,
        ]);
    }

    /**
     * Builds a synthetic run at status=escalated with extraction complete and a configurable
     * number of unverified claims — the Wiki run-585 shape.
     */
    private function createEscalatedRun(
        Customer $customer,
        int $unverifiedClaims = 2,
        bool $leaseActiveOnClaim = false,
        ?string $qaStatus = EnterpriseWikiIngestRun::QA_STATUS_ESCALATED,
        ?string $qaLastError = null,
    ): EnterpriseWikiIngestRun {
        $document = EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'source.pdf',
            'file_path' => 'customers/'.$customer->id.'/wiki/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => 'Source text for findings explanation tests.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);

        $run = EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_ESCALATED,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'qa_status' => $qaStatus,
            'qa_attempt_count' => 1,
            'qa_last_error' => $qaLastError,
            'error_message' => 'Interrupted before all claims were verified.',
            'finished_at' => now(),
        ]);

        $article = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'artikkel-'.Str::lower(Str::random(6)),
            'title' => 'Artikkel',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
        $summary = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'sammendrag-'.Str::lower(Str::random(6)),
            'title' => 'Sammendrag',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_SUMMARY,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        foreach ([$article, $summary] as $page) {
            EnterpriseWikiIngestRunPage::query()->create([
                'enterprise_wiki_ingest_run_id' => $run->id,
                'enterprise_wiki_page_id' => $page->id,
                'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
                'generation_status' => EnterpriseWikiIngestRunPage::GENERATION_STATUS_COMPLETED,
                'claims_extracted_at' => now(),
            ]);
        }

        $articleVersion = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $article->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# Artikkel\n\nInnhold.",
        ]);
        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $summary->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# Sammendrag\n\nInnhold.",
        ]);

        for ($i = 0; $i < $unverifiedClaims; $i++) {
            EnterpriseWikiClaim::query()->create([
                'enterprise_wiki_page_id' => $article->id,
                'enterprise_wiki_page_version_id' => $articleVersion->id,
                'claim_text' => 'Uverifisert påstand '.$i,
                'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
                'conflict_flag' => false,
                'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
                'verified_at' => null,
                'verification_claimed_at' => $leaseActiveOnClaim ? now() : null,
            ]);
        }

        return $run;
    }
}
