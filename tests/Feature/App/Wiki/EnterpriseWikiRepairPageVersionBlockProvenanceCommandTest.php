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
 * wiki:repair-page-version-block-provenance — reconstructs content_blocks_json for a page's
 * current version from an earlier block-bearing version, and re-links unanchored claims to the
 * correct content_block_key. Mirrors the exact run-39 drift: EnterpriseWikiLinkSemanticRepairService
 * writes a new current version with content_markdown only, never content_blocks_json.
 */
class EnterpriseWikiRepairPageVersionBlockProvenanceCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_fails_when_run_id_is_not_numeric(): void
    {
        $this->artisan('wiki:repair-page-version-block-provenance', ['--run-id' => 'abc'])
            ->expectsOutputToContain('must be numeric')
            ->assertExitCode(1);
    }

    public function test_command_fails_when_run_not_found(): void
    {
        $this->artisan('wiki:repair-page-version-block-provenance', ['--run-id' => 99999])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    }

    public function test_blocks_are_reconstructed_for_an_unambiguous_bijective_match(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunApplied($customer);
        $page = $this->createPage($customer);
        $this->addPageToRun($run, $page);

        $priorBlocks = [
            $this->sourceBasedBlock('block-0001', "# Test Page\nFørste avsnitt uten lenke."),
            $this->sourceBasedBlock('block-0002', 'Andre avsnitt som nevner [[andre-side|Andre side]] i teksten.'),
        ];
        $this->createVersion($page, 1, false, $priorBlocks);

        // The new version moved the wikilink to a different word in the SAME sentence — the
        // visible text is otherwise byte-identical, exactly like EnterpriseWikiLinkSemanticRepairService's
        // revisions in production.
        $currentMarkdown = "# Test Page\nFørste avsnitt uten lenke.\n\nAndre avsnitt som [[andre-side|nevner]] Andre side i teksten.";
        $current = $this->createVersion($page, 2, true, null, $currentMarkdown);

        $this->artisan('wiki:repair-page-version-block-provenance', ['--run-id' => $run->id])
            ->expectsOutputToContain('Page versions repaired (would be): 1')
            ->assertExitCode(0);

        // Dry run — nothing persisted yet.
        $this->assertEmpty($current->fresh()->content_blocks_json ?? []);

        $this->artisan('wiki:repair-page-version-block-provenance', ['--run-id' => $run->id, '--apply' => true]);

        $blocks = collect($current->fresh()->content_blocks_json);
        $this->assertCount(2, $blocks);
        $this->assertSame('source_based', $blocks->firstWhere('block_key', 'block-0001')['content_origin']);
        $this->assertSame('source_based', $blocks->firstWhere('block_key', 'block-0002')['content_origin']);
        $this->assertNotEmpty($blocks->firstWhere('block_key', 'block-0002')['source_elements']);

        // content_markdown itself must never be touched.
        $this->assertSame($currentMarkdown, $current->fresh()->content_markdown);
    }

    public function test_claim_with_empty_block_key_is_linked_to_the_reconstructed_block(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunApplied($customer);
        $page = $this->createPage($customer);
        $this->addPageToRun($run, $page);

        $priorBlocks = [
            $this->sourceBasedBlock('block-0001', 'ITIL sikrer forutsigbar håndtering av henvendelser.'),
        ];
        $this->createVersion($page, 1, false, $priorBlocks);
        $current = $this->createVersion($page, 2, true, null, 'ITIL sikrer forutsigbar håndtering av henvendelser.');

        $claim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $current->id,
            'content_block_key' => '',
            'claim_text' => 'ITIL sikrer forutsigbar håndtering av henvendelser.',
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);

        $this->artisan('wiki:repair-page-version-block-provenance', ['--run-id' => $run->id, '--apply' => true])
            ->expectsOutputToContain('Claims linked: 1');

        $this->assertSame('block-0001', $claim->fresh()->content_block_key);
    }

    public function test_claim_matching_zero_blocks_is_left_unlinked_not_guessed(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunApplied($customer);
        $page = $this->createPage($customer);
        $this->addPageToRun($run, $page);

        $priorBlocks = [
            $this->sourceBasedBlock('block-0001', 'ITIL sikrer forutsigbar håndtering av henvendelser.'),
        ];
        $this->createVersion($page, 1, false, $priorBlocks);
        $current = $this->createVersion($page, 2, true, null, 'ITIL sikrer forutsigbar håndtering av henvendelser.');

        $claim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $current->id,
            'content_block_key' => '',
            'claim_text' => 'Denne teksten finnes ikke noe sted i siden.',
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);

        $this->artisan('wiki:repair-page-version-block-provenance', ['--run-id' => $run->id, '--apply' => true])
            ->expectsOutputToContain('Claims still ambiguous:       1');

        $this->assertSame('', $claim->fresh()->content_block_key);
    }

    public function test_page_version_with_mismatched_segment_count_is_left_untouched(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunApplied($customer);
        $page = $this->createPage($customer);
        $this->addPageToRun($run, $page);

        $priorBlocks = [
            $this->sourceBasedBlock('block-0001', 'Første avsnitt.'),
            $this->sourceBasedBlock('block-0002', 'Andre avsnitt.'),
        ];
        $this->createVersion($page, 1, false, $priorBlocks);

        // The current version merged both paragraphs into one — segment count (1) no longer
        // matches block count (2). Must be refused, not guessed.
        $current = $this->createVersion($page, 2, true, null, 'Første avsnitt. Andre avsnitt.');

        $this->artisan('wiki:repair-page-version-block-provenance', ['--run-id' => $run->id, '--apply' => true])
            ->expectsOutputToContain('Page versions skipped (ambiguous mapping):  1');

        $this->assertEmpty($current->fresh()->content_blocks_json ?? []);
    }

    public function test_page_version_with_duplicate_normalized_segments_is_left_untouched(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunApplied($customer);
        $page = $this->createPage($customer);
        $this->addPageToRun($run, $page);

        $priorBlocks = [
            $this->sourceBasedBlock('block-0001', 'Samme setning gjentatt.'),
            $this->sourceBasedBlock('block-0002', 'En annen setning.'),
        ];
        $this->createVersion($page, 1, false, $priorBlocks);

        // Two segments normalize identically — no way to safely tell which old block maps to
        // which, so the whole page must be refused.
        $current = $this->createVersion($page, 2, true, null, "Samme setning gjentatt.\n\nSamme setning gjentatt.");

        $this->artisan('wiki:repair-page-version-block-provenance', ['--run-id' => $run->id, '--apply' => true])
            ->expectsOutputToContain('Page versions skipped (ambiguous mapping):  1');

        $this->assertEmpty($current->fresh()->content_blocks_json ?? []);
    }

    public function test_page_version_that_already_has_blocks_is_skipped(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunApplied($customer);
        $page = $this->createPage($customer);
        $this->addPageToRun($run, $page);

        $blocks = [$this->sourceBasedBlock('block-0001', 'Innhold.')];
        $current = $this->createVersion($page, 1, true, $blocks);

        $this->artisan('wiki:repair-page-version-block-provenance', ['--run-id' => $run->id, '--apply' => true])
            ->expectsOutputToContain('Page versions skipped (already had blocks): 1')
            ->expectsOutputToContain('Page versions repaired: 0');

        $this->assertSame($blocks, $current->fresh()->content_blocks_json);
    }

    public function test_page_version_with_no_prior_blocks_is_skipped(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunApplied($customer);
        $page = $this->createPage($customer);
        $this->addPageToRun($run, $page);

        $this->createVersion($page, 1, true, null, 'Innhold uten blokker noensinne.');

        $this->artisan('wiki:repair-page-version-block-provenance', ['--run-id' => $run->id, '--apply' => true])
            ->expectsOutputToContain('Page versions skipped (no prior blocks):    1');
    }

    public function test_repair_is_idempotent(): void
    {
        $customer = $this->createCustomer();
        $run = $this->createRunApplied($customer);
        $page = $this->createPage($customer);
        $this->addPageToRun($run, $page);

        $priorBlocks = [$this->sourceBasedBlock('block-0001', 'Innhold som ikke endres.')];
        $this->createVersion($page, 1, false, $priorBlocks);
        $this->createVersion($page, 2, true, null, 'Innhold som ikke endres.');

        $this->artisan('wiki:repair-page-version-block-provenance', ['--run-id' => $run->id, '--apply' => true]);

        $this->artisan('wiki:repair-page-version-block-provenance', ['--run-id' => $run->id, '--apply' => true])
            ->expectsOutputToContain('Page versions repaired: 0')
            ->expectsOutputToContain('Page versions skipped (already had blocks): 1');
    }

    public function test_other_customers_are_not_affected(): void
    {
        $customerA = $this->createCustomer('Block Provenance A');
        $customerB = $this->createCustomer('Block Provenance B');

        $runA = $this->createRunApplied($customerA);
        $pageA = $this->createPage($customerA);
        $this->addPageToRun($runA, $pageA);
        $this->createVersion($pageA, 1, false, [$this->sourceBasedBlock('block-0001', 'Innhold A.')]);
        $currentA = $this->createVersion($pageA, 2, true, null, 'Innhold A.');

        $runB = $this->createRunApplied($customerB);
        $pageB = $this->createPage($customerB);
        $this->addPageToRun($runB, $pageB);
        $this->createVersion($pageB, 1, false, [$this->sourceBasedBlock('block-0001', 'Innhold B.')]);
        $currentB = $this->createVersion($pageB, 2, true, null, 'Innhold B.');

        $this->artisan('wiki:repair-page-version-block-provenance', ['--run-id' => $runA->id, '--apply' => true]);

        $this->assertNotEmpty($currentA->fresh()->content_blocks_json);
        $this->assertEmpty($currentB->fresh()->content_blocks_json ?? []);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function sourceBasedBlock(string $blockKey, string $markdown): array
    {
        return [
            'block_key' => $blockKey,
            'position' => 0,
            'markdown' => $markdown,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'source_id' => 123,
            'source_label' => 'source.docx',
            'source_elements' => [[
                'source_type' => 'enterprise_wiki_document',
                'source_id' => 123,
                'source_element_key' => 'paragraph-1',
                'source_excerpt' => $markdown,
            ]],
        ];
    }

    private function createCustomer(string $name = 'Block Provenance AS'): Customer
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
            'extracted_text' => 'Source text for block provenance repair tests.',
            'document_status' => EnterpriseWikiDocument::DOCUMENT_STATUS_EXTRACTED,
        ]);
    }

    private function createRunApplied(Customer $customer): EnterpriseWikiIngestRun
    {
        $document = $this->createDocument($customer);

        return EnterpriseWikiIngestRun::query()->create([
            'uuid' => Str::uuid()->toString(),
            'customer_id' => $customer->id,
            'trigger_type' => EnterpriseWikiIngestRun::TRIGGER_TYPE_MANUAL,
            'source_type' => EnterpriseWikiIngestRun::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $document->id,
            'status' => EnterpriseWikiIngestRun::STATUS_DECISION_ONLY,
            'maintainer_decision_status' => EnterpriseWikiIngestRun::MAINTAINER_DECISION_STATUS_APPLIED,
            'maintainer_decision_generated_at' => now(),
        ]);
    }

    private function createPage(Customer $customer, string $title = 'Test Page'): EnterpriseWikiPage
    {
        return EnterpriseWikiPage::query()->create([
            'customer_id' => $customer->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'title' => $title,
            'page_type' => EnterpriseWikiPage::PAGE_TYPE_CONCEPT,
            'status' => EnterpriseWikiPage::STATUS_APPROVED,
            'generated_by' => EnterpriseWikiPage::GENERATED_BY_AI_JOB,
            'last_source_hash' => str_pad('hash', 64, '0'),
        ]);
    }

    private function addPageToRun(EnterpriseWikiIngestRun $run, EnterpriseWikiPage $page): void
    {
        EnterpriseWikiIngestRunPage::query()->create([
            'enterprise_wiki_ingest_run_id' => $run->id,
            'enterprise_wiki_page_id' => $page->id,
            'action' => EnterpriseWikiIngestRunPage::ACTION_CREATED,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>|null  $blocks
     */
    private function createVersion(
        EnterpriseWikiPage $page,
        int $versionNumber,
        bool $isCurrent,
        ?array $blocks,
        ?string $markdown = null,
    ): EnterpriseWikiPageVersion {
        if ($isCurrent) {
            EnterpriseWikiPageVersion::query()
                ->where('enterprise_wiki_page_id', $page->id)
                ->update(['is_current' => false]);
        }

        return EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => $versionNumber,
            'is_current' => $isCurrent,
            'content_markdown' => $markdown ?? implode("\n\n", array_column($blocks ?? [], 'markdown')),
            'content_blocks_json' => $blocks,
            'generated_by_model' => 'gpt-5',
        ]);
    }
}
