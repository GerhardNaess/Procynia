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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Run-38 follow-up: repairing claims whose content_block_key is missing/stale and restoring the
 * source references their correctly-matched block declares — never guessing when more than one
 * block matches, never touching a claim outside the explicit --claim-ids scope, never calling AI.
 */
class EnterpriseWikiRepairRunClaimSourceLinksCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_the_unique_match_without_changing_data(): void
    {
        [$run, , , $claim] = $this->createRunWithBlocks();

        $this->artisan('wiki:repair-run-claim-source-links', [
            '--run-id' => $run->id,
            '--claim-ids' => (string) $claim->id,
        ])
            ->expectsOutputToContain('Read-only analysis')
            ->expectsOutputToContain('Relinked:                  1')
            ->assertExitCode(0);

        $fresh = $claim->fresh();
        $this->assertNull($fresh->content_block_key);
        $this->assertSame(0, EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $claim->id)->count());
    }

    public function test_apply_sets_the_block_key_and_restores_source_references(): void
    {
        [$run, , , $claim] = $this->createRunWithBlocks();

        $this->artisan('wiki:repair-run-claim-source-links', [
            '--run-id' => $run->id,
            '--claim-ids' => (string) $claim->id,
            '--apply' => true,
        ])
            ->expectsOutputToContain('Applied repair')
            ->expectsOutputToContain('Relinked:                  1')
            ->expectsOutputToContain('Source references created: 1')
            ->assertExitCode(0);

        $fresh = $claim->fresh();
        $this->assertSame('block-uniq', $fresh->content_block_key);

        $reference = EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $claim->id)->first();
        $this->assertNotNull($reference);
        $this->assertSame('paragraph-9', $reference->source_element_key);
        $this->assertSame('Critical incidents shall be responded to within 30 minutes.', $reference->excerpt);
    }

    public function test_repair_restores_candidates_but_never_marks_a_claim_supported_itself(): void
    {
        // Mirrors real run-38 claim 4048: relinking restores CANDIDATE source references so a
        // separate verification step can judge the claim — it must never, on its own, flip
        // content_origin. A genuinely misattributed claim stays rejected until (and unless) actual
        // verification says otherwise; this command has no opinion on that question at all.
        [$run, , , $claim] = $this->createRunWithBlocks();

        $this->artisan('wiki:repair-run-claim-source-links', [
            '--run-id' => $run->id,
            '--claim-ids' => (string) $claim->id,
            '--apply' => true,
        ])->assertExitCode(0);

        $fresh = $claim->fresh();
        $this->assertSame('block-uniq', $fresh->content_block_key);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, $fresh->content_origin);
    }

    public function test_ambiguous_match_is_reported_and_not_repaired(): void
    {
        [$run, , , , $ambiguousClaim] = $this->createRunWithBlocks();

        $this->artisan('wiki:repair-run-claim-source-links', [
            '--run-id' => $run->id,
            '--claim-ids' => (string) $ambiguousClaim->id,
            '--apply' => true,
        ])
            ->expectsOutputToContain('Ambiguous (not repaired):  1')
            ->assertExitCode(0);

        $fresh = $ambiguousClaim->fresh();
        $this->assertNull($fresh->content_block_key);
        $this->assertSame(0, EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $ambiguousClaim->id)->count());
    }

    public function test_no_matching_block_is_reported_and_not_repaired(): void
    {
        [$run, $page, $version] = $this->createRunWithBlocks();

        $unmatchedClaim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'Dette finnes ingen steder i blokkene.',
            'page_excerpt' => 'Dette finnes ingen steder i blokkene.',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'generation_issue' => 'unsupported_generated_content',
            'position_order' => 3,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
        ]);

        $this->artisan('wiki:repair-run-claim-source-links', [
            '--run-id' => $run->id,
            '--claim-ids' => (string) $unmatchedClaim->id,
            '--apply' => true,
        ])
            ->expectsOutputToContain('No match (not repaired):   1')
            ->assertExitCode(0);

        $this->assertNull($unmatchedClaim->fresh()->content_block_key);
    }

    public function test_repair_never_touches_a_claim_outside_the_explicit_scope(): void
    {
        [$run, , , $claim] = $this->createRunWithBlocks();

        $protectedClaim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $claim->enterprise_wiki_page_id,
            'enterprise_wiki_page_version_id' => $claim->enterprise_wiki_page_version_id,
            'claim_text' => 'Critical incidents shall be responded to within 30 minutes.',
            'page_excerpt' => 'Critical incidents shall be responded to within 30 minutes.',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'generation_issue' => 'genuine_misattribution',
            'position_order' => 4,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
        ]);

        // Only $claim is in scope — $protectedClaim has an identical anchor and would match the
        // same unique block, but must never be touched because its ID was never requested.
        $this->artisan('wiki:repair-run-claim-source-links', [
            '--run-id' => $run->id,
            '--claim-ids' => (string) $claim->id,
            '--apply' => true,
        ])->assertExitCode(0);

        $fresh = $protectedClaim->fresh();
        $this->assertNull($fresh->content_block_key);
        $this->assertSame(EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT, $fresh->content_origin);
        $this->assertSame(0, EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $protectedClaim->id)->count());
    }

    public function test_repair_can_run_twice_without_creating_duplicate_references(): void
    {
        [$run, , , $claim] = $this->createRunWithBlocks();

        $this->artisan('wiki:repair-run-claim-source-links', [
            '--run-id' => $run->id,
            '--claim-ids' => (string) $claim->id,
            '--apply' => true,
        ])->assertExitCode(0);

        $this->artisan('wiki:repair-run-claim-source-links', [
            '--run-id' => $run->id,
            '--claim-ids' => (string) $claim->id,
            '--apply' => true,
        ])
            ->expectsOutputToContain('Unchanged (already ok):    1')
            ->expectsOutputToContain('Relinked:                  0')
            ->assertExitCode(0);

        $this->assertSame(
            1,
            EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $claim->id)->count(),
        );
    }

    public function test_supplement_adds_a_missing_source_element_idempotently(): void
    {
        [$run, $page, $version, $claim] = $this->createRunWithBlocks();

        // A source reference for paragraph-99 already exists, tied to some unrelated claim from
        // the original extraction — addMissingSourceElement() looks up its canonical excerpt/
        // metadata from there rather than inventing new text, exactly like the real
        // paragraph-43/claim-3925 case. $claim itself starts with zero references.
        $otherClaim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => 'En annen påstand.',
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
            'position_order' => 9,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
        ]);
        EnterpriseWikiSourceReference::query()->create([
            'enterprise_wiki_claim_id' => $otherClaim->id,
            'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
            'source_id' => $run->source_id,
            'source_element_key' => 'paragraph-99',
            'source_element_type' => 'paragraph',
            'source_label' => 'source.pdf',
            'excerpt' => 'Mer omfattende utviklingsarbeid gjennomføres etter nærmere avtale.',
            'source_hash' => 'abc123',
            'page_reference' => 'Avsnitt 99',
        ]);

        $this->artisan('wiki:repair-run-claim-source-links', [
            '--run-id' => $run->id,
            '--claim-ids' => (string) $claim->id,
            '--supplement' => [$version->id.':block-uniq:paragraph-99'],
            '--apply' => true,
        ])
            ->expectsOutputToContain('Would add [paragraph-99] to block [block-uniq]')
            ->assertExitCode(0);

        $blocks = $version->fresh()->content_blocks_json;
        $keys = array_column($blocks[0]['source_elements'], 'source_element_key');
        $this->assertContains('paragraph-99', $keys);

        $reference = EnterpriseWikiSourceReference::query()
            ->where('enterprise_wiki_claim_id', $claim->id)
            ->where('source_element_key', 'paragraph-99')
            ->first();
        $this->assertNotNull($reference);

        // Re-run: the supplement is already present, and the claim already has both references —
        // nothing should be added a second time.
        $this->artisan('wiki:repair-run-claim-source-links', [
            '--run-id' => $run->id,
            '--claim-ids' => (string) $claim->id,
            '--supplement' => [$version->id.':block-uniq:paragraph-99'],
            '--apply' => true,
        ])
            ->expectsOutputToContain('already lists [paragraph-99]')
            ->assertExitCode(0);

        $this->assertSame(
            2,
            EnterpriseWikiSourceReference::query()->where('enterprise_wiki_claim_id', $claim->id)->count(),
        );
    }

    public function test_command_fails_without_run_id(): void
    {
        $this->artisan('wiki:repair-run-claim-source-links', ['--claim-ids' => '1'])
            ->expectsOutputToContain('--run-id is required')
            ->assertExitCode(1);
    }

    public function test_command_fails_without_claim_ids(): void
    {
        $customer = $this->createCustomer();
        [$run] = $this->createRunWithBlocks($customer);

        $this->artisan('wiki:repair-run-claim-source-links', ['--run-id' => $run->id])
            ->expectsOutputToContain('--claim-ids is required')
            ->assertExitCode(1);
    }

    /**
     * @return array{0: EnterpriseWikiIngestRun, 1: EnterpriseWikiPage, 2: EnterpriseWikiPageVersion, 3: EnterpriseWikiClaim, 4: EnterpriseWikiClaim}
     */
    private function createRunWithBlocks(?Customer $customer = null): array
    {
        $customer ??= $this->createCustomer();

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
            'slug' => 'repair-link-page-'.Str::lower(Str::random(8)),
            'title' => 'Repair Link Page',
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

        $uniqueMarkdown = 'Critical incidents shall be responded to within 30 minutes.';
        $duplicateAnchorText = 'Kunden eier prosessene videre etter innføring.';

        $version = EnterpriseWikiPageVersion::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'version_number' => 1,
            'is_current' => true,
            'content_markdown' => "# Repair Link Page\n\n{$uniqueMarkdown}\n\n{$duplicateAnchorText}",
            'content_blocks_json' => [
                [
                    'block_key' => 'block-uniq',
                    'position' => 0,
                    'markdown' => $uniqueMarkdown,
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                    'source_elements' => [[
                        'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                        'source_id' => $document->id,
                        'source_element_key' => 'paragraph-9',
                        'source_element_type' => 'paragraph',
                        'source_excerpt' => $uniqueMarkdown,
                        'source_label' => 'source.pdf',
                        'source_hash' => $document->file_hash_sha256,
                        'page_reference' => 'Avsnitt 9',
                    ]],
                ],
                [
                    'block_key' => 'block-dup-a',
                    'position' => 1,
                    'markdown' => $duplicateAnchorText.' Videre detaljer for blokk A.',
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                    'source_elements' => [[
                        'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                        'source_id' => $document->id,
                        'source_element_key' => 'paragraph-10',
                        'source_element_type' => 'paragraph',
                        'source_excerpt' => $duplicateAnchorText,
                        'source_label' => 'source.pdf',
                        'source_hash' => $document->file_hash_sha256,
                        'page_reference' => 'Avsnitt 10',
                    ]],
                ],
                [
                    'block_key' => 'block-dup-b',
                    'position' => 2,
                    'markdown' => $duplicateAnchorText.' Videre detaljer for blokk B.',
                    'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_SOURCE_BASED,
                    'source_elements' => [[
                        'source_type' => EnterpriseWikiSourceReference::SOURCE_TYPE_ENTERPRISE_WIKI_DOCUMENT,
                        'source_id' => $document->id,
                        'source_element_key' => 'paragraph-11',
                        'source_element_type' => 'paragraph',
                        'source_excerpt' => $duplicateAnchorText,
                        'source_label' => 'source.pdf',
                        'source_hash' => $document->file_hash_sha256,
                        'page_reference' => 'Avsnitt 11',
                    ]],
                ],
            ],
        ]);

        $claim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => $uniqueMarkdown,
            'page_excerpt' => $uniqueMarkdown,
            'content_block_key' => null,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'generation_issue' => 'unsupported_generated_content',
            'position_order' => 0,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
        ]);

        $ambiguousClaim = EnterpriseWikiClaim::query()->create([
            'enterprise_wiki_page_id' => $page->id,
            'enterprise_wiki_page_version_id' => $version->id,
            'claim_text' => $duplicateAnchorText,
            'page_excerpt' => $duplicateAnchorText,
            'content_block_key' => null,
            'content_origin' => EnterpriseWikiClaim::CONTENT_ORIGIN_UNSUPPORTED_GENERATED_CONTENT,
            'generation_issue' => 'unsupported_generated_content',
            'position_order' => 1,
            'confidence' => EnterpriseWikiClaim::CONFIDENCE_UNCERTAIN,
            'conflict_flag' => false,
            'approval_status' => EnterpriseWikiClaim::APPROVAL_STATUS_PENDING,
            'verified_at' => now(),
        ]);

        return [$run, $page, $version, $claim, $ambiguousClaim];
    }

    private function createCustomer(string $name = 'Repair Link Test AS'): Customer
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
            'is_active' => true,
        ]);
    }
}
