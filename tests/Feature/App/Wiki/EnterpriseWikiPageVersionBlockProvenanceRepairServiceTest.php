<?php

namespace Tests\Feature\App\Wiki;

use App\Models\Customer;
use App\Models\EnterpriseWikiClaim;
use App\Models\EnterpriseWikiPage;
use App\Models\EnterpriseWikiPageVersion;
use App\Models\Language;
use App\Models\Nationality;
use App\Services\EnterpriseWiki\EnterpriseWikiPageVersionBlockProvenanceRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * repairPageVersion() — the single-page-version entry point EnterpriseWikiLinkSemanticRepairService
 * and EnterpriseWikiSemanticRepairService now call immediately after creating a new current
 * EnterpriseWikiPageVersion, so content_blocks_json/content_block_key never sit empty until a
 * later manual wiki:repair-page-version-block-provenance sweep.
 *
 * Reuses the exact same reconstruction/matching rules as repair()/the Artisan command (see
 * EnterpriseWikiRepairPageVersionBlockProvenanceCommandTest) — this file only exercises the new
 * targeted method's own contract (single page/version, immediate apply, status envelope), not the
 * reconstruction algorithm itself.
 */
class EnterpriseWikiPageVersionBlockProvenanceRepairServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_unambiguous_reconstruction_links_claim_with_excerpt_from_one_block(): void
    {
        $customer = $this->createCustomer();
        $page = $this->createPage($customer);

        $priorBlocks = [
            $this->sourceBasedBlock('block-0001', 'ITIL sikrer forutsigbar håndtering av henvendelser.'),
            $this->sourceBasedBlock('block-0002', 'Eskalering følger en fast prioriteringsmodell.'),
        ];
        $this->createVersion($page, 1, false, $priorBlocks);
        $current = $this->createVersion(
            $page,
            2,
            true,
            null,
            "ITIL sikrer forutsigbar håndtering av henvendelser.\n\nEskalering følger en fast prioriteringsmodell.",
        );

        $claim = $this->createClaim($page, $current, 'ITIL sikrer forutsigbar håndtering av henvendelser.');

        $result = $this->service()->repairPageVersion($page->id, $current);

        $this->assertSame('repaired', $result['status']);
        $this->assertSame(1, $result['claims_linked']);
        $this->assertSame(0, $result['claims_ambiguous']);
        $this->assertSame('block-0001', $claim->fresh()->content_block_key);

        $blocks = collect($current->fresh()->content_blocks_json);
        $this->assertCount(2, $blocks);
        $this->assertSame('source_based', $blocks->firstWhere('block_key', 'block-0001')['content_origin']);
    }

    public function test_two_claims_from_the_same_block_both_get_that_blocks_key(): void
    {
        $customer = $this->createCustomer();
        $page = $this->createPage($customer);

        $blockText = 'ITIL beskriver en strukturert flyt fra registrering til lukking av hendelser.';
        $priorBlocks = [$this->sourceBasedBlock('block-0001', $blockText)];
        $this->createVersion($page, 1, false, $priorBlocks);
        $current = $this->createVersion($page, 2, true, null, $blockText);

        $claimA = $this->createClaim($page, $current, 'ITIL beskriver en strukturert flyt fra registrering til lukking av hendelser.');
        $claimB = $this->createClaim($page, $current, 'ITIL beskriver en strukturert flyt fra registrering til lukking av hendelser.', position: 1);

        $result = $this->service()->repairPageVersion($page->id, $current);

        $this->assertSame('repaired', $result['status']);
        $this->assertSame(2, $result['claims_linked']);
        $this->assertSame('block-0001', $claimA->fresh()->content_block_key);
        $this->assertSame('block-0001', $claimB->fresh()->content_block_key);
    }

    public function test_claim_with_no_unique_match_is_left_without_a_block_key(): void
    {
        $customer = $this->createCustomer();
        $page = $this->createPage($customer);

        $priorBlocks = [$this->sourceBasedBlock('block-0001', 'Innhold som finnes på siden.')];
        $this->createVersion($page, 1, false, $priorBlocks);
        $current = $this->createVersion($page, 2, true, null, 'Innhold som finnes på siden.');

        $claim = $this->createClaim($page, $current, 'Denne teksten finnes ikke noe sted på siden.');

        $result = $this->service()->repairPageVersion($page->id, $current);

        // Blocks are still reconstructed (unambiguous 1:1 bijection) — only the claim, whose
        // excerpt matches zero blocks, is left alone rather than guessed at.
        $this->assertSame('repaired', $result['status']);
        $this->assertSame(0, $result['claims_linked']);
        $this->assertSame(1, $result['claims_ambiguous']);
        $this->assertSame('', $claim->fresh()->content_block_key);
    }

    public function test_ambiguous_segment_mapping_is_refused_not_guessed(): void
    {
        $customer = $this->createCustomer();
        $page = $this->createPage($customer);

        $priorBlocks = [
            $this->sourceBasedBlock('block-0001', 'Første avsnitt.'),
            $this->sourceBasedBlock('block-0002', 'Andre avsnitt.'),
        ];
        $this->createVersion($page, 1, false, $priorBlocks);

        // The revised markdown merged both paragraphs into a single segment — segment count (1)
        // no longer matches the prior block count (2); a bijection is impossible.
        $current = $this->createVersion($page, 2, true, null, 'Første avsnitt. Andre avsnitt.');
        $claim = $this->createClaim($page, $current, 'Første avsnitt.');

        $result = $this->service()->repairPageVersion($page->id, $current);

        $this->assertSame('skipped_ambiguous', $result['status']);
        $this->assertEmpty($current->fresh()->content_blocks_json ?? []);
        $this->assertSame('', $claim->fresh()->content_block_key);
    }

    public function test_version_that_already_has_valid_blocks_is_not_touched_or_reprocessed(): void
    {
        $customer = $this->createCustomer();
        $page = $this->createPage($customer);

        $blocks = [$this->sourceBasedBlock('block-0001', 'Innhold med blokker fra før.')];
        $current = $this->createVersion($page, 1, true, $blocks);

        $result = $this->service()->repairPageVersion($page->id, $current);

        $this->assertSame('skipped_already_has_blocks', $result['status']);
        $this->assertSame(0, $result['claims_linked']);
        $this->assertSame($blocks, $current->fresh()->content_blocks_json);
    }

    public function test_version_with_no_prior_block_bearing_version_is_left_untouched(): void
    {
        $customer = $this->createCustomer();
        $page = $this->createPage($customer);

        $current = $this->createVersion($page, 1, true, null, 'Innhold uten blokker noensinne.');

        $result = $this->service()->repairPageVersion($page->id, $current);

        $this->assertSame('skipped_no_prior_blocks', $result['status']);
        $this->assertEmpty($current->fresh()->content_blocks_json ?? []);
    }

    public function test_reconstructed_block_key_resolves_on_the_current_version(): void
    {
        // This is the exact structural precondition EnterpriseWikiVerifyPageClaimsService::
        // isPositiveBestPracticeSuggestion() checks before ever rescuing a claim to best_practice
        // (content_block_key is non-empty AND resolves to a real block on the CURRENT version) —
        // proving it holds after repair demonstrates the fix, without re-implementing or touching
        // that private method/rule itself.
        $customer = $this->createCustomer();
        $page = $this->createPage($customer);

        $priorBlocks = [$this->sourceBasedBlock('block-0001', 'Bruk av prosessbilder gjør årsak-virkning mer synlig.')];
        $this->createVersion($page, 1, false, $priorBlocks);
        $current = $this->createVersion($page, 2, true, null, 'Bruk av prosessbilder gjør årsak-virkning mer synlig.');

        $claim = $this->createClaim($page, $current, 'Bruk av prosessbilder gjør årsak-virkning mer synlig.');

        $this->service()->repairPageVersion($page->id, $current);
        $claim->refresh();

        $this->assertNotSame('', trim((string) $claim->content_block_key));

        $resolvedBlock = collect($current->fresh()->content_blocks_json)
            ->firstWhere('block_key', $claim->content_block_key);

        $this->assertNotNull($resolvedBlock, 'content_block_key must resolve to a real block on the current version.');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function service(): EnterpriseWikiPageVersionBlockProvenanceRepairService
    {
        return app(EnterpriseWikiPageVersionBlockProvenanceRepairService::class);
    }

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

    private function createCustomer(string $name = 'Block Provenance Service AS'): Customer
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

    private function createPage(Customer $customer, string $title = 'ITIL'): EnterpriseWikiPage
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

    private function createClaim(
        EnterpriseWikiPage $page,
        EnterpriseWikiPageVersion $version,
        string $claimText,
        int $position = 0,
    ): EnterpriseWikiClaim {
        return EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'content_block_key' => '',
            'claim_text' => $claimText,
            'page_excerpt' => $claimText,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'position_order' => $position,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_HIGH,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
        ]);
    }
}
