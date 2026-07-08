<?php

namespace Tests\Feature\Console;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiDocument;
use App\Models\EnterpriseWikiIngestRun;
use App\Models\EnterpriseWikiLintFinding;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\EnterpriseWikiSourceReference;
use App\Models\Language;
use App\Models\Nationality;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnterpriseWikiLintCommandTest extends TestCase
{
    use RefreshDatabase;

    // ─── Claim missing source ─────────────────────────────────────────────────

    public function test_claim_without_source_reference_creates_finding(): void
    {
        $customer = $this->createCustomer();
        $page = $this->createPage($customer);
        $claim = $this->createClaim($page);

        $this->artisan('wiki:lint', ['--customer' => (string) $customer->id])
            ->assertSuccessful();

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'customer_id' => $customer->id,
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_claim_id' => $claim->id,
            'code' => EnterpriseWikiLintFinding::CODE_CLAIM_MISSING_SOURCE,
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
        ]);
    }

    public function test_claim_missing_source_is_error_for_pending_review_page(): void
    {
        $customer = $this->createCustomer();
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_PENDING_REVIEW);
        $this->createClaim($page);

        $this->artisan('wiki:lint', ['--customer' => (string) $customer->id])
            ->assertSuccessful();

        $this->assertSame(
            EnterpriseWikiLintFinding::SEVERITY_ERROR,
            EnterpriseWikiLintFinding::query()->first()->severity,
        );
    }

    public function test_claim_missing_source_is_warning_for_draft_page(): void
    {
        $customer = $this->createCustomer();
        $page = $this->createPage($customer, EnterpriseWikiPage::STATUS_DRAFT);
        $this->createClaim($page);

        $this->artisan('wiki:lint', ['--customer' => (string) $customer->id])
            ->assertSuccessful();

        $this->assertSame(
            EnterpriseWikiLintFinding::SEVERITY_WARNING,
            EnterpriseWikiLintFinding::query()->first()->severity,
        );
    }

    // ─── Source reference missing excerpt ─────────────────────────────────────

    public function test_source_reference_without_excerpt_creates_finding(): void
    {
        $customer = $this->createCustomer();
        $page = $this->createPage($customer);
        $claim = $this->createClaim($page);
        $this->createSourceReference($claim, excerpt: null);

        $this->artisan('wiki:lint', ['--customer' => (string) $customer->id])
            ->assertSuccessful();

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'customer_id' => $customer->id,
            'enterprise_wiki_claim_id' => $claim->id,
            'code' => EnterpriseWikiLintFinding::CODE_SOURCE_REFERENCE_MISSING_EXCERPT,
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
        ]);
    }

    public function test_claim_with_source_reference_and_excerpt_does_not_create_finding(): void
    {
        $customer = $this->createCustomer();
        $page = $this->createPage($customer);
        $claim = $this->createClaim($page);
        $this->createSourceReference($claim, excerpt: 'Dette er et relevant utdrag fra kilden.');

        $this->artisan('wiki:lint', ['--customer' => (string) $customer->id])
            ->assertSuccessful();

        $this->assertSame(0, EnterpriseWikiLintFinding::query()->count());
    }

    // ─── Document ingest failed ───────────────────────────────────────────────

    public function test_failed_document_ingest_creates_finding(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $this->createIngestRun($customer, $document, EnterpriseWikiIngestRun::STATUS_FAILED);

        $this->artisan('wiki:lint', ['--customer' => (string) $customer->id])
            ->assertSuccessful();

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', [
            'customer_id' => $customer->id,
            'enterprise_wiki_document_id' => $document->id,
            'code' => EnterpriseWikiLintFinding::CODE_DOCUMENT_INGEST_FAILED,
            'status' => EnterpriseWikiLintFinding::STATUS_OPEN,
        ]);
    }

    public function test_completed_document_ingest_does_not_create_finding(): void
    {
        $customer = $this->createCustomer();
        $document = $this->createDocument($customer);
        $this->createIngestRun($customer, $document, EnterpriseWikiIngestRun::STATUS_COMPLETED);

        $this->artisan('wiki:lint', ['--customer' => (string) $customer->id])
            ->assertSuccessful();

        $this->assertSame(0, EnterpriseWikiLintFinding::query()->count());
    }

    // ─── Idempotency ─────────────────────────────────────────────────────────

    public function test_lint_is_idempotent(): void
    {
        $customer = $this->createCustomer();
        $page = $this->createPage($customer);
        $this->createClaim($page);

        $this->artisan('wiki:lint', ['--customer' => (string) $customer->id])->assertSuccessful();
        $this->artisan('wiki:lint', ['--customer' => (string) $customer->id])->assertSuccessful();

        $this->assertSame(1, EnterpriseWikiLintFinding::query()->count());
    }

    // ─── Resolution ──────────────────────────────────────────────────────────

    public function test_finding_is_resolved_when_claim_gets_source_reference(): void
    {
        $customer = $this->createCustomer();
        $page = $this->createPage($customer);
        $claim = $this->createClaim($page);

        // First run: claim has no source → finding created
        $this->artisan('wiki:lint', ['--customer' => (string) $customer->id])->assertSuccessful();

        $this->assertSame(1, EnterpriseWikiLintFinding::query()
            ->where('code', EnterpriseWikiLintFinding::CODE_CLAIM_MISSING_SOURCE)
            ->where('status', EnterpriseWikiLintFinding::STATUS_OPEN)
            ->count());

        // Add a source reference with excerpt
        $this->createSourceReference($claim, excerpt: 'Utdrag fra kilden.');

        // Second run: claim now has a source → finding resolved
        $this->artisan('wiki:lint', ['--customer' => (string) $customer->id])->assertSuccessful();

        $this->assertSame(0, EnterpriseWikiLintFinding::query()
            ->where('code', EnterpriseWikiLintFinding::CODE_CLAIM_MISSING_SOURCE)
            ->where('status', EnterpriseWikiLintFinding::STATUS_OPEN)
            ->count());

        $this->assertSame(1, EnterpriseWikiLintFinding::query()
            ->where('code', EnterpriseWikiLintFinding::CODE_CLAIM_MISSING_SOURCE)
            ->where('status', EnterpriseWikiLintFinding::STATUS_RESOLVED)
            ->count());
    }

    public function test_resolved_finding_is_reopened_when_problem_returns(): void
    {
        $customer = $this->createCustomer();
        $page = $this->createPage($customer);
        $claim = $this->createClaim($page);
        $ref = $this->createSourceReference($claim, excerpt: 'Utdrag.');

        // First run: no problem
        $this->artisan('wiki:lint', ['--customer' => (string) $customer->id])->assertSuccessful();
        $this->assertSame(0, EnterpriseWikiLintFinding::query()->where('status', 'open')->count());

        // Remove excerpt
        $ref->update(['excerpt' => null]);

        // Second run: problem returns → finding opened
        $this->artisan('wiki:lint', ['--customer' => (string) $customer->id])->assertSuccessful();
        $this->assertSame(1, EnterpriseWikiLintFinding::query()
            ->where('code', EnterpriseWikiLintFinding::CODE_SOURCE_REFERENCE_MISSING_EXCERPT)
            ->where('status', EnterpriseWikiLintFinding::STATUS_OPEN)
            ->count());
    }

    // ─── Scheduled / no-args mode ────────────────────────────────────────────

    public function test_command_without_arguments_succeeds_on_empty_database(): void
    {
        // Simulates the scheduled cron run when no customers exist yet.
        $this->artisan('wiki:lint')
            ->expectsOutputToContain('Opened: 0')
            ->assertSuccessful();
    }

    public function test_command_without_arguments_lints_all_active_customers(): void
    {
        $customerA = $this->createCustomer('Kunde A');
        $customerB = $this->createCustomer('Kunde B');
        $pageA = $this->createPage($customerA);
        $pageB = $this->createPage($customerB);
        $this->createClaim($pageA);
        $this->createClaim($pageB);

        $this->artisan('wiki:lint')->assertSuccessful();

        // Findings created for both customers without specifying --customer
        $this->assertDatabaseHas('enterprise_wiki_lint_findings', ['customer_id' => $customerA->id]);
        $this->assertDatabaseHas('enterprise_wiki_lint_findings', ['customer_id' => $customerB->id]);
    }

    public function test_command_without_arguments_skips_inactive_customers(): void
    {
        $active = $this->createCustomer('Aktiv kunde');
        $inactive = $this->createCustomer('Inaktiv kunde', isActive: false);
        $pageActive = $this->createPage($active);
        $pageInactive = $this->createPage($inactive);
        $this->createClaim($pageActive);
        $this->createClaim($pageInactive);

        $this->artisan('wiki:lint')->assertSuccessful();

        $this->assertDatabaseHas('enterprise_wiki_lint_findings', ['customer_id' => $active->id]);
        $this->assertDatabaseMissing('enterprise_wiki_lint_findings', ['customer_id' => $inactive->id]);
    }

    // ─── Command options ──────────────────────────────────────────────────────

    public function test_command_with_unknown_customer_fails(): void
    {
        $this->artisan('wiki:lint', ['--customer' => '99999'])
            ->expectsOutputToContain('not found')
            ->assertFailed();
    }

    public function test_command_with_unknown_page_fails(): void
    {
        $this->artisan('wiki:lint', ['--page' => '99999'])
            ->expectsOutputToContain('not found')
            ->assertFailed();
    }

    public function test_command_with_page_option_lints_only_that_page(): void
    {
        $customer = $this->createCustomer();
        $page = $this->createPage($customer);
        $otherPage = $this->createPage($customer);
        $this->createClaim($page);
        $this->createClaim($otherPage);

        $this->artisan('wiki:lint', ['--page' => (string) $page->id])->assertSuccessful();

        // Only the specified page was linted
        $this->assertSame(1, EnterpriseWikiLintFinding::query()->count());
        $this->assertSame($page->id, EnterpriseWikiLintFinding::query()->first()->enterprise_wiki_page_id);
    }

    public function test_command_outputs_opened_count(): void
    {
        $customer = $this->createCustomer();
        $page = $this->createPage($customer);
        $this->createClaim($page);

        $this->artisan('wiki:lint', ['--customer' => (string) $customer->id])
            ->expectsOutputToContain('Opened: 1')
            ->assertSuccessful();
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function createCustomer(string $name = 'Lint Test AS', bool $isActive = true): Customer
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
            'is_active' => $isActive,
        ]);
    }

    private function createPage(Customer $customer, string $status = EnterpriseWikiPage::STATUS_DRAFT): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => 'lint-test-'.Str::random(8),
            'title' => 'Lint Test Side',
            'status' => $status,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => hash('sha256', 'test'),
        ]);
    }

    private function createPageVersion(EnterpriseWikiPage $page): EnterpriseWikiPageVersion
    {
        return EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'content_markdown' => '# Test',
            'is_current' => true,
        ]);
    }

    private function createClaim(EnterpriseWikiPage $page): EnterpriseWikiClaim
    {
        $version = EnterpriseWikiPageVersion::query()
            ->where('enterprise_wiki_page_id', $page->id)
            ->first() ?? $this->createPageVersion($page);

        return EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Test-påstand '.Str::random(6),
            'position_order' => 1,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);
    }

    private function createSourceReference(EnterpriseWikiClaim $claim, ?string $excerpt): EnterpriseWikiSourceReference
    {
        return EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $claim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => 1,
            'source_label' => 'Testdokument',
            'excerpt' => $excerpt,
        ]);
    }

    private function createDocument(Customer $customer): EnterpriseWikiDocument
    {
        return EnterpriseWikiDocument::query()->create([
            'customer_id' => $customer->id,
            'original_filename' => 'lint-test.pdf',
            'file_path' => sprintf('customers/%d/wiki-documents/lint-test.pdf', $customer->id),
            'file_hash_sha256' => hash('sha256', Str::random(32)),
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_FAILED,
        ]);
    }

    private function createIngestRun(Customer $customer, EnterpriseWikiDocument $document, string $status): EnterpriseWikiIngestRun
    {
        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'source_hash' => hash('sha256', "enterprise_wiki_document:{$document->id}"),
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'status' => $status,
        ]);
    }
}
