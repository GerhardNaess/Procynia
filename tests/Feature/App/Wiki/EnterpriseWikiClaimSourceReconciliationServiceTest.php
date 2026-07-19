<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiClaimSourceReconciliationAttempt;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\Ai\Wiki\WikiClaimVerificationAiClient;
use App\Services\EnterpriseWiki\EnterpriseWikiClaimSourceReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesEnterpriseWikiFixtures;
use Tests\TestCase;

/**
 * Automatic check of a newly-processed Enterprise Wiki document against existing claims that
 * currently have no source reference. Reuses the existing WikiClaimVerificationAiClient rather
 * than a parallel verification engine — see EnterpriseWikiClaimSourceReconciliationService.
 */
class EnterpriseWikiClaimSourceReconciliationServiceTest extends TestCase
{
    use CreatesEnterpriseWikiFixtures;
    use RefreshDatabase;

    private const FAKE_EXCERPT = 'Advania er leverandør av IT-driftstjenester til norske virksomheter.';

    private const CLAIM_TEXT = 'Advania er leverandør av IT-driftstjenester.';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.enterprise_wiki.ai_enabled' => true]);

        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->andReturn($this->verificationResult())
            ->byDefault();
    }

    private function service(): EnterpriseWikiClaimSourceReconciliationService
    {
        return app(EnterpriseWikiClaimSourceReconciliationService::class);
    }

    // =========================================================================
    // Supported: source reference created
    // =========================================================================

    public function test_new_document_supporting_claim_creates_exactly_one_source_reference(): void
    {
        $customer = $this->createCustomer();
        $page = $this->createPage($customer);
        $claim = $this->createClaim($page, self::CLAIM_TEXT);
        $document = $this->createDocument($customer, self::FAKE_EXCERPT);

        $this->service()->reconcileForDocument($document);

        $this->assertSame(
            1,
            EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $claim->id)->count(),
        );
    }

    public function test_source_reference_has_correct_excerpt_and_document_link(): void
    {
        $customer = $this->createCustomer();
        $page = $this->createPage($customer);
        $claim = $this->createClaim($page, self::CLAIM_TEXT);
        $document = $this->createDocument($customer, self::FAKE_EXCERPT, 'advania-drift.pdf');

        $this->service()->reconcileForDocument($document);

        $ref = EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $claim->id)->firstOrFail();

        $this->assertSame(EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT, $ref->source_type);
        $this->assertSame($document->id, $ref->source_id);
        $this->assertSame('advania-drift.pdf', $ref->source_label);
        $this->assertSame(self::FAKE_EXCERPT, $ref->excerpt);
    }

    public function test_claim_shows_source_found_after_reconciliation(): void
    {
        $customer = $this->createCustomer();
        $page = $this->createPage($customer);
        $claim = $this->createClaim($page, self::CLAIM_TEXT);
        $document = $this->createDocument($customer, self::FAKE_EXCERPT);

        $this->service()->reconcileForDocument($document);

        $this->assertSame(EnterpriseWikiClaim::SOURCE_STATUS_FOUND, $claim->fresh()->sourceStatus());
        $this->assertFalse($claim->fresh()->needsSourceWarning());
    }

    // =========================================================================
    // Not supported: nothing changes
    // =========================================================================

    public function test_new_document_not_supporting_claim_changes_nothing(): void
    {
        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->once()
            ->andReturn($this->verificationResult(verdict: 'not_supported', reason: 'No candidate excerpt supports this claim.'));

        $customer = $this->createCustomer();
        $page = $this->createPage($customer);
        $claim = $this->createClaim($page, self::CLAIM_TEXT);
        $document = $this->createDocument($customer, 'Helt urelatert innhold uten treff.');

        $this->service()->reconcileForDocument($document);

        $this->assertSame(
            0,
            EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $claim->id)->count(),
        );
        $this->assertTrue($claim->fresh()->needsSourceWarning());
    }

    // =========================================================================
    // Idempotency: no duplicate AI calls / references / attempt rows
    // =========================================================================

    public function test_running_reconciliation_twice_causes_no_duplicates_or_extra_ai_calls(): void
    {
        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->once()
            ->andReturn($this->verificationResult());

        $customer = $this->createCustomer();
        $page = $this->createPage($customer);
        $claim = $this->createClaim($page, self::CLAIM_TEXT);
        $document = $this->createDocument($customer, self::FAKE_EXCERPT);

        $service = $this->service();
        $service->reconcileForDocument($document);
        $service->reconcileForDocument($document);

        $this->assertSame(
            1,
            EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $claim->id)->count(),
        );
        $this->assertSame(
            1,
            EnterpriseWikiClaimSourceReconciliationAttempt::query()
                ->where('enterprise_wiki_claim_id', $claim->id)
                ->where('enterprise_wiki_document_id', $document->id)
                ->count(),
        );
    }

    // =========================================================================
    // Tenant isolation
    // =========================================================================

    public function test_customer_a_document_is_never_used_as_source_for_customer_b_claim(): void
    {
        $customerA = $this->createCustomer('Kunde A AS');
        $customerB = $this->createCustomer('Kunde B AS');

        $pageB = $this->createPage($customerB);
        $claimB = $this->createClaim($pageB, self::CLAIM_TEXT);
        $documentA = $this->createDocument($customerA, self::FAKE_EXCERPT);

        $this->service()->reconcileForDocument($documentA);

        $this->assertSame(
            0,
            EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $claimB->id)->count(),
        );
        $this->assertSame(
            0,
            EnterpriseWikiClaimSourceReconciliationAttempt::query()
                ->where('enterprise_wiki_claim_id', $claimB->id)
                ->count(),
        );
    }

    // =========================================================================
    // verified_at is not a sufficient checkpoint
    // =========================================================================

    public function test_claim_with_verified_at_but_no_source_is_still_checked_against_new_document(): void
    {
        $customer = $this->createCustomer();
        $page = $this->createPage($customer);
        $claim = $this->createClaim($page, self::CLAIM_TEXT, ['verified_at' => now()->subDay()]);
        $document = $this->createDocument($customer, self::FAKE_EXCERPT);

        $this->service()->reconcileForDocument($document);

        $this->assertSame(
            1,
            EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $claim->id)->count(),
        );
    }

    // =========================================================================
    // Manually-approved claims can still receive a real source reference
    // =========================================================================

    public function test_manually_approved_claim_can_later_receive_a_real_source_reference(): void
    {
        $customer = $this->createCustomer();
        $page = $this->createPage($customer);
        $claim = $this->createClaim($page, self::CLAIM_TEXT, [
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_APPROVED,
            'approved_at' => now()->subHour(),
            'approval_comment' => 'Bekreftet muntlig.',
        ]);
        $document = $this->createDocument($customer, self::FAKE_EXCERPT);

        $this->service()->reconcileForDocument($document);

        $fresh = $claim->fresh();
        $this->assertSame(EnterpriseWikiClaim::SOURCE_STATUS_FOUND, $fresh->sourceStatus());
        $this->assertTrue($fresh->isApproved());
        $this->assertSame('Bekreftet muntlig.', $fresh->approval_comment);
    }

    // =========================================================================
    // No regeneration side effects
    // =========================================================================

    public function test_reconciliation_does_not_regenerate_pages_versions_or_claims(): void
    {
        $customer = $this->createCustomer();
        $page = $this->createPage($customer);
        $version = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->firstOrFail();
        $claim = $this->createClaim($page, self::CLAIM_TEXT);
        $document = $this->createDocument($customer, self::FAKE_EXCERPT);

        $pagesBefore = EnterpriseWikiPage::query()->count();
        $versionsBefore = EnterpriseWikiPageVersion::query()->count();
        $claimsBefore = EnterpriseWikiClaim::query()->count();
        $markdownBefore = $version->content_markdown;

        $this->service()->reconcileForDocument($document);

        $this->assertSame($pagesBefore, EnterpriseWikiPage::query()->count());
        $this->assertSame($versionsBefore, EnterpriseWikiPageVersion::query()->count());
        $this->assertSame($claimsBefore, EnterpriseWikiClaim::query()->count());
        $this->assertSame($markdownBefore, $version->fresh()->content_markdown);
        $this->assertSame($claim->claim_text, $claim->fresh()->claim_text);
    }

    // =========================================================================
    // Lint: automatic source resolves the missing-source finding
    // =========================================================================

    public function test_automatic_source_reference_resolves_missing_source_finding(): void
    {
        $customer = $this->createCustomer();
        $page = $this->createPage($customer);
        $claim = $this->createClaim($page, self::CLAIM_TEXT);
        $document = $this->createDocument($customer, self::FAKE_EXCERPT);

        $finding = EnterpriseWikiLintFinding::query()->create([
            'customer_id' => $customer->id,
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_claim_id' => $claim->id,
            'enterprise_wiki_document_id' => null,
            'code' => EnterpriseWikiLintFinding::CODE_CLAIM_MISSING_SOURCE,
            'severity' => EnterpriseWikiLintFinding::SEVERITY_WARNING,
            'message' => 'Claim has no source reference.',
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
            'detected_at' => now(),
        ]);

        $this->service()->reconcileForDocument($document);

        $this->assertSame(EnterpriseWikiLintFinding::STATUS_RESOLVED, $finding->fresh()->status);
    }

    // =========================================================================
    // Claims that already have a real source are skipped entirely
    // =========================================================================

    public function test_claim_with_existing_source_reference_is_not_reconciled(): void
    {
        $this->mock(WikiClaimVerificationAiClient::class)
            ->shouldReceive('verifyClaim')
            ->never();

        $customer = $this->createCustomer();
        $page = $this->createPage($customer);
        $claim = $this->createClaim($page, self::CLAIM_TEXT);

        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => 999999,
            'source_label' => 'existing.pdf',
            'excerpt' => 'Eksisterende utdrag.',
        ]);

        $document = $this->createDocument($customer, self::FAKE_EXCERPT);

        $this->service()->reconcileForDocument($document);

        $this->assertSame(
            1,
            EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $claim->id)->count(),
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createCustomer(string $name = 'Reconciliation Test AS'): Customer
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

    private function createPage(Customer $customer): EnterpriseWikiPage
    {
        $page = EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'recon-page-'.Str::lower(Str::random(8)),
            'title' => 'Reconciliation Page',
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_ARTICLE,
            'status' => EnterpriseWikiPage::STATUS_DRAFT,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);

        EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => '# Reconciliation Page',
            'generated_by_model' => 'gpt-5',
        ]);

        return $page;
    }

    private function createClaim(EnterpriseWikiPage $page, string $text, array $overrides = []): EnterpriseWikiClaim
    {
        $version = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->where('is_current', true)
            ->firstOrFail();

        return EnterpriseWikiClaim::query()->create(array_merge([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => $text,
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ], $overrides));
    }

    private function createDocument(Customer $customer, string $extractedText, string $filename = 'doc.pdf'): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => $filename,
            'file_path' => 'customers/'.$customer->id.'/wiki-documents/'.Str::random(8).'.pdf',
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'extracted_text' => $extractedText,
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }
}
